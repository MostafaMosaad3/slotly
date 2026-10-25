<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CompletePastBookings extends Command
{
    protected $signature = 'bookings:complete-past {--chunk=500}';

    protected $description = 'Mark confirmed bookings that already ended as completed';

    public function handle(): int
    {
        $cutoff = now();
        $completed = 0;

        Booking::query()
            ->where('status', 'confirmed')
            ->where('ends_at', '<', $cutoff)
            ->select('id')
            ->chunkById((int) $this->option('chunk'), function (Collection $chunk) use ($cutoff, &$completed) {
                // set-based on purpose: no model events exist yet (revisit in Week 3)
                $completed += DB::transaction(fn () => Booking::query()
                    ->whereKey($chunk->modelKeys())
                    ->where('status', 'confirmed')
                    ->where('ends_at', '<', $cutoff)
                    ->update(['status' => 'completed', 'updated_at' => now()]));
            });

        $this->info("Completed {$completed} bookings.");

        return self::SUCCESS;
    }
}
