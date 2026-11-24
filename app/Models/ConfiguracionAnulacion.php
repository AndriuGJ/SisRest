<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionAnulacion extends Model
{
    protected $table = 'configuracion_anulacion';

    protected $fillable = [
        'singleton',
        'clave_hash',
    ];

    // Evita exponer el hash al convertir el modelo a JSON.
    protected $hidden = [
        'clave_hash',
    ];
}
