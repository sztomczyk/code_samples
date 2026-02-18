{{-- Livewire Rooms Table Component --}}

<div>
    {{-- Search and Filters --}}
    <div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
        <div class="flex flex-wrap gap-4">
            {{-- Search Input --}}
            <div class="flex-1 min-w-64">
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">
                    Search
                </label>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="text"
                    id="search"
                    placeholder="Room name, description..."
                    class="block w-full rounded-md border-gray-300 shadow-sm
                           focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                >
            </div>

            {{-- Facility Filter --}}
            <div class="flex-shrink-0 min-w-48">
                <label for="facilityFilter" class="block text-sm font-medium text-gray-700 mb-1">
                    Facility
                </label>
                <select
                    wire:model.live="facilityFilter"
                    id="facilityFilter"
                    class="block w-full rounded-md border-gray-300 shadow-sm
                           focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                >
                    <option value="">All facilities</option>
                    @foreach($facilitiesForFilter as $facility)
                        <option value="{{ $facility->id }}">{{ $facility->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Visibility Filter --}}
            <div class="flex-shrink-0 min-w-32">
                <label for="visibilityFilter" class="block text-sm font-medium text-gray-700 mb-1">
                    Visibility
                </label>
                <select
                    wire:model.live="visibilityFilter"
                    id="visibilityFilter"
                    class="block w-full rounded-md border-gray-300 shadow-sm
                           focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                >
                    <option value="">All</option>
                    <option value="1">Visible</option>
                    <option value="0">Hidden</option>
                </select>
            </div>
        </div>

        {{-- Filter Actions --}}
        <div class="mt-4 flex items-center justify-between">
            <button
                wire:click="clearFilters"
                class="text-sm text-gray-500 hover:text-gray-700 underline"
            >
                Clear filters
            </button>

            <span class="text-sm text-gray-500">
                Results: {{ $rooms->total() }}
            </span>
        </div>
    </div>

    {{-- Loading Indicator --}}
    <div wire:loading class="mb-4">
        <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
            <div class="flex items-center">
                <svg class="animate-spin h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                <span class="ml-3 text-sm text-blue-700">Loading...</span>
            </div>
        </div>
    </div>

    {{-- Pagination Top --}}
    @if($rooms->hasPages())
        <div class="mb-4">
            {{ $rooms->links() }}
        </div>
    @endif

    {{-- Rooms Table --}}
    @if($rooms->count() > 0)
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Room
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Facility
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Visible
                            </th>
                            <th class="relative px-6 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="sortable-rooms" class="bg-white divide-y divide-gray-200">
                        @foreach($rooms as $room)
                            <tr
                                data-id="{{ $room->id }}"
                                class="cursor-move hover:bg-gray-50 transition-colors"
                            >
                                {{-- Room Info --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        {{-- Thumbnail --}}
                                        @if($room->photos->isNotEmpty())
                                            <img
                                                src="{{ $room->photos->first()->asset_path }}"
                                                alt="{{ $room->name }}"
                                                class="h-12 w-12 rounded-lg object-cover"
                                            >
                                        @else
                                            <div class="h-12 w-12 rounded-lg bg-gray-200 flex items-center justify-center">
                                                <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                                    </path>
                                                </svg>
                                            </div>
                                        @endif

                                        {{-- Name & Description --}}
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $room->name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ Str::limit(strip_tags($room->description ?? ''), 50) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Facility --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $room->facility->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $room->facility->city }}</div>
                                </td>

                                {{-- Visibility Toggle --}}
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <button
                                        wire:click="toggleVisibility({{ $room->id }})"
                                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer
                                               rounded-full border-2 border-transparent transition-colors
                                               duration-200 ease-in-out focus:outline-none focus:ring-2
                                               focus:ring-primary-500 focus:ring-offset-2
                                               {{ $room->is_visible ? 'bg-primary-600' : 'bg-gray-200' }}"
                                        role="switch"
                                        aria-checked="{{ $room->is_visible ? 'true' : 'false' }}"
                                    >
                                        <span
                                            class="pointer-events-none inline-block h-5 w-5 transform
                                                   rounded-full bg-white shadow ring-0 transition
                                                   duration-200 ease-in-out
                                                   {{ $room->is_visible ? 'translate-x-5' : 'translate-x-0' }}"
                                        ></span>
                                    </button>
                                </td>

                                {{-- Actions --}}
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a
                                        href="{{ route('rooms.edit', $room->id) }}"
                                        class="text-primary-600 hover:text-primary-900"
                                    >
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination Bottom --}}
            @if($rooms->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $rooms->links() }}
                </div>
            @endif
        </div>

        {{-- SortableJS for Drag & Drop --}}
        @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <script>
            document.addEventListener('livewire:initialized', () => {
                const tbody = document.getElementById('sortable-rooms');
                if (tbody) {
                    new Sortable(tbody, {
                        animation: 150,
                        ghostClass: 'opacity-50',
                        onEnd: function(evt) {
                            const orderedIds = Array.from(tbody.children)
                                .map(row => row.getAttribute('data-id'));
                            @this.call('updateOrder', orderedIds);
                        }
                    });
                }
            });
        </script>
        @endpush

    @else
        {{-- Empty State --}}
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
                    </path>
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">No rooms found</h3>
                <p class="mt-1 text-sm text-gray-500">
                    @if($search || $facilityFilter || $visibilityFilter !== '')
                        Try adjusting your search criteria.
                    @else
                        Rooms will appear here once added.
                    @endif
                </p>
            </div>
        </div>
    @endif
</div>
