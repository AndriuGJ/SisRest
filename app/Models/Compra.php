<?php

namespace App\Models;

use App\Enums\CondicionPagoCompra;
use App\Enums\EstadoCompra;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string|null $clave_registro
 * @property string|null $proveedor_nombre
 * @property string|null $proveedor_documento
 * @property string|null $documento_nombre
 * @property int|null $confirmada_por
 * @property int|null $anulada_por
 * @property CarbonImmutable|null $anulada_at
 * @property string|null $motivo_anulacion
 * @property string $codigo
 * @property int $proveedor_id
 * @property int $usuario_id
 * @property int|null $tipo_comprobante_id
 * @property string|null $serie
 * @property string|null $numero
 * @property CarbonImmutable $fecha
 * @property CarbonImmutable|null $fecha_vencimiento
 * @property CondicionPagoCompra $condicion_pago
 * @property EstadoCompra $estado
 * @property string $moneda
 * @property numeric-string $subtotal
 * @property numeric-string $descuento
 * @property numeric-string $impuestos
 * @property numeric-string $total
 * @property CarbonImmutable|null $confirmada_at
 * @property string|null $observaciones
 */
class Compra extends Model
{
    use LogsActivity;

    protected $table = 'compras';

    protected $fillable = [
        'clave_registro', 'proveedor_nombre', 'proveedor_documento', 'documento_nombre', 'confirmada_por', 'anulada_por', 'anulada_at', 'motivo_anulacion',
        'codigo',
        'proveedor_id',
        'usuario_id',
        'tipo_comprobante_id',
        'serie',
        'numero',
        'fecha',
        'fecha_vencimiento',
        'condicion_pago',
        'estado',
        'moneda',
        'subtotal',
        'descuento',
        'impuestos',
        'total',
        'confirmada_at',
        'observaciones',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anulada_at' => 'immutable_datetime',
            'fecha' => 'immutable_date',
            'fecha_vencimiento' => 'immutable_date',
            'condicion_pago' => CondicionPagoCompra::class,
            'estado' => EstadoCompra::class,
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
            'confirmada_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<CompraDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class, 'compra_id');
    }

    /** @return HasMany<PagoCompra, $this> */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoCompra::class, 'compra_id');
    }

    /** @return numeric-string */
    public function saldoPendiente(): string
    {
        $pagado = '0.00';
        foreach (($this->relationLoaded('pagos') ? $this->pagos : $this->pagos()->get()) as $pago) {
            $pagado = bcadd($pagado, $pago->monto, 2);
        }

        return bcsub($this->total, $pagado, 2);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'proveedor_id', 'usuario_id', 'tipo_comprobante_id', 'serie', 'numero', 'fecha', 'fecha_vencimiento', 'condicion_pago', 'estado', 'moneda', 'subtotal', 'descuento', 'impuestos', 'total', 'confirmada_at', 'observaciones'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
