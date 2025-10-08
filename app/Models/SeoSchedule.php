<?php

namespace App\Models;

use Carbon\Carbon;
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
    public function scopeDue($query)
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
     * Calculate the next run time based on frequency, time, and day
     */
    protected function calculateNextRun(): Carbon
    {
        $time = $this->schedule_time ?? '02:00:00';
        [$hours, $minutes] = explode(':', $time);

        return match ($this->frequency) {
            'daily' => now()->addDay()->setTime((int) $hours, (int) $minutes),
            'weekly' => $this->calculateNextWeeklyRun($hours, $minutes),
            'monthly' => now()->addMonth()->setTime((int) $hours, (int) $minutes),
            default => now()->addDay()->setTime((int) $hours, (int) $minutes),
        };
    }

    /**
     * Calculate next weekly run based on selected day
     */
    protected function calculateNextWeeklyRun(int $hours, int $minutes): Carbon
    {
        $day = $this->schedule_day ?? 'Monday';
        $nextRun = now()->next($day)->setTime($hours, $minutes);

        // If the next occurrence is today but the time has passed, get next week's occurrence
        if ($nextRun->isPast()) {
            $nextRun = now()->addWeek()->next($day)->setTime($hours, $minutes);
        }

        return $nextRun;
    }
}
