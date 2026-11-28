<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $comprobante_id
 * @property int|null $cobro_detalle_id
 * @property string $codigo_producto
 * @property string $descripcion
 * @property string $unidad_sunat
 * @property numeric-string $cantidad
 * @property numeric-string $valor_unitario
 * @property numeric-string $precio_unitario
 * @property numeric-string $valor_referencial
 * @property string $tipo_afectacion_igv
 * @property numeric-string $tasa_impuesto
 * @property numeric-string $base_imponible
 * @property numeric-string $descuento
 * @property numeric-string $impuestos
 * @property numeric-string $total
 */
class ComprobanteDetalle extends Model
{
    protected $table = 'comprobante_detalles';

    protected $fillable = [
        'comprobante_id',
        'cobro_detalle_id',
        'codigo_producto',
        'descripcion',
        'unidad_sunat',
        'cantidad',
        'valor_unitario',
        'precio_unitario',
        'valor_referencial',
        'tipo_afectacion_igv',
        'tasa_impuesto',
        'base_imponible',
        'descuento',
        'impuestos',
        'total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'valor_unitario' => 'decimal:6',
            'precio_unitario' => 'decimal:6',
            'valor_referencial' => 'decimal:6',
            'tasa_impuesto' => 'decimal:4',
            'base_imponible' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    /** @return BelongsTo<CobroDetalle, $this> */
    public function detalleCobro(): BelongsTo
    {
        return $this->belongsTo(CobroDetalle::class, 'cobro_detalle_id');
    }
}
