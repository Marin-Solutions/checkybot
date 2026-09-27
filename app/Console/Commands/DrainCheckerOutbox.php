<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\Website;
use App\Services\CheckerOwnership;
use App\Services\HealthEventNotificationService;
use App\Services\SslExpiryReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Throwable;

class DrainCheckerOutbox extends Command
{
    protected $signature = 'checker:drain-outbox';

    protected $description = 'Deliver check notifications written by the Go checker';

    public function handle(
        HealthEventNotificationService $notifications,
        SslExpiryReminderService $reminders,
        CheckerOwnership $ownership,
    ): int {
        $rows = DB::table('checker_outbox')
            ->whereNull('processed_at')
            ->where('attempts', '<', 5)
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            try {
                $ok = $this->deliver($row, $notifications, $reminders);
                if ($ok) {
                    DB::table('checker_outbox')->where('id', $row->id)->update([
                        'processed_at' => now(),
                        'last_error' => null,
                    ]);
                } else {
                    $this->recordFailure($row->id, 'delivery returned false');
                }
            } catch (Throwable $exception) {
                Log::error('Checker outbox delivery failed', [
                    'outbox_id' => $row->id,
                    'kind' => $row->kind,
                    'exception' => $exception::class,
                ]);
                $this->recordFailure($row->id, $exception::class);
            }
        }

        $this->reconcileHorizon($ownership);

        return self::SUCCESS;
    }

    private function deliver(object $row, HealthEventNotificationService $notifications, SslExpiryReminderService $reminders): bool
    {
        return match ($row->kind) {
            'website_transition' => $this->deliverWebsite($row, $notifications),
            'api_transition' => $this->deliverApi($row, $notifications),
            'ssl_reminder' => $this->deliverReminder($row, $reminders),
            default => true,
        };
    }

    private function deliverWebsite(object $row, HealthEventNotificationService $notifications): bool
    {
        $website = Website::query()->find($row->subject_id);
        if (! $website) {
            return true;
        }

        return $notifications->notifyWebsite(
            $website,
            (string) $row->event,
            (string) $row->status,
            (string) ($row->summary ?? ''),
        );
    }

    private function deliverApi(object $row, HealthEventNotificationService $notifications): bool
    {
        $monitor = MonitorApis::query()->find($row->subject_id);
        if (! $monitor) {
            return true;
        }

        return $notifications->notifyApi(
            $monitor,
            (string) $row->event,
            (string) $row->status,
            (string) ($row->summary ?? ''),
        );
    }

    private function deliverReminder(object $row, SslExpiryReminderService $reminders): bool
    {
        $website = Website::query()->find($row->subject_id);
        if (! $website) {
            return true;
        }

        $reminders->deliverIfDue($website);

        return true;
    }

    private function recordFailure(int $id, string $error): void
    {
        DB::table('checker_outbox')->where('id', $id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => mb_substr($error, 0, 500),
        ]);
    }

    private function reconcileHorizon(CheckerOwnership $ownership): void
    {
        if (! app()->environment(['production', 'staging']) || ! config('horizon.enabled', true)) {
            return;
        }

        $this->signalSupervisor('supervisor-2', $ownership->goOwns('uptime'));
        $this->signalSupervisor('supervisor-3', $ownership->goOwns('api'));
        $this->signalSupervisor('supervisor-4', $ownership->goOwns('ssl'));
    }

    private function signalSupervisor(string $name, bool $shouldPause): void
    {
        try {
            $match = collect(app(SupervisorRepository::class)->all())->first(
                fn ($supervisor) => Str::endsWith((string) $supervisor->name, $name)
            );
            if ($match === null) {
                return;
            }

            $paused = ($match->status ?? '') === 'paused';
            if ($paused === $shouldPause) {
                return;
            }

            Artisan::call($shouldPause ? 'horizon:pause-supervisor' : 'horizon:continue-supervisor', [
                'name' => $name,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Checker could not update a Horizon supervisor', [
                'supervisor' => $name,
                'pause' => $shouldPause,
                'exception' => $exception::class,
            ]);
        }
    }
}
