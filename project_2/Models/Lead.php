<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\LeadType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * Lead Model
 */
class Lead extends Model
{
    use HasFactory, SoftDeletes, Searchable;

    /**
     * Boot method with model events.
     */
    protected static function boot(): void
    {
        parent::boot();

        // On saving (create or update)
        static::saving(function (Lead $lead) {
            // Generate job number when is_job changes to true
            if ($lead->is_job && ! $lead->job_number) {
                $lead->job_number = static::generateJobNumber();
            }
        });

        // On update only
        static::updating(function (Lead $lead) {
            // Convert lead to job when status changes
            if ($lead->isDirty('status')) {
                $oldStatus = $lead->getOriginal('status');
                $newStatus = $lead->status;

                if ($newStatus === LeadStatus::OfferAcceptedByClient && $oldStatus !== LeadStatus::OfferAcceptedByClient) {
                    if (! $lead->is_job) {
                        $lead->is_job = true;
                    }
                }
            }

            // Generate job number when is_job changes from false to true
            if ($lead->is_job && ! $lead->job_number) {
                $originalIsJob = $lead->getOriginal('is_job');
                if (! $originalIsJob) {
                    $lead->job_number = static::generateJobNumber();
                }
            }
        });
    }

    /**
     * Generate sequential job number with year.
     * Format: 20001/2024, 20002/2024, etc.
     */
    protected static function generateJobNumber(): string
    {
        $year = now()->year;
        $baseNumber = 20000;

        // Find all job numbers for this year and get the highest one
        $jobNumbers = static::where('job_number', 'like', "%/{$year}")
            ->whereNotNull('job_number')
            ->pluck('job_number')
            ->map(function ($jobNumber) {
                $parts = explode('/', $jobNumber);
                return (int) $parts[0];
            })
            ->filter()
            ->values();

        if ($jobNumbers->isNotEmpty()) {
            $nextNumber = $jobNumbers->max() + 1;
        } else {
            $nextNumber = $baseNumber;
        }

        return "{$nextNumber}/{$year}";
    }

    protected $fillable = [
        'organization_id',
        'type',
        'status',
        'temperature',
        'source',
        'language',
        'preferred_manufacturer_id',
        'b2b_price',
        'notes',
        'call_notes',
        'assigned_to',
        'is_job',
        'job_number',
        // ... many more fields for job tracking
        'google_drive_folder_id',
    ];

    protected function casts(): array
    {
        return [
            // Enum casting for type safety
            'type' => LeadType::class,
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'source' => LeadSource::class,

            // JSON fields
            'measurement_files' => 'array',
            'documentation_files' => 'array',

            // Boolean flags for job tracking
            'is_job' => 'boolean',
            'offer_signed_by_client' => 'boolean',
            'deposit_paid' => 'boolean',
            // ... many more tracking flags
        ];
    }

    // ============ RELATIONSHIPS ============

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Polymorphic relationship for tasks.
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Polymorphic relationship for calendar events.
     */
    public function calendarEvents(): MorphMany
    {
        return $this->morphMany(CalendarEvent::class, 'eventable');
    }

    /**
     * Polymorphic relationship for status change history.
     */
    public function statusChanges(): MorphMany
    {
        return $this->morphMany(StatusChange::class, 'statusable');
    }

    /**
     * Many-to-many with contact details.
     */
    public function contactDetails(): BelongsToMany
    {
        return $this->belongsToMany(ContactDetail::class, 'lead_contact_detail');
    }

    // ============ ACCESSORS ============

    public function getFullNameAttribute(): string
    {
        $firstContact = $this->contactDetails->first();
        return $firstContact ? $firstContact->name : '';
    }

    // ============ SCOUT SEARCH ============

    /**
     * Laravel Scout for full-text search.
     */
    public function toSearchableArray(): array
    {
        return [
            'notes' => $this->notes,
            'call_notes' => $this->call_notes,
            'job_number' => $this->job_number,
        ];
    }
}
