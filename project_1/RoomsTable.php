<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Facility;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire component for managing rooms table with search and filtering.
 *
 * Features:
 * - Real-time search across room name, description, and facility name
 * - Filter by facility and visibility status
 * - Query string persistence for shareable URLs
 * - Drag & drop reordering via SortableJS integration
 * - Toggle visibility action
 */
final class RoomsTable extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $facilityFilter = '';

    #[Url(except: '')]
    public string $visibilityFilter = '';

    public int $perPage = 20;

    /**
     * Reset pagination when search query changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when facility filter changes.
     */
    public function updatingFacilityFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when visibility filter changes.
     */
    public function updatingVisibilityFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all filters and reset pagination.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->facilityFilter = '';
        $this->visibilityFilter = '';
        $this->resetPage();
    }

    /**
     * Toggle room visibility status.
     *
     * Dispatches 'room-visibility-updated' event for frontend notifications.
     */
    public function toggleVisibility(int $roomId): void
    {
        $room = Room::findOrFail($roomId);
        $room->update(['is_visible' => ! $room->is_visible]);

        $this->dispatch('room-visibility-updated', [
            'roomId' => $roomId,
            'isVisible' => $room->is_visible,
        ]);
    }

    /**
     * Update room positions after drag & drop reordering.
     *
     * @param array<int> $orderedIds Array of room IDs in new order
     */
    public function updateOrder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            Room::where('id', $id)->update(['position' => $index + 1]);
        }

        $this->dispatch('order-updated');
    }

    /**
     * Get filtered and paginated rooms with eager-loaded relationships.
     *
     * Uses conditional query building with Eloquent's when() method
     * for clean, readable filter logic.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getRoomsProperty()
    {
        return Room::query()
            ->with(['facility.photos', 'photos'])
            ->when($this->search, function (Builder $query): void {
                $query->where(function (Builder $subQuery): void {
                    $subQuery->where('name', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhere('roomadmin_id', 'like', "%{$this->search}%")
                        ->orWhereHas('facility', function (Builder $facilityQuery): void {
                            $facilityQuery->where('name', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->facilityFilter, function (Builder $query): void {
                $query->where('facility_id', $this->facilityFilter);
            })
            ->when($this->visibilityFilter !== '', function (Builder $query): void {
                $query->where('is_visible', (bool) $this->visibilityFilter);
            })
            ->orderBy('position', 'asc')
            ->paginate($this->perPage);
    }

    /**
     * Get facilities for the filter dropdown.
     *
     * Only returns 'location' type facilities as rooms belong to locations,
     * not guesthouses.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFacilitiesForFilterProperty()
    {
        return Facility::query()
            ->select(['id', 'name'])
            ->where('type', 'location')
            ->orderBy('name')
            ->get();
    }

    /**
     * Render the component view.
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.rooms-table', [
            'rooms' => $this->rooms,
            'facilitiesForFilter' => $this->facilitiesForFilter,
        ]);
    }
}
