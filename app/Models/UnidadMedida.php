<?php

namespace App\Models;

use App\Enums\MagnitudUnidadMedida;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property MagnitudUnidadMedida $magnitud
 * @property numeric-string $factor_base
 * @property string|null $codigo_sunat
 * @property bool $activo
 */
class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $fillable = [
        'codigo',
        'nombre',
        'magnitud',
        'factor_base',
        'codigo_sunat',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'magnitud' => MagnitudUnidadMedida::class,
            'factor_base' => 'decimal:6',
            'activo' => 'boolean',
        ];
    }
}
