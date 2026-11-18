<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $tipo_comprobante_id
 * @property string $serie
 * @property int $ultimo_correlativo
 * @property bool $activo
 */
class SerieComprobante extends Model
{
    protected $table = 'series_comprobante';

    protected $fillable = [
        'tipo_comprobante_id',
        'serie',
        'ultimo_correlativo',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<TipoComprobante, $this> */
    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class, 'tipo_comprobante_id');
    }
}
