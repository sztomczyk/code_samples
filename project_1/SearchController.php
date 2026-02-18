<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SearchType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchRequest;
use App\Models\Facility;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * API Controller for searching facilities and rooms.
 *
 * This controller demonstrates a complex search algorithm that:
 * - Handles multiple entity types (facilities, rooms, guesthouses)
 * - Integrates with external RoomAdmin API for real-time availability
 * - Uses Spatie QueryBuilder for dynamic filtering
 * - Calculates minimum prices from multiple variants
 * - Supports location-based and amenity-based filtering
 */
final class SearchController extends Controller
{
    /**
     * Get all available location cities.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function locations(): JsonResponse
    {
        $cities = Facility::query()
            ->pluck('city')
            ->unique()
            ->values()
            ->all();

        return response()->json($cities);
    }

    /**
     * Search for facilities or rooms based on criteria.
     *
     * Handles three search modes:
     * 1. Facilities without dates - returns all visible facilities
     * 2. Facilities with dates - checks availability via API
     * 3. Rooms - returns individual rooms with availability info
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $locations = $validated['location'] ?? [];

        // Build base facility query with location filter
        [$facilities, $hashIn] = $this->buildBaseFacilityQuery($locations);

        // Handle search without date constraints
        if (! $this->hasDateConstraints($validated)) {
            return $this->searchWithoutDates($validated, $facilities, $hashIn, $locations);
        }

        // Handle search with availability checking
        return match ($validated['type']) {
            SearchType::Facilities->value => response()->json(
                $this->searchForFacilities($validated, $hashIn)
            ),
            SearchType::Rooms->value => response()->json(
                $this->searchForRooms($validated, $hashIn, isset($request->filter))
            ),
            default => response()->json([]),
        };
    }

    /**
     * Build base facility query with optional location filtering.
     *
     * @param array<int, string> $locations
     * @return array{0: \Illuminate\Database\Eloquent\Collection, 1: array<int, string>}
     */
    private function buildBaseFacilityQuery(array $locations): array
    {
        $locationCallback = function ($query) use ($locations): void {
            foreach ($locations as $location) {
                $query->orWhere('city', $location);
            }
        };

        $facilities = QueryBuilder::for(Facility::class)
            ->allowedFilters(AllowedFilter::exact('amenities.shortname'))
            ->when(! empty($locations), $locationCallback)
            ->where('is_visible', true)
            ->with(['photos', 'amenities', 'reviews'])
            ->orderBy('position', 'asc')
            ->get();

        $hashIn = Facility::query()
            ->when(! empty($locations), $locationCallback)
            ->where('type', 'location')
            ->pluck('roomadmin_id')
            ->toArray();

        return [$facilities, $hashIn];
    }

    /**
     * Check if search has date constraints for availability checking.
     */
    private function hasDateConstraints(array $validated): bool
    {
        return isset($validated['arrival'])
            && isset($validated['departure'])
            && isset($validated['numberOfGuests']);
    }

    /**
     * Handle search without date constraints.
     *
     * @param array<int, string> $locations
     */
    private function searchWithoutDates(
        array $validated,
        $facilities,
        array $hashIn,
        array $locations
    ): JsonResponse {
        if ($validated['type'] === SearchType::Facilities->value) {
            return response()->json($facilities);
        }

        $rooms = $this->buildRoomsWithoutDates($hashIn, $locations);

        return response()->json($rooms);
    }

    /**
     * Build rooms collection without date availability checking.
     *
     * @param array<int, string> $hashIn
     * @param array<int, string> $locations
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomsWithoutDates(array $hashIn, array $locations): array
    {
        $facilitiesWithRooms = $this->getRooms($hashIn, $locations);
        $rooms = [];

        $localRooms = QueryBuilder::for(Room::class)
            ->allowedFilters(AllowedFilter::exact('amenities.shortname'))
            ->with(['photos', 'amenities'])
            ->orderBy('position', 'asc')
            ->get();

        // Merge API rooms with local data
        foreach ($facilitiesWithRooms as $facility) {
            foreach ($facility['rooms'] as $roomadminRoom) {
                $this->enrichRoomWithLocalData($roomadminRoom, $localRooms, $facility);
                $rooms[] = $roomadminRoom;
            }
        }

        // Add guesthouses as room-like entities
        $rooms = $this->addGuesthousesToResults($rooms, $locations);

        return $this->sortResultsByPosition($rooms);
    }

    /**
     * Enrich room data with local database information.
     */
    private function enrichRoomWithLocalData(
        array &$roomadminRoom,
        $localRooms,
        array $facility
    ): void {
        foreach ($localRooms as $localRoom) {
            if ($localRoom->roomadmin_id == $roomadminRoom['id']) {
                $roomadminRoom['local'] = $localRoom;
                $roomadminRoom['facility'] = $facility['local'] ?? null;
                break;
            }
        }
    }

