<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $resumen_diario_id
 * @property int|null $usuario_id
 * @property string $operacion
 * @property string $estado
 * @property string|null $codigo
 * @property string|null $mensaje
 * @property array<string,mixed>|null $diagnostico
 * @property CarbonImmutable $created_at
 */
class ResumenDiarioEvento extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['diagnostico' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
