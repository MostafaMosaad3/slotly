<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['staff_id', 'starts_at'], 'bookings_staff_starts_idx');
            $table->index(['tenant_id', 'created_at'], 'bookings_tenant_created_idx');
        });

        // MySQL silently drops an auto-created FK index once another index can serve the FK
        foreach (['bookings_staff_id_foreign', 'bookings_tenant_id_foreign'] as $index) {
            if (Schema::hasIndex('bookings', $index)) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('staff_id', 'bookings_staff_id_foreign');
            $table->index('tenant_id', 'bookings_tenant_id_foreign');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_staff_starts_idx');
            $table->dropIndex('bookings_tenant_created_idx');
        });
    }
};
