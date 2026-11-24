<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property bool $electronico
 * @property bool $activo
 */
class TipoComprobante extends Model
{
    protected $table = 'tipos_comprobante';

    protected $fillable = [
        'codigo',
        'nombre',
        'electronico',
        'activo',
        'disponible_venta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'electronico' => 'boolean',
            'activo' => 'boolean',
            'disponible_venta' => 'boolean',
        ];
    }
}
