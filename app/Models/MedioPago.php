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
 * @property bool $afecta_efectivo
 * @property bool $requiere_referencia
 * @property bool $activo
 */
class MedioPago extends Model
{
    protected $table = 'medios_pago';

    protected $fillable = [
        'codigo',
        'nombre',
        'afecta_efectivo',
        'requiere_referencia',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'afecta_efectivo' => 'boolean',
            'requiere_referencia' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}
