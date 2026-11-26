<?php

namespace App\Models;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoSunatComprobante;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $entorno
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $serie_comprobante_id
 * @property int $correlativo
 * @property int|null $cobro_id
 * @property int|null $comprobante_referencia_id
 * @property int|null $devolucion_id
 * @property int $usuario_id
 * @property CarbonImmutable $fecha_emision
 * @property string $moneda
 * @property EstadoComprobante $estado
 * @property EstadoSunatComprobante $estado_sunat
 * @property array<string, mixed> $emisor
 * @property array<string, mixed> $receptor
 * @property array<string, mixed> $configuracion_tributaria
 * @property numeric-string $valor_venta
 * @property numeric-string $descuento
 * @property numeric-string $operaciones_gratuitas
 * @property numeric-string $impuestos
 * @property numeric-string $total
 * @property string|null $codigo_motivo_nota
 * @property string|null $descripcion_motivo_nota
 * @property string|null $xml_ruta
 * @property string|null $cdr_ruta
 * @property string|null $pdf_ruta
 * @property string|null $hash_documento
 * @property string $clave_idempotencia
 */
class Comprobante extends Model
{
    protected $table = 'comprobantes';

    protected $fillable = [
        'entorno',
        'serie_comprobante_id',
        'correlativo',
        'cobro_id',
        'comprobante_referencia_id',
        'devolucion_id',
        'usuario_id',
        'fecha_emision',
        'moneda',
        'estado',
        'estado_sunat',
        'emisor',
        'receptor',
        'configuracion_tributaria',
        'valor_venta',
        'descuento',
        'operaciones_gratuitas',
        'impuestos',
        'total',
        'codigo_motivo_nota',
        'descripcion_motivo_nota',
        'xml_ruta',
        'cdr_ruta',
        'pdf_ruta',
        'hash_documento',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'immutable_date',
            'estado' => EstadoComprobante::class,
            'estado_sunat' => EstadoSunatComprobante::class,
            'emisor' => 'array',
            'receptor' => 'array',
            'configuracion_tributaria' => 'array',
            'valor_venta' => 'decimal:2',
            'descuento' => 'decimal:2',
            'operaciones_gratuitas' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SerieComprobante, $this> */
    public function serie(): BelongsTo
    {
        return $this->belongsTo(SerieComprobante::class, 'serie_comprobante_id');
    }

    /** @return BelongsTo<Cobro, $this> */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class, 'cobro_id');
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_referencia_id');
    }

    /** @return HasMany<ComprobanteDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(ComprobanteDetalle::class, 'comprobante_id');
    }

    /** @return HasMany<ComprobanteEnvio, $this> */
    public function envios(): HasMany
    {
        return $this->hasMany(ComprobanteEnvio::class, 'comprobante_id');
    }

    /** @return HasOne<ResumenDiarioDetalle, $this> */
    public function resumenActivo(): HasOne
    {
        return $this->hasOne(ResumenDiarioDetalle::class, 'comprobante_activo_id');
    }

    /** @return HasMany<ResumenDiarioDetalle, $this> */
    public function resumenesDetalles(): HasMany
    {
        return $this->hasMany(ResumenDiarioDetalle::class);
    }
}
