<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->unsignedInteger('upcoming_bookings_count')->default(0); // confirmed, not started yet; recounted nightly
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['notes', 'version', 'cancelled_at']);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('upcoming_bookings_count');
        });
    }
};