    /**
     * Add guesthouses to search results as room-like entities.
     *
     * @param array<int, string> $locations
     */
    private function addGuesthousesToResults(array $rooms, array $locations): array
    {
        $guesthouseQuery = Facility::query()
            ->where('type', 'guesthouse')
            ->where('is_visible', true);

        if (! empty($locations)) {
            $guesthouseQuery->where(function ($query) use ($locations): void {
                foreach ($locations as $location) {
                    $query->orWhere('city', $location);
                }
            });
        }

        $guesthouses = $guesthouseQuery
            ->with(['photos', 'amenities', 'reviews'])
            ->orderBy('position', 'asc')
            ->get();

        foreach ($guesthouses as $guesthouse) {
            $rooms[] = [
                'id' => $guesthouse->roomadmin_id,
                'name' => $guesthouse->name,
                'local' => $guesthouse,
                'min_price' => $guesthouse->price_from,
                'photos' => $guesthouse->photos,
                'description' => $guesthouse->description,
            ];
        }

        return $rooms;
    }

    /**
     * Search for facilities with availability checking.
     *
     * @param array<string, mixed> $validated
     * @param array<int, string> $hashIn
     */
    private function searchForFacilities(array $validated, array $hashIn): array
    {
        // Get guesthouse IDs for the selected locations
        $guesthouseIds = $this->getGuesthouseIds($validated['location'] ?? []);
        $allFacilityIds = array_merge($hashIn, $guesthouseIds);

        // Check availability via external API
        $availableFacilities = $this->facilityAvailability(
            $validated['arrival'],
            $validated['departure'],
            $allFacilityIds,
            $validated['numberOfGuests']
        );

        // Get local facility data with amenities
        $localFacilities = QueryBuilder::for(Facility::class)
            ->allowedFilters(AllowedFilter::exact('amenities.shortname'))
            ->with(['photos', 'amenities', 'reviews'])
            ->whereIn('roomadmin_id', $allFacilityIds)
            ->where('is_visible', true)
            ->orderBy('position', 'asc')
            ->get();

        // Merge availability data with local facilities
        return $this->mergeAvailabilityWithFacilities($localFacilities, $availableFacilities);
    }

    /**
     * Get guesthouse IDs for specified locations.
     *
     * @param array<int, string> $locations
     * @return array<int, string>
     */
    private function getGuesthouseIds(array $locations): array
    {
        $query = Facility::query()
            ->where('type', 'guesthouse')
            ->where('is_visible', true);

        if (! empty($locations)) {
            $query->where(function ($q) use ($locations): void {
                foreach ($locations as $location) {
                    $q->orWhere('city', $location);
                }
            });
        }

        return $query->pluck('roomadmin_id')->toArray();
    }

    /**
     * Merge availability data from API with local facility records.
     */
    private function mergeAvailabilityWithFacilities(
        $localFacilities,
        array $availableFacilities
    ): array {
        $facilities = [];

        foreach ($availableFacilities as $availableFacility) {
            foreach ($localFacilities as $facility) {
                if ($facility->roomadmin_id == $availableFacility['hash']) {
                    $facility->variants = $availableFacility['variants'];

                    // Calculate minimum price from all variants
                    $prices = [];
                    foreach ($facility->variants as $variant) {
                        foreach ($variant['variants'] as $v) {
                            $prices[] = $v['price'];
                        }
                    }
                    $facility->min_price = min($prices) / 100;
                    $facilities[] = $facility;
                }
            }
        }

        return $facilities;
    }

    /**
     * Search for individual rooms with availability checking.
     *
     * @param array<string, mixed> $validated
     * @param array<int, string> $hashIn
     */
    private function searchForRooms(array $validated, array $hashIn, bool $isFilter): array
    {
        $availableFacilities = $this->facilityAvailability(
            $validated['arrival'],
            $validated['departure'],
            $hashIn,
            $validated['numberOfGuests']
        );

        $rooms = [];
        $localRooms = QueryBuilder::for(Room::class)
            ->allowedFilters(AllowedFilter::exact('amenities.shortname'))
            ->with(['photos', 'amenities'])
            ->orderBy('position', 'asc')
            ->get();

        // Process rooms from locations
        foreach ($availableFacilities as $availableFacility) {
            foreach ($availableFacility['variants'] as $facilityRoom) {
                $this->processAvailableRoom(
                    $facilityRoom,
                    $localRooms,
                    $availableFacility,
                    $rooms
                );
            }
        }

        // Add guesthouses
        $rooms = $this->addAvailableGuesthouses($rooms, $validated);

        return $this->sortResultsByPosition($rooms);
    }

    /**
     * Process a single available room and add to results.
     */
    private function processAvailableRoom(
        array $facilityRoom,
        $localRooms,
        array $availableFacility,
        array &$rooms
    ): void {
        foreach ($localRooms as $localRoom) {
            if ($localRoom->roomadmin_id == $facilityRoom['room']['id']) {
                $prices = array_column($facilityRoom['variants'], 'price');

                $rooms[] = [
                    'id' => $localRoom->roomadmin_id,
                    'name' => $localRoom->name,
                    'variants' => $facilityRoom['variants'],
                    'min_price' => min($prices) / 100,
                    'local' => $localRoom,
                    'facility' => $availableFacility['local'] ?? null,
                ];
            }
        }
    }

