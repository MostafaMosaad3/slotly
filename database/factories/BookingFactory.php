<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class BookingFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::parse('2026-11-02 08:00:00')->addDays(fake()->numberBetween(0, 30))->addHours(fake()->numberBetween(0, 10));

        return [
            'staff_id' => Staff::factory(),
            'tenant_id' => fn (array $attributes) => Staff::findOrFail($attributes['staff_id'])->tenant_id,
            'service_id' => fn (array $attributes) => Service::where('tenant_id', $attributes['tenant_id'])->value('id')
                ?? Service::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'customer_id' => fn (array $attributes) => Customer::where('tenant_id', $attributes['tenant_id'])->value('id')
                ?? Customer::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'status' => 'confirmed',
            'price_cents' => 25_000,
        ];
    }
}
