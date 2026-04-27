<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'package_key',
        'package_version',
        'group',
        'environment',
        'technology',
        'identity_endpoint',
        'base_url',
        'repository',
        'sync_defaults',
        'last_synced_at',
        'token',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sync_defaults' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function monitorApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class);
    }

    public function packageManagedWebsites(): HasMany
    {
        return $this->hasMany(Website::class)->where('source', 'package');
    }

    public function packageManagedApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class)->where('source', 'package');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProjectComponent::class);
    }

    public function activeComponents(): HasMany
    {
        return $this->hasMany(ProjectComponent::class)->where('is_archived', false);
    }

    protected function applicationStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->resolveApplicationStatus(
                $this->relationLoaded('activeComponents')
                    ? $this->activeComponents
                    : $this->activeComponents()->get(['current_status'])
            ),
        );
    }

    /**
     * @param  Collection<int, ProjectComponent>  $components
     */
    protected function resolveApplicationStatus(Collection $components): string
    {
        if ($components->isEmpty()) {
            return 'unknown';
        }

        $worstStatus = 'healthy';

        foreach ($components as $component) {
            if ($this->statusPriority($component->current_status) > $this->statusPriority($worstStatus)) {
                $worstStatus = $component->current_status;
            }
        }

        return $worstStatus;
    }

    protected function statusPriority(?string $status): int
    {
        return match ($status) {
            'danger' => 3,
            'warning' => 2,
            'healthy' => 1,
            default => 0,
        };
    }

    public function guidedSetupSnippet(?string $apiKey = null): string
    {
        $checkybotUrl = rtrim((string) config('app.url', 'https://checkybot.com'), '/');

        if ($checkybotUrl === '') {
            $checkybotUrl = 'https://checkybot.com';
        }

        $apiKey ??= 'replace-with-your-api-key';

        return implode(PHP_EOL, [
            'composer require marin-solutions/checkybot-laravel',
            'php artisan vendor:publish --tag="checkybot-routes"',
            'php artisan vendor:publish --tag="checkybot-laravel-config"',
            '',
            "cat <<'EOF' >> .env",
            "CHECKYBOT_API_KEY={$apiKey}",
            "CHECKYBOT_URL={$checkybotUrl}",
            "CHECKYBOT_APP_ID={$this->getKey()}",
            'CHECKYBOT_APPLICATION_NAME="'.$this->escapeEnvDoubleQuotedValue($this->name).'"',
            "CHECKYBOT_ENVIRONMENT={$this->environment}",
            'CHECKYBOT_IDENTITY_ENDPOINT="${APP_URL}"',
            'EOF',
            '',
            '# routes/console.php',
            "Schedule::command('checkybot:sync')->everyMinute();",
            '',
            'php artisan checkybot:sync --dry-run',
            'php artisan checkybot:sync',
        ]);
    }

    private function escapeEnvDoubleQuotedValue(string $value): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        );
    }
}
