<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $singleton
 * @property string|null $ruc
 * @property string|null $razon_social
 * @property string|null $nombre_comercial
 * @property string|null $direccion
 * @property string|null $ubigeo
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $logo_ruta
 * @property string $moneda
 * @property string $zona_horaria
 * @property bool $precios_incluyen_impuestos
 */
class Empresa extends Model
{
    protected $table = 'empresa';

    protected $fillable = [
        'singleton',
        'ruc',
        'razon_social',
        'nombre_comercial',
        'direccion',
        'ubigeo',
        'telefono',
        'correo',
        'logo_ruta',
        'moneda',
        'zona_horaria',
        'precios_incluyen_impuestos',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precios_incluyen_impuestos' => 'boolean',
        ];
    }
}
