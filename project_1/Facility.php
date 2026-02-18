<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Http;

/**
 * Facility model representing accommodation locations and guesthouses.
 *
 * This model demonstrates:
 * - Polymorphic relationships (photos, amenities, reviews)
 * - External API integration with RoomAdmin service
 * - Custom attribute accessors for data transformation
 * - Type-based behavior differentiation (location vs guesthouse)
 *
 * @property int $id
 * @property string $roomadmin_id External identifier for RoomAdmin API
 * @property string $name
 * @property string $type 'location' or 'guesthouse'
 * @property string|null $description
 * @property string $address
 * @property string $city
 * @property string $zip_code
 * @property string $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $position Display order position
 * @property bool $is_visible Visibility flag
 * @property bool $is_promoted Promoted status
 * @property int|null $apartment_size Size in square meters
 * @property int|null $max_guests Maximum guest capacity
 * @property int|null $bedroom_count Number of bedrooms
 * @property int|null $price_from Starting price in cents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Photo> $photos
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Amenity> $amenities
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Review> $reviews
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Room> $rooms
 */
final class Facility extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'is_promoted',
        'roomadmin_id',
        'name',
        'type',
        'description',
        'address',
        'city',
        'zip_code',
        'country',
        'latitude',
        'longitude',
        'position',
        'is_visible',
        'apartment_size',
        'max_guests',
        'bedroom_count',
        'price_from',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['pivot'];

    /**
     * Get the amenities associated with this facility.
     *
     * Polymorphic many-to-many relationship allowing amenities
     * to be shared across different entity types.
     */
    public function amenities(): MorphToMany
    {
        return $this->morphToMany(Amenity::class, 'amenityable')
            ->withPivot('is_featured');
    }

    /**
     * Get the featured amenity for this facility.
     *
     * Used for displaying a primary amenity badge/icon.
     */
    public function featuredAmenity(): ?Amenity
    {
        return $this->amenities()
            ->wherePivot('is_featured', true)
            ->first();
    }

    /**
     * Get all photos for this facility.
     *
     * Polymorphic one-to-many relationship with ordering by position.
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Photo::class, 'photoable')
            ->orderBy('position');
    }

    /**
     * Get accepted reviews for this facility.
     *
     * Only returns reviews with 'ACCEPTED' status, ordered by newest first.
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable')
            ->where('status', 'ACCEPTED')
            ->orderBy('created_at', 'DESC');
    }

    /**
     * Get all reviews regardless of status.
     *
     * Used in admin panel for review management.
     */
    public function allReviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * Get reviews from rooms belonging to this facility.
     *
     * Demonstrates complex whereHasMorph query for polymorphic relations.
     */
    public function roomReviews()
    {
        return Review::whereHasMorph(
            'reviewable',
            [Room::class],
            function ($query): void {
                $query->whereHas('facility', function ($q): void {
                    $q->where('id', $this->id);
                });
            }
        );
    }

    /**
     * Get rooms stored locally in the database.
     *
     * For 'location' type facilities, rooms are managed locally.
     * For 'guesthouse' type, the facility itself acts as the bookable unit.
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'facility_id');
    }

    /**
     * Get rooms from the external RoomAdmin API.
     *
     * This accessor demonstrates:
     * - Conditional logic based on facility type
     * - External API integration with HTTP client
     * - Data enrichment by merging API data with local database records
     *
     * @return array<int, array<string, mixed>>
     */
    protected function roomsFromApi(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): array {
                // Guesthouses don't have individual rooms
                if (($attributes['type'] ?? null) === 'guesthouse') {
                    return [];
                }

                $response = Http::withToken(config('roomadmin.token'))
                    ->get('https://se.roomadmin.pl/ws/facility-availability/rooms', [
                        'hash_in' => [$attributes['roomadmin_id']],
                    ]);

                $roomadminFacilities = $response->json();

                if (isset($roomadminFacilities['exception'])) {
                    return [];
                }

                // Enrich API data with local database records
                foreach ($roomadminFacilities as &$roomadminFacility) {
                    foreach ($roomadminFacility['rooms'] as &$room) {
                        $localRoom = Room::where('roomadmin_id', $room['id'])
                            ->with(['photos', 'amenities'])
                            ->first();

                        if ($localRoom) {
                            $room['local'] = $localRoom;
                            $room['is_visible'] = $localRoom->is_visible;
                        } else {
                            $room['is_visible'] = true;
                        }
                    }
                }

                return $roomadminFacilities[0]['rooms'] ?? [];
            }
        );
    }

    /**
     * Get the starting price formatted in dollars.
     *
     * Prices are stored in cents for precision, converted to dollars for display.
     */
    protected function priceFrom(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?float => $value ? $value / 100 : null
        );
    }
}
