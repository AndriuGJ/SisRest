<?php

namespace App\Models;

use App\Enums\EstadoTrabajoImpresion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $impresora_id
 * @property int $usuario_id
 * @property array{nombre: string, host: string, puerto: int, ancho_mm: int} $configuracion
 * @property EstadoTrabajoImpresion $estado
 * @property string $mensaje
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PruebaImpresion extends Model
{
    protected $table = 'pruebas_impresion';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'impresora_id', 'usuario_id', 'configuracion', 'estado', 'mensaje'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuracion' => 'array', 'estado' => EstadoTrabajoImpresion::class];
    }

    /** @return BelongsTo<Impresora, $this> */
    public function impresora(): BelongsTo
    {
        return $this->belongsTo(Impresora::class);
    }
}
