<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Photo model with polymorphic relationships.
 *
 * This model demonstrates:
 * - Polymorphic relationship (can belong to Facility, Room, etc.)
 * - Custom attribute accessor for asset URL generation
 * - Proper type casting and attribute hiding
 *
 * @property int $id
 * @property string $filename Original filename without extension
 * @property string $extension File extension (jpg, png, etc.)
 * @property string $file_path Full path to the file on disk
 * @property string $directory Storage directory
 * @property string $disk Storage disk name
 * @property int $position Display order position
 * @property array|null $sizes Generated image sizes
 * @property bool $optimized Whether image has been optimized
 * @property int $photoable_id ID of the related entity
 * @property string $photoable_type Class name of the related entity
 * @property-read string $asset_path Full URL to the image
 * @property-read Model $photoable The parent entity (Facility, Room, etc.)
 */
final class Photo extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'filename',
        'extension',
        'file_path',
        'directory',
        'disk',
        'position',
        'photoable_id',
        'photoable_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sizes' => 'array',
        'optimized' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['pivot'];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['asset_path'];

    /**
     * Get the full URL to the photo.
     *
     * Generates the public URL by converting the storage path
     * to an asset URL, removing 'public/' prefix if present.
     */
    protected function assetPath(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => asset(
                'storage/' . str_replace('public/', '', $attributes['file_path'])
            )
        );
    }

    /**
     * Get the parent entity that this photo belongs to.
     *
     * Polymorphic relationship allowing photos to be attached
     * to any model that needs image management.
     */
    public function photoable(): MorphTo
    {
        return $this->morphTo();
    }
}
