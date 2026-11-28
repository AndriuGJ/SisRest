<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property int|null $longitud
 * @property bool $activo
 */
class TipoDocumentoIdentidad extends Model
{
    protected $table = 'tipos_documento_identidad';

    protected $fillable = [
        'codigo',
        'nombre',
        'longitud',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
