# Laravel + Livewire - Przykłady kodu

## Stack
- Laravel 11
- Livewire 3
- PHP 8.2+
- Eloquent ORM
- Spatie QueryBuilder

## Pliki

**`RoomsTable.php`** + **`rooms-table.blade.php`** - Komponent Livewire z interaktywną tabelą, wyszukiwaniem w czasie rzeczywistym, filtrowaniem po relacjach i drag & drop sortowaniem

**`Facility.php`** - Model Eloquent z relacjami polimorficznymi, integracją z zewnętrznym API i przechowywaniem cen w centach

**`SearchController.php`** - API controller z dynamicznym filtrowaniem przez QueryBuilder, integracją z zewnętrznym serwisem i kalkulacją cen

**`Photo.php`** - Model polimorficzny z relacją `morphTo()` i custom accessorami

**`SearchType.php`** - PHP enum dla type-safe parametrów wyszukiwania

**`2024_01_01_000000_create_facilities_table.php`** - Migracja głównej encji z danymi geograficznymi

**`2024_01_01_000001_create_polymorphic_tables.php`** - Migracja tabel relacji polimorficznych
