<?php

namespace App\Jobs;

use App\Enums\DocumentTemplateType;
use App\Models\Offer;
use App\Services\DocumentGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate Offer Documents Job
 */
class GenerateOfferDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @param  array<string>  $documentTypes
     */
    public function __construct(
        public Offer $offer,
        public array $documentTypes = ['installation', 'items']
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentGeneratorService $generator): void
    {
        Log::info("Starting document generation for offer {$this->offer->id}", [
            'document_types' => $this->documentTypes,
        ]);

        foreach ($this->documentTypes as $type) {
            try {
                $templateType = DocumentTemplateType::from($type);
                $generator->generateForOffer($this->offer, $templateType);
            } catch (\Exception $e) {
                Log::error("Failed to generate {$type} document for offer {$this->offer->id}", [
                    'error' => $e->getMessage(),
                ]);

                // Re-throw to trigger retry mechanism
                throw $e;
            }
        }

        Log::info("Completed document generation for offer {$this->offer->id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Document generation job failed for offer {$this->offer->id}", [
            'document_types' => $this->documentTypes,
            'error' => $exception->getMessage(),
        ]);

        // Could send notification to user here
        // Mail::to($this->offer->user)->send(new DocumentGenerationFailed($this->offer));
    }
}
