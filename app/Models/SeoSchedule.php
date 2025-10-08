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
        'frequency',
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
     * Calculate the next run time based on frequency
     */
    protected function calculateNextRun(): Carbon
    {
        return match ($this->frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        };
    }
}
