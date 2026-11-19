<?php

namespace App\Models;

use App\Enums\EntornoConfiguracionFacturacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property CarbonImmutable|null $vigente_hasta
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $empresa_id
 * @property bool $habilitada
 * @property EntornoConfiguracionFacturacion $entorno
 * @property string|null $usuario_sol
 * @property string|null $clave_sol
 * @property string|null $clave_certificado
 * @property string|null $certificado_ruta
 * @property numeric-string|null $tasa_impuesto
 * @property CarbonImmutable|null $vigente_desde
 */
class ConfiguracionFacturacion extends Model
{
    use LogsActivity;

    protected $table = 'configuraciones_facturacion';

    protected $fillable = [
        'vigente_hasta',

        'empresa_id',
        'habilitada',
        'entorno',
        'usuario_sol',
        'clave_sol',
        'clave_certificado',
        'certificado_ruta',
        'tasa_impuesto',
        'vigente_desde',
    ];

    protected $hidden = ['usuario_sol', 'clave_sol', 'clave_certificado', 'certificado_ruta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vigente_hasta' => 'immutable_date',

            'habilitada' => 'boolean',
            'entorno' => EntornoConfiguracionFacturacion::class,
            'tasa_impuesto' => 'decimal:4',
            'vigente_desde' => 'immutable_date',
            'usuario_sol' => 'encrypted',
            'clave_sol' => 'encrypted',
            'clave_certificado' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['habilitada', 'entorno', 'tasa_impuesto', 'vigente_desde'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
