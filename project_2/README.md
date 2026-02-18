## Stack
- Backend: Laravel 11, PHP 8.2+
- Frontend: React, TypeScript, TanStack Table, shadcn/ui
- Integracje: Google Docs/Drive API, OAuth2
- Queue: Laravel Queue

## Pliki

**`Services/GoogleDriveService.php`** - Integracja z Google Drive/Docs API, OAuth2 z automatycznym odświeżaniem tokena, batch update dokumentów, eksport do PDF

**`Services/DocumentGeneratorService.php`** - Generowanie dokumentów z szablonów Google Docs, system placeholderów, podwójne przechowywanie (Google Drive + lokalny backup)

**`Models/Lead.php`** - Model z automatyczną konwersją Lead → Job, generowaniem sekwencyjnych numerów zadań, relacjami polimorficznymi i Laravel Scout

**`Casts/IntegerMoneyCast.php`** - Custom cast do przechowywania pieniędzy jako integery (grosze) z transparentną konwersją

**`Enums/LeadStatus.php`** - PHP enum dla bezpiecznego zarządzania statusami z metodą `label()`

**`Events/OfferSaved.php`** + **`Listeners/GenerateOfferDocumentsListener.php`** + **`Jobs/GenerateOfferDocumentsJob.php`** - Architektura event-driven z asynchronicznym przetwarzaniem i retry logic

**`components/data-table.tsx`** - React komponent tabeli z TanStack Table, sortowaniem, filtrowaniem, grupowaniem i TypeScript generikami

---

## Struktura bazy danych (ERD)

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              CORE BUSINESS ENTITIES                              │
└─────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   Organization   │────▶│       Lead       │◀────│   ContactDetail  │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ id               │     │ id               │     │ id               │
│ name             │     │ organization_id  │     │ name             │
│ vat_number       │     │ status (enum)    │     │ email            │
│ ...              │     │ temperature      │     │ phone            │
└──────────────────┘     │ source (enum)    │     │ address          │
                         │ is_job           │     │ zipcode          │
                         │ job_number       │     │ city             │
                         │ google_drive_    │     └────────┬─────────┘
                         │   folder_id      │              │
                         └────────┬─────────┘              │
                                  │                        │
                    ┌─────────────┼────────────────────────┘
                    │             │
                    ▼             │
           ┌──────────────────┐   │     POLYMORPHIC RELATIONS
           │      Offer       │   │     ═══════════════════════
           ├──────────────────┤   │
           │ id               │   │     ┌──────────────────────────────┐
           │ lead_id          │◀──┘     │          Task                │
           │ offer_number     │         ├──────────────────────────────┤
           │ subtotal (int)   │         │ id                           │
           │ vat_9_amount     │         │ taskable_type (Lead/Offer/…) │
           │ vat_21_amount    │         │ taskable_id                  │
           │ total (int)      │         │ title, description           │
           │ installation_    │         │ due_date, completed_at       │
           │   cost (int)     │         └──────────────────────────────┘
           │ processing_cost  │
           │ scaffold_cost    │         ┌──────────────────────────────┐
           │ ...              │         │      CalendarEvent           │
           └────────┬─────────┘         ├──────────────────────────────┤
                    │                   │ eventable_type               │
                    │                   │ eventable_id                 │
                    ▼                   │ title, start, end            │
           ┌──────────────────┐         └──────────────────────────────┘
           │  OfferPosition   │
           ├──────────────────┤         ┌──────────────────────────────┐
           │ id               │         │       StatusChange           │
           │ offer_id         │         ├──────────────────────────────┤
           │ name             │         │ statusable_type              │
           │ width, height    │         │ statusable_id                │
           │ glass_type       │         │ from_status, to_status       │
           │ quantity         │         │ changed_by, changed_at       │
           │ price (int)      │         └──────────────────────────────┘
           └──────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────┐
│                              DOCUMENT GENERATION                                 │
└─────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│     Document     │     │ GoogleOAuthToken │     │     Setting      │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ id               │     │ id               │     │ key              │
│ documentable_    │     │ access_token     │     │ value            │
│   type/id        │     │ refresh_token    │     │ google_template_ │
│ type (enum)      │     │ expires_at       │     │   installation_id│
│ status (enum)    │     │ scopes           │     │ google_template_ │
│ google_drive_    │     └──────────────────┘     │   items_id       │
│   file_id        │                              │ google_drive_    │
│ google_drive_    │                              │   root_folder_id │
│   pdf_id         │                              └──────────────────┘
│ local_pdf_path   │
└──────────────────┘

┌─────────────────────────────────────────────────────────────────────────────────┐
│                              EVENT-DRIVEN FLOW                                   │
└─────────────────────────────────────────────────────────────────────────────────┘

  Controller         Event           Listener           Job              Service
  ──────────        ──────          ─────────         ──────            ───────
      │                │                │                 │                 │
      │  $offer->save()│                │                 │                 │
      │───────────────▶│                │                 │                 │
      │                │ OfferSaved     │                 │                 │
      │                │───────────────▶│                 │                 │
      │                │                │ dispatch(Job)   │                 │
      │                │                │────────────────▶│                 │
      │                │                │                 │ handle()        │
      │                │                │                 │────────────────▶│
      │                │                │                 │                 │
      │                │                │                 │  Google Drive   │
      │                │                │                 │  API calls      │
      │                │                │                 │                 │
      │                │                │                 │◀────────────────│
      │                │                │                 │  return $doc    │
      │◀───────────────│◀───────────────│◀────────────────│                 │
      │                │                │                 │                 │
                     (sync)          (queued)         (queued)           (sync)

┌─────────────────────────────────────────────────────────────────────────────────┐
│                              KLUCZOWE RELACJE                                    │
└─────────────────────────────────────────────────────────────────────────────────┘

• Lead ──hasMany──▶ Offer           (jeden lead może mieć wiele ofert)
• Offer ──hasMany──▶ OfferPosition  (jedna oferta ma wiele pozycji/szyb)
• Lead ◀──belongsToMany──▶ ContactDetail  (wiele kontaktów dla leada)
• Lead ──morphMany──▶ Task          (polimorficzne - zadania dla każdego modelu)
• Lead ──morphMany──▶ CalendarEvent (polimorficzne - wydarzenia)
• Lead ──morphMany──▶ StatusChange  (polimorficzne - historia zmian)
• Offer ──morphOne──▶ Document      (polimorficzne - dokumenty)
