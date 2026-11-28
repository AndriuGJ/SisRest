<?php

use App\Models\ConfiguracionAnulacion;
use App\Models\MotivoAnulacion;
use Illuminate\Support\Facades\Hash;

/** @return array{motivoId:int,claveAutorizacion:string} */
function autorizacionAnulacionPrueba(string $nombre): array
{
    ConfiguracionAnulacion::firstOrCreate(['singleton' => 1], ['clave_hash' => Hash::make('804216')]);
    $motivo = MotivoAnulacion::firstOrCreate(['codigo' => 'test_'.substr(hash('sha256', $nombre), 0, 30)], ['nombre' => $nombre, 'orden' => 1, 'activo' => true]);

    return ['motivoId' => $motivo->id, 'claveAutorizacion' => '804216'];
}
