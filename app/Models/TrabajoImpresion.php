<?php

namespace App\Models;

use App\Enums\EstadoTrabajoImpresion;
use App\Enums\TipoTrabajoImpresion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $impresora_id
 * @property int $usuario_id
 * @property TipoTrabajoImpresion $tipo
 * @property array<string, mixed> $contenido
 * @property EstadoTrabajoImpresion $estado
 * @property int $intentos
 * @property int|null $reimpresion_de_id
 * @property string|null $ultimo_error
 * @property CarbonImmutable|null $enviado_at
 * @property string $clave_idempotencia
 * @property string $documento_type
 * @property int $documento_id
 */
class TrabajoImpresion extends Model
{
    protected $table = 'trabajos_impresion';

    protected $fillable = [
        'impresora_id',
        'usuario_id',
        'tipo',
        'contenido',
        'estado',
        'intentos',
        'reimpresion_de_id',
        'ultimo_error',
        'enviado_at',
        'clave_idempotencia',
        'documento_type',
        'documento_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoTrabajoImpresion::class,
            'contenido' => 'array',
            'estado' => EstadoTrabajoImpresion::class,
            'enviado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Impresora, $this> */
    public function impresora(): BelongsTo
    {
        return $this->belongsTo(Impresora::class, 'impresora_id');
    }

    /** @return BelongsTo<TrabajoImpresion, $this> */
    public function reimpresionDe(): BelongsTo
    {
        return $this->belongsTo(TrabajoImpresion::class, 'reimpresion_de_id');
    }

    /** @return MorphTo<Model, $this> */
    public function documento(): MorphTo
    {
        return $this->morphTo();
    }
}
