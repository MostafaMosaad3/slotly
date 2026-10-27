<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Staff $staff) {
            if (! Service::where('tenant_id', $staff->tenant_id)->exists()) {
                Service::factory()->create(['tenant_id' => $staff->tenant_id]);
            }
            if (! Customer::where('tenant_id', $staff->tenant_id)->exists()) {
                Customer::factory()->create(['tenant_id' => $staff->tenant_id]);
            }
        });
    }
}
