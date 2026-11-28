<?php

use App\Services\Facturacion\ImportarResumenesHistoricos;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ImportarResumenesHistoricos::class)->importar();
    }

    public function down(): void
    {
        // Deliberately preserve linked history and all original records.
    }
};
