<?php

namespace Database\Seeders;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\Impresora;
use App\Models\Salon;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use Illuminate\Database\Seeder;

class ConfiguracionInicialSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::firstOrCreate(['singleton' => 1], [
            'moneda' => 'PEN',
            'zona_horaria' => 'America/Lima',
            'precios_incluyen_impuestos' => true,
        ]);

        ConfiguracionFacturacion::firstOrCreate(['empresa_id' => $empresa->id], [
            'habilitada' => false,
            'entorno' => 'beta',
        ]);

        if (! Salon::query()->exists()) {
            Salon::create(['codigo' => 'principal', 'nombre' => 'Principal']);
        }
        $cocina = AreaPreparacion::firstOrCreate(['codigo' => 'cocina'], ['nombre' => 'Cocina']);
        $barra = AreaPreparacion::firstOrCreate(['codigo' => 'barra'], ['nombre' => 'Barra', 'activo' => false]);

        foreach ([1, 2] as $numero) {
            $impresora = Impresora::firstOrCreate(['codigo' => 'caja_'.$numero], [
                'nombre' => 'Impresora Caja '.$numero,
                'activo' => false,
            ]);
            Caja::firstOrCreate(['codigo' => 'caja_'.$numero], [
                'nombre' => 'Caja '.$numero,
                'impresora_id' => $impresora->id,
            ]);
        }

        foreach ([$cocina, $barra] as $area) {
            Impresora::firstOrCreate(['codigo' => $area->codigo], [
                'nombre' => 'Impresora '.$area->nombre,
                'area_preparacion_id' => $area->id,
                'activo' => false,
            ]);
        }

        foreach ([['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['07', 'BC01']] as [$codigo, $serie]) {
            $tipo = TipoComprobante::query()->where('codigo', $codigo)->firstOrFail();
            SerieComprobante::firstOrCreate([
                'tipo_comprobante_id' => $tipo->id,
                'entorno' => 'beta',
                'serie' => $serie,
            ], ['activo' => false]);
        }
    }
}
