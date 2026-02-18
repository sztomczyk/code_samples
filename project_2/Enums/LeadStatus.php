<?php

namespace App\Enums;

/**
 * Lead Status Enum
 */
enum LeadStatus: string
{
    case New = 'new';
    case QuoteAtManufacturer = 'quote_at_manufacturer';
    case PreparingOffer = 'preparing_offer';
    case ClientWaitingForOffer = 'client_waiting_for_offer';
    case OfferSentToClient = 'offer_sent_to_client';
    case OfferCorrection = 'offer_correction';
    case OfferAcceptedByClient = 'offer_accepted_by_client';
    case Rejected = 'rejected';
    case Archive = 'archive';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::QuoteAtManufacturer => 'Quote at manufacturer',
            self::PreparingOffer => 'Preparing offer',
            self::ClientWaitingForOffer => 'Client waiting for offer',
            self::OfferSentToClient => 'Offer sent to client',
            self::OfferCorrection => 'Offer correction',
            self::OfferAcceptedByClient => 'Offer accepted by client',
            self::Rejected => 'Rejected',
            self::Archive => 'Archive',
        };
    }

    /**
     * Get color for status badge (example of extended functionality).
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::QuoteAtManufacturer, self::PreparingOffer => 'yellow',
            self::ClientWaitingForOffer, self::OfferSentToClient => 'orange',
            self::OfferAcceptedByClient => 'green',
            self::OfferCorrection => 'red',
            self::Rejected => 'gray',
            self::Archive => 'slate',
        };
    }

    /**
     * Check if this is a "won" status.
     */
    public function isWon(): bool
    {
        return $this === self::OfferAcceptedByClient;
    }

    /**
     * Check if this is a "lost" status.
     */
    public function isLost(): bool
    {
        return $this === self::Rejected;
    }
}
