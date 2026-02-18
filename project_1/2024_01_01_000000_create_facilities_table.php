<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating the facilities table.
 *
 * This migration demonstrates:
 * - Geographic data storage (coordinates, address)
 * - Soft ordering via position column
 * - External API integration field (roomadmin_id)
 * - Type differentiation (location vs guesthouse)
 * - Visibility and promotion flags
 * - Accommodation-specific attributes
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table): void {
            $table->id();

            // External API integration
            $table->string('roomadmin_id')->unique()->comment('External ID from RoomAdmin API');

            // Basic information
            $table->string('name');
            $table->text('description')->nullable();

            // Type differentiation
            $table->enum('type', ['location', 'guesthouse'])
                ->default('location')
                ->comment('Location has rooms, guesthouse is bookable as whole');

            // Address and location
            $table->string('address');
            $table->string('city');
            $table->string('zip_code');
            $table->string('country')->default('Poland');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();

            // Accommodation attributes
            $table->unsignedInteger('apartment_size')->nullable()->comment('Size in square meters');
            $table->unsignedTinyInteger('max_guests')->nullable();
            $table->unsignedTinyInteger('bedroom_count')->nullable();
            $table->unsignedBigInteger('price_from')->nullable()->comment('Starting price in cents');

            // Display and ordering
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_promoted')->default(false);

            $table->timestamps();

            // Indexes for common queries
            $table->index(['city', 'is_visible']);
            $table->index(['type', 'is_visible']);
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
