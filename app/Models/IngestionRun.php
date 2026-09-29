<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IngestionRun extends Model
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::RUNNING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    public function isStalled(): bool
    {
        $last = $this->last_progress_at ?? $this->started_at ?? $this->created_at;

        return $this->isActive()
            && $last->lt(now()->subMinutes(config('foodfacts.stall_after_minutes')));
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::FAILED,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
        ])->save();
    }

    /** Cutoff for an incremental run: newest change we already have. */
    public static function lastCompletedWatermark(): ?int
    {
        return static::query()
            ->where('status', self::COMPLETED)
            ->whereNotNull('watermark')
            ->latest('id')
            ->value('watermark');
    }
}
