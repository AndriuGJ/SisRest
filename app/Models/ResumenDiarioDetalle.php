<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $resumen_diario_id
 * @property int $comprobante_id
 * @property int|null $comprobante_activo_id
 * @property int $linea
 * @property string $numero_fiscal
 */
class ResumenDiarioDetalle extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** @return BelongsTo<ResumenDiario, $this> */
    public function resumen(): BelongsTo
    {
        return $this->belongsTo(ResumenDiario::class, 'resumen_diario_id');
    }
}
