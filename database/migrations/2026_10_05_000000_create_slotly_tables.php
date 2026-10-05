<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone');
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('price_cents');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('staff_id')->constrained('staff');
            $table->foreignId('customer_id')->constrained();
            // Application values are UTC; DATETIME performs no timezone conversion.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('services');
        Schema::dropIfExists('tenants');
    }
};
