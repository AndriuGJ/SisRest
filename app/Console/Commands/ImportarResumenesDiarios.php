<?php

namespace App\Console\Commands;

use App\Services\Facturacion\ImportarResumenesHistoricos;
use Illuminate\Console\Command;

class ImportarResumenesDiarios extends Command
{
    protected $signature = 'facturacion:importar-resumenes';

    protected $description = 'Vincula resúmenes unitarios históricos sin enviar ni modificar documentos originales';

    public function handle(): int
    {
        $cantidad = app(ImportarResumenesHistoricos::class)->importar();
        $this->info('Registros revisados: '.$cantidad.'. Operación local e idempotente; sin envíos.');

        return self::SUCCESS;
    }
}
