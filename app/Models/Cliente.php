<?php

namespace App\Models;

use App\Enums\TipoCliente;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $clave_registro
 * @property int|null $creado_por
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $tipo_documento_identidad_id
 * @property string|null $numero_documento
 * @property TipoCliente $tipo
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 */
class Cliente extends Model
{
    protected $table = 'clientes';

    protected $fillable = [
        'clave_registro', 'creado_por',
        'tipo_documento_identidad_id',
        'numero_documento',
        'tipo',
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
            'tipo' => TipoCliente::class,
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<TipoDocumentoIdentidad, $this> */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoIdentidad::class, 'tipo_documento_identidad_id');
    }
}
