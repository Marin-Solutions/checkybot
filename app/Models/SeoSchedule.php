<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'created_by',
        'frequency',
        'schedule_time',
        'schedule_day',
        'last_run_at',
        'next_run_at',
        'is_active',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get schedules that are due to run
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('next_run_at', '<=', now());
    }

    /**
     * Update the next run time based on frequency
     */
    public function updateNextRun(): void
    {
        $this->update([
            'last_run_at' => now(),
            'next_run_at' => $this->calculateNextRun(),
        ]);
    }

    /**
     * Advance the schedule without recording a completed run.
     */
    public function advanceNextRun(): void
    {
        $this->update([
            'next_run_at' => $this->calculateNextRun(),
        ]);
    }

    /**
     * Calculate the next run time based on frequency, time, and day
     */
    protected function calculateNextRun(): Carbon
    {
        return self::calculateNextRunAt(
            $this->frequency,
            $this->schedule_time,
            $this->schedule_day,
        );
    }

    /**
     * Calculate the next run time based on frequency, time, and day.
     */
    public static function calculateNextRunAt(string $frequency, ?string $scheduleTime = null, ?string $scheduleDay = null): Carbon
    {
        [$hours, $minutes] = self::parseScheduleTime($scheduleTime);

        return match ($frequency) {
            'daily' => now()->addDay()->setTime($hours, $minutes),
            'weekly' => self::calculateNextWeeklyRunAt($scheduleDay, $hours, $minutes),
            'monthly' => now()->addMonth()->setTime($hours, $minutes),
            default => now()->addDay()->setTime($hours, $minutes),
        };
    }

    /**
     * Calculate next weekly run based on selected day.
     */
    protected static function calculateNextWeeklyRunAt(?string $scheduleDay, int $hours, int $minutes): Carbon
    {
        $now = now();
        $day = $scheduleDay ?? 'Monday';
        $candidate = $now->copy()->setTime($hours, $minutes);

        if (strcasecmp($candidate->englishDayOfWeek, $day) !== 0) {
            return $now->copy()->next($day)->setTime($hours, $minutes);
        }

        if ($candidate->isPast()) {
            return $now->copy()->next($day)->setTime($hours, $minutes);
        }

        return $candidate;
    }

    /**
     * Parse schedule time values from form input or stored schedule records.
     *
     * @return array{0: int, 1: int}
     */
    protected static function parseScheduleTime(?string $scheduleTime): array
    {
        $time = $scheduleTime ?? '02:00:00';
        [$hours, $minutes] = array_pad(explode(':', $time), 2, 0);

        return [(int) $hours, (int) $minutes];
    }
}
