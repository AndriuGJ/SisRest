<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotivoAnulacion extends Model
{
    protected $table = 'motivos_anulacion';

    protected $fillable = [
        'codigo',
        'nombre',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }
}