    /**
     * Add available guesthouses to room results.
     */
    private function addAvailableGuesthouses(array $rooms, array $validated): array
    {
        $guesthouseIds = $this->getGuesthouseIds($validated['location'] ?? []);

        if (empty($guesthouseIds)) {
            return $rooms;
        }

        $availableGuesthouses = $this->facilityAvailability(
            $validated['arrival'],
            $validated['departure'],
            $guesthouseIds,
            $validated['numberOfGuests']
        );

        $guesthouses = Facility::query()
            ->whereIn('roomadmin_id', $guesthouseIds)
            ->where('is_visible', true)
            ->with(['photos', 'amenities', 'reviews'])
            ->orderBy('position', 'asc')
            ->get();

        foreach ($guesthouses as $guesthouse) {
            $availability = collect($availableGuesthouses)
                ->firstWhere('hash', $guesthouse->roomadmin_id);

            if ($availability) {
                $prices = [];
                foreach ($availability['variants'] ?? [] as $variant) {
                    foreach ($variant['variants'] ?? [] as $v) {
                        $prices[] = $v['price'];
                    }
                }

                $rooms[] = [
                    'id' => $guesthouse->roomadmin_id,
                    'name' => $guesthouse->name,
                    'local' => $guesthouse,
                    'min_price' => ! empty($prices) ? min($prices) / 100 : null,
                    'photos' => $guesthouse->photos,
                    'description' => $guesthouse->description,
                ];
            }
        }

        return $rooms;
    }

    /**
     * Check facility availability via RoomAdmin API.
     *
     * @param string $arrival Arrival date (Y-m-d)
     * @param string $departure Departure date (Y-m-d)
     * @param array<int, string> $hashIn Facility identifiers
     * @param int $numberOfGuests Number of guests
     * @return array<int, array<string, mixed>>
     */
    public function facilityAvailability(
        string $arrival,
        string $departure,
        array $hashIn,
        int $numberOfGuests
    ): array {
        $response = Http::withToken(config('roomadmin.token'))
            ->get('https://se.roomadmin.pl/ws/facility-availability/find', [
                'arrival' => $arrival,
                'departure' => $departure,
                'hash_in' => $hashIn,
                'numberOfGuests' => $numberOfGuests,
                'variants' => 1,
            ]);

        $facilities = $response->json();

        if (isset($facilities['exception'])) {
            return [];
        }

        // Enrich API response with local facility data
        $localFacilities = Facility::all()->keyBy('roomadmin_id');

        foreach ($facilities as &$facility) {
            $facility['local'] = $localFacilities->get($facility['hash']);
        }

        return $facilities;
    }

    /**
     * Get rooms for facilities without availability checking.
     *
     * @param array<int, string> $hashIn
     * @param array<int, string> $locations
     * @return array<int, array<string, mixed>>
     */
    public function getRooms(array $hashIn, array $locations = []): array
    {
        $rooms = [];

        // Get rooms from locations
        $localFacilities = Facility::query()
            ->whereIn('roomadmin_id', $hashIn)
            ->where('type', 'location')
            ->with(['rooms.amenities', 'amenities'])
            ->get();

        foreach ($localFacilities as $facility) {
            $facilityRooms = $facility->rooms->map(fn ($room) => [
                'id' => $room->roomadmin_id,
                'name' => $room->name,
                'local' => $room,
            ])->all();

            if (! empty($facilityRooms)) {
                $rooms[] = [
                    'hash' => $facility->roomadmin_id,
                    'local' => $facility,
                    'rooms' => $facilityRooms,
                ];
            }
        }

        // Get guesthouses
        $guesthouseQuery = Facility::query()
            ->where('type', 'guesthouse')
            ->where('is_visible', true);

        if (! empty($locations)) {
            $guesthouseQuery->where(function ($query) use ($locations): void {
                foreach ($locations as $location) {
                    $query->orWhere('city', $location);
                }
            });
        }

        $guesthouses = $guesthouseQuery->with(['rooms.amenities', 'amenities'])->get();

        foreach ($guesthouses as $guesthouse) {
            $guesthouseRooms = $guesthouse->rooms->map(fn ($room) => [
                'id' => $room->roomadmin_id,
                'name' => $room->name,
                'local' => $room,
            ])->all();

            if (! empty($guesthouseRooms)) {
                $rooms[] = [
                    'hash' => $guesthouse->roomadmin_id,
                    'local' => $guesthouse,
                    'rooms' => $guesthouseRooms,
                ];
            }
        }

        return $rooms;
    }

    /**
     * Sort results by position value.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function sortResultsByPosition(array $results): array
    {
        return collect($results)
            ->sortBy(function (array $room): int {
                return $room['local']['position']
                    ?? $room['facility']['position']
                    ?? PHP_INT_MAX;
            })
            ->values()
            ->all();
    }
}
