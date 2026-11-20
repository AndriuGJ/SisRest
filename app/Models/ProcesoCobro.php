<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-import-type Fiscal from \App\Services\Cobros\ProcesarVenta
 *
 * @property int $id
 * @property int $cobro_id
 * @property int $usuario_id
 * @property int $caja_id
 * @property string $documento
 * @property bool $liberar
 * @property Fiscal $fiscal
 * @property string $clave_emision
 * @property string $clave_impresion
 * @property int|null $comprobante_id
 * @property int|null $trabajo_impresion_id
 * @property CarbonImmutable|null $finalizado_at
 * @property string|null $error
 */
class ProcesoCobro extends Model
{
    protected $table = 'procesos_cobro';

    protected $fillable = ['cobro_id', 'usuario_id', 'caja_id', 'documento', 'liberar', 'fiscal', 'clave_emision', 'clave_impresion', 'comprobante_id', 'trabajo_impresion_id', 'finalizado_at', 'error'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['liberar' => 'boolean', 'fiscal' => 'array', 'finalizado_at' => 'immutable_datetime'];
    }
}
