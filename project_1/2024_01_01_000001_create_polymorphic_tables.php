<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for polymorphic relationship tables.
 *
 * This migration demonstrates Laravel's polymorphic relationships:
 * - amenityables: Many-to-many between amenities and any entity
 * - photos: One-to-many between photos and any entity
 *
 * These tables allow amenities and photos to be shared across
 * different entity types (facilities, rooms, etc.) without
 * creating separate junction tables for each relationship.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Amenity types lookup table
        Schema::create('amenities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('shortname')->unique()->comment('URL-friendly identifier');
            $table->enum('type', ['facility', 'room', 'both'])->default('both');
            $table->timestamps();
        });

        // Polymorphic many-to-many: amenities <-> entities
        Schema::create('amenityables', function (Blueprint $table): void {
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();

            // Polymorphic columns for the related entity
            $table->unsignedBigInteger('amenityable_id');
            $table->string('amenityable_type');

            // Additional pivot data
            $table->boolean('is_featured')->default(false);

            $table->primary([
                'amenity_id',
                'amenityable_id',
                'amenityable_type',
            ], 'amenityable_primary');

            $table->index(['amenityable_type', 'amenityable_id']);
        });

        // Polymorphic one-to-many: photos <-> entities
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();

            // File information
            $table->string('filename');
            $table->string('extension', 10);
            $table->string('file_path');
            $table->string('directory');
            $table->string('disk')->default('public');

            // Image optimization data
            $table->json('sizes')->nullable()->comment('Generated image size variants');
            $table->boolean('optimized')->default(false);

            // Display order
            $table->unsignedInteger('position')->default(0);

            // Polymorphic relationship
            $table->unsignedBigInteger('photoable_id');
            $table->string('photoable_type');

            $table->timestamps();

            $table->index(['photoable_type', 'photoable_id']);
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
        Schema::dropIfExists('amenityables');
        Schema::dropIfExists('amenities');
    }
};
