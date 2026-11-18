<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $tipo_documento_identidad_id
 * @property string|null $numero_documento
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 */
class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = [
        'tipo_documento_identidad_id',
        'numero_documento',
        'nombre',
        'direccion',
        'telefono',
        'correo',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<TipoDocumentoIdentidad, $this> */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoIdentidad::class, 'tipo_documento_identidad_id');
    }
}
