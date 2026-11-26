<?php

namespace App\Models;

use App\Enums\EstadoComprobanteEnvio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $diagnostico_envio
 * @property array<string, mixed>|null $diagnostico_consulta
 * @property string|null $cdr_recibido_ruta
 * @property string|null $cdr_recibido_sha256
 * @property string|null $xml_nombre
 * @property string|null $xml_ruta
 * @property string|null $xml_sha256
 * @property int|null $usuario_id
 * @property CarbonImmutable|null $consulta_iniciada_at
 * @property CarbonImmutable|null $consulta_finalizada_at
 * @property bool $consultando
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $comprobante_id
 * @property int $intento
 * @property EstadoComprobanteEnvio $estado
 * @property string|null $ticket
 * @property string|null $codigo_respuesta
 * @property string|null $mensaje
 * @property string|null $cdr_ruta
 * @property CarbonImmutable|null $iniciado_at
 * @property CarbonImmutable|null $finalizado_at
 */
class ComprobanteEnvio extends Model
{
    protected $table = 'comprobante_envios';

    protected $fillable = [
        'diagnostico_envio',
        'diagnostico_consulta',
        'cdr_recibido_ruta',
        'cdr_recibido_sha256',
        'xml_nombre',
        'xml_ruta',
        'xml_sha256',
        'usuario_id',
        'consultando',
        'consulta_iniciada_at',
        'consulta_finalizada_at',

        'comprobante_id',
        'intento',
        'estado',
        'ticket',
        'codigo_respuesta',
        'mensaje',
        'cdr_ruta',
        'iniciado_at',
        'finalizado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diagnostico_envio' => 'array',
            'diagnostico_consulta' => 'array',
            'consultando' => 'boolean',
            'consulta_iniciada_at' => 'immutable_datetime',
            'consulta_finalizada_at' => 'immutable_datetime',

            'estado' => EstadoComprobanteEnvio::class,
            'iniciado_at' => 'immutable_datetime',
            'finalizado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }
}
