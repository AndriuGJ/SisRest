<?php

namespace App\Models;

use App\Enums\TipoMotivoBeneficio;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property TipoMotivoBeneficio $tipo
 * @property bool $activo
 */
class MotivoBeneficio extends Model
{
    protected $table = 'motivos_beneficio';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMotivoBeneficio::class,
            'activo' => 'boolean',
        ];
    }
}
