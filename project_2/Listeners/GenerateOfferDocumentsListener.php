<?php

namespace App\Listeners;

use App\Events\OfferSaved;
use App\Jobs\GenerateOfferDocumentsJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Generate Offer Documents Listener
 */
class GenerateOfferDocumentsListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(OfferSaved $event): void
    {
        GenerateOfferDocumentsJob::dispatch(
            $event->offer,
            ['installation', 'items']
        );
    }
}
