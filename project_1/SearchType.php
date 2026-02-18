<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum representing search result types.
 *
 * Used for type-safe search mode selection in the search API.
 * This enum ensures only valid search types can be passed to the controller.
 */
enum SearchType: string
{
    /**
     * Search for facilities (locations and guesthouses).
     */
    case Facilities = 'facilities';

    /**
     * Search for individual rooms within facilities.
     */
    case Rooms = 'rooms';
}
