<?php

namespace Database\Seeders;

use App\Models\CategoriaProducto;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\TipoComprobante;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['codigo' => '1', 'nombre' => 'DNI', 'longitud' => 8],
            ['codigo' => '6', 'nombre' => 'RUC', 'longitud' => 11],
            ['codigo' => '4', 'nombre' => 'Carné de extranjería', 'longitud' => null],
            ['codigo' => '7', 'nombre' => 'Pasaporte', 'longitud' => null],
        ] as $datos) {
            TipoDocumentoIdentidad::firstOrCreate(['codigo' => $datos['codigo']], $datos);
        }

        foreach ([
            ['codigo' => '01', 'nombre' => 'Factura', 'electronico' => true],
            ['codigo' => '03', 'nombre' => 'Boleta de venta', 'electronico' => true],
            ['codigo' => '07', 'nombre' => 'Nota de crédito', 'electronico' => true],
            ['codigo' => '00', 'nombre' => 'Otro documento de compra', 'electronico' => false],
        ] as $datos) {
            TipoComprobante::firstOrCreate(['codigo' => $datos['codigo']], $datos);
        }

        foreach ([
            ['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true],
            ['codigo' => 'yape', 'nombre' => 'Yape', 'afecta_efectivo' => false],
            ['codigo' => 'plin', 'nombre' => 'Plin', 'afecta_efectivo' => false],
            ['codigo' => 'tarjeta', 'nombre' => 'Tarjeta', 'afecta_efectivo' => false],
            ['codigo' => 'transferencia', 'nombre' => 'Transferencia bancaria', 'afecta_efectivo' => false],
        ] as $datos) {
            MedioPago::firstOrCreate(['codigo' => $datos['codigo']], $datos);
        }

        foreach ([
            ['codigo' => 'g', 'nombre' => 'Gramo', 'magnitud' => 'masa', 'factor_base' => 1, 'codigo_sunat' => 'GRM'],
            ['codigo' => 'kg', 'nombre' => 'Kilogramo', 'magnitud' => 'masa', 'factor_base' => 1000, 'codigo_sunat' => 'KGM'],
            ['codigo' => 'ml', 'nombre' => 'Mililitro', 'magnitud' => 'volumen', 'factor_base' => 1, 'codigo_sunat' => 'MLT'],
            ['codigo' => 'l', 'nombre' => 'Litro', 'magnitud' => 'volumen', 'factor_base' => 1000, 'codigo_sunat' => 'LTR'],
            ['codigo' => 'und', 'nombre' => 'Unidad', 'magnitud' => 'unidad', 'factor_base' => 1, 'codigo_sunat' => 'NIU'],
            ['codigo' => 'porcion', 'nombre' => 'Porción', 'magnitud' => 'unidad', 'factor_base' => 1, 'codigo_sunat' => 'NIU'],
        ] as $datos) {
            UnidadMedida::firstOrCreate(['codigo' => $datos['codigo']], $datos);
        }

        foreach (['Platos', 'Entradas', 'Bebidas', 'Combos', 'Adicionales', 'Menús'] as $orden => $nombre) {
            CategoriaProducto::firstOrCreate(['nombre' => $nombre], ['orden' => $orden]);
        }

        foreach ([
            ['codigo' => 'cumpleanos', 'nombre' => 'Cumpleaños'],
            ['codigo' => 'promocion', 'nombre' => 'Promoción'],
            ['codigo' => 'atencion_comercial', 'nombre' => 'Atención comercial'],
            ['codigo' => 'otro', 'nombre' => 'Otro motivo'],
        ] as $datos) {
            MotivoBeneficio::firstOrCreate(['codigo' => $datos['codigo']], $datos + ['tipo' => 'ambos']);
        }
    }
}
