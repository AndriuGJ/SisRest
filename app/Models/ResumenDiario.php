<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int|null $usuario_id
 * @property int|null $envio_historico_id
 * @property string $clave_idempotencia
 * @property string $entorno
 * @property string $nombre
 * @property int $correlativo
 * @property CarbonImmutable $fecha_emision
 * @property CarbonImmutable $fecha_generacion
 * @property string $moneda
 * @property numeric-string $total
 * @property array<string,mixed> $emisor
 * @property string $estado
 * @property string|null $ticket
 * @property string|null $xml_ruta
 * @property string|null $xml_sha256
 * @property string|null $cdr_ruta
 * @property string|null $cdr_recibido_ruta
 * @property string|null $cdr_recibido_sha256
 * @property string|null $codigo_respuesta
 * @property string|null $mensaje
 * @property array<string,mixed>|null $diagnostico
 * @property CarbonImmutable|null $envio_iniciado_at
 * @property CarbonImmutable|null $envio_finalizado_at
 * @property bool $consultando
 * @property CarbonImmutable|null $consulta_iniciada_at
 * @property CarbonImmutable|null $consulta_finalizada_at
 */
class ResumenDiario extends Model
{
    protected $table = 'resumenes_diarios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_emision' => 'immutable_date', 'fecha_generacion' => 'immutable_date', 'total' => 'decimal:2', 'emisor' => 'array', 'diagnostico' => 'array', 'consultando' => 'boolean', 'envio_iniciado_at' => 'immutable_datetime', 'envio_finalizado_at' => 'immutable_datetime', 'consulta_iniciada_at' => 'immutable_datetime', 'consulta_finalizada_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ResumenDiarioDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(ResumenDiarioDetalle::class)->orderBy('linea');
    }

    /** @return HasMany<ResumenDiarioEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(ResumenDiarioEvento::class)->orderByDesc('id');
    }

    /** @return BelongsTo<ComprobanteEnvio, $this> */
    public function envioHistorico(): BelongsTo
    {
        return $this->belongsTo(ComprobanteEnvio::class, 'envio_historico_id');
    }
}
