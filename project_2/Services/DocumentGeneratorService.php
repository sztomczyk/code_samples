<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentTemplateType;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Document Generator Service
 */
class DocumentGeneratorService
{
    public function __construct(
        protected GoogleDriveService $googleDrive
    ) {}

    /**
     * Generate a document for an offer.
     */
    public function generateForOffer(Offer $offer, DocumentTemplateType $templateType): ?Document
    {
        $templateId = $this->getTemplateId($templateType);

        if (! $templateId) {
            Log::warning("No template ID configured for type: {$templateType->value}");
            return null;
        }

        $lead = $offer->lead;
        if (! $lead) {
            throw new \RuntimeException('Offer has no associated lead.');
        }

        try {
            // Get or create the lead folder
            $leadFolderId = $this->getOrCreateLeadFolder($lead);

            // Generate file name
            $fileName = $this->generateFileName($offer, $templateType);

            // Delete existing files with the same name (regeneration)
            $this->googleDrive->deleteFilesByName($leadFolderId, $fileName);
            $this->googleDrive->deleteFilesByName($leadFolderId, $fileName.'.pdf');

            // Copy template to lead folder
            $documentId = $this->googleDrive->copyFile(
                $templateId,
                $fileName,
                $leadFolderId
            );

            // Build replacements and apply them
            $replacements = $this->buildReplacements($offer, $templateType);
            $this->googleDrive->replaceTextInDocument($documentId, $replacements);

            // Export to PDF
            $pdfId = $this->googleDrive->exportToPdf($documentId, $leadFolderId, $fileName.'.pdf');

            // Set public view permissions
            $this->googleDrive->setPublicViewPermission($documentId);
            $this->googleDrive->setPublicViewPermission($pdfId);

            // Download PDF content and save locally (backup)
            $localPdfPath = $this->savePdfLocally($offer, $fileName, $documentId);

            // Get URLs
            $docsUrl = $this->googleDrive->getDocsUrl($documentId);
            $pdfUrl = $this->googleDrive->getViewUrl($pdfId);

            // Create or update document record
            return $this->createOrUpdateDocument(
                $offer,
                $templateType,
                $fileName,
                $documentId,
                $pdfId,
                $docsUrl,
                $pdfUrl,
                $localPdfPath
            );
        } catch (\Exception $e) {
            Log::error("Failed to generate document for offer {$offer->id}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get or create a folder for the lead.
     */
    public function getOrCreateLeadFolder(Lead $lead): ?string
    {
        if (! $this->googleDrive->isConfigured()) {
            return null;
        }

        // Return existing folder if already created
        if ($lead->google_drive_folder_id) {
            return $lead->google_drive_folder_id;
        }

        $rootFolderId = Setting::getGoogleDriveRootFolderId();

        if (! $rootFolderId) {
            throw new \RuntimeException('Google Drive root folder ID is not configured');
        }

        // Generate folder name from lead data
        $contact = $lead->contactDetails->first();
        $folderName = $this->generateLeadFolderName($lead, $contact);

        // Check if folder already exists (edge case)
        $existingFolderId = $this->googleDrive->findFolderByName($folderName, $rootFolderId);

        if ($existingFolderId) {
            $lead->update(['google_drive_folder_id' => $existingFolderId]);
            return $existingFolderId;
        }

        // Create new folder
        $folderId = $this->googleDrive->createFolder($folderName, $rootFolderId);
        $lead->update(['google_drive_folder_id' => $folderId]);

        return $folderId;
    }

    /**
     * Build replacement array for placeholders.
     *
     * @return array<string, string>
     */
    protected function buildReplacements(Offer $offer, DocumentTemplateType $templateType): array
    {
        $lead = $offer->load(['lead.contactDetails', 'positions'])->lead;
        $contact = $lead->contactDetails->first();

        $replacements = [
            // Date
            '{{todayDate}}' => now()->format('d/m/Y'),

            // Customer data
            '{{customer.name}}' => $contact?->name ?? '',
            '{{customer.address}}' => $this->formatAddress($contact),
            '{{customer.email}}' => $contact?->email ?? '',
            '{{customer.phone}}' => $contact?->phone ?? '',

            // Offer data
            '{{offer.nr}}' => $offer->offer_number ?? '',
            '{{offer.createdDate}}' => $offer->created_at?->format('d/m/Y') ?? '',

            // Pricing
            '{{price.offer}}' => $this->formatPrice($offer->subtotal),
            '{{price.installation}}' => $this->formatPrice($offer->installation_cost),
            '{{price.totalNetto}}' => $this->formatPrice($offer->subtotal),
            '{{price.vat21Netto}}' => $this->formatPrice($offer->vat_21_amount),
            '{{price.vat21}}' => $this->formatPrice($offer->vat_21_amount ? $offer->vat_21_amount * 0.21 : 0),
            '{{price.vat9Netto}}' => $this->formatPrice($offer->vat_9_amount),
            '{{price.vat9}}' => $this->formatPrice($offer->vat_9_amount ? $offer->vat_9_amount * 0.09 : 0),
            '{{price.total}}' => $this->formatPrice($offer->total),

            // Additional costs
            '{{price.processing}}' => $this->formatPrice($offer->processing_cost),
            '{{price.scaffold}}' => $this->formatPrice($offer->scaffold_cost),
            '{{price.parapet}}' => $this->formatPrice($offer->parapet_cost),
            '{{price.lift}}' => $this->formatPrice($offer->lift_cost),
            '{{price.hoist}}' => $this->formatPrice($offer->hoist_cost),
            '{{price.container}}' => $this->formatPrice($offer->container_cost),

            // Glazing info from positions
            '{{glazing}}' => $this->getGlazingInfo($offer),

            // Delivery time
            '{{weeksRange}}' => $this->calculateWeeksRange($offer),
        ];

        // Conditional content based on offer data
        $hasProcessing = $offer->processing_cost > 0;
        $replacements['{{isProcessing1}}'] = $hasProcessing ? '' : '[REMOVE_LINE]';
        $replacements['{{isProcessing2}}'] = $hasProcessing ? '' : '[REMOVE_LINE]';

        // Check for "INHAAK" positions
        $hasInhaak = $offer->positions->contains(function ($position) {
            return str_contains(strtoupper($position->name ?? ''), 'INHAAK');
        });
        $replacements['{{isInhaak}}'] = $hasInhaak ? '' : '[REMOVE_LINE]';

        return $replacements;
    }

    /**
     * Generate file name for the document.
     */
    protected function generateFileName(Offer $offer, DocumentTemplateType $templateType): string
    {
        $lead = $offer->lead;
        $contact = $lead?->contactDetails?->first();
        $customerName = $contact?->name ?? 'Unknown';

        // Clean the name for file system
        $cleanName = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $customerName);
        $cleanName = str_replace(' ', '_', $cleanName);

        $typePrefix = match ($templateType) {
            DocumentTemplateType::Installation => 'install',
            DocumentTemplateType::Items => 'items',
        };

        return "{$typePrefix}_{$offer->offer_number}_{$cleanName}";
    }

    /**
     * Format price for display.
     */
    protected function formatPrice($amount): string
    {
        if ($amount === null) {
            return '0,00';
        }

        return number_format((float) $amount, 2, ',', '.');
    }

    /**
     * Save PDF content locally as backup.
     */
    protected function savePdfLocally(Offer $offer, string $fileName, string $documentId): ?string
    {
        try {
            $pdfContent = $this->googleDrive->downloadPdfFromDoc($documentId);

            $directory = "documents/offers/{$offer->id}";
            $fullPath = "{$directory}/{$fileName}.pdf";

            Storage::disk('public')->put($fullPath, $pdfContent);

            return $fullPath;
        } catch (\Exception $e) {
            Log::error('Failed to save PDF locally', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
