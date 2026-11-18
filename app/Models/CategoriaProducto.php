<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $nombre
 * @property int $orden
 * @property bool $activo
 */
class CategoriaProducto extends Model
{
    protected $table = 'categorias_producto';

    protected $fillable = [
        'nombre',
        'orden',
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
