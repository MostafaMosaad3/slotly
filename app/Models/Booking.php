<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price_cents' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeOverlapping(Builder $query, int $staffId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        $earliestStart = $start->copy()->subMinutes(config('scheduling.max_booking_minutes'));

        return $query
            ->where('staff_id', $staffId)
            ->where('starts_at', '>', $earliestStart)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->where('status', '!=', 'cancelled');
    }
}
