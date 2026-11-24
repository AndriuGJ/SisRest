<?php

namespace Database\Seeders;

use App\Models\MotivoAnulacion;
use Illuminate\Database\Seeder;

class MotivosAnulacionSeeder extends Seeder
{
    public function run(): void
    {
        $motivos = [
            ['codigo' => 'error_registro', 'nombre' => 'Error al registrar el pedido'],
            ['codigo' => 'solicitud_cliente', 'nombre' => 'Solicitud del cliente'],
            ['codigo' => 'producto_agotado', 'nombre' => 'Producto agotado'],
            ['codigo' => 'pedido_duplicado', 'nombre' => 'Pedido duplicado'],
            ['codigo' => 'cambio_cliente', 'nombre' => 'Cambio solicitado por el cliente'],
            ['codigo' => 'otro', 'nombre' => 'Otro motivo'],
        ];

        foreach ($motivos as $orden => $datos) {
            MotivoAnulacion::firstOrCreate(
                ['codigo' => $datos['codigo']],
                [
                    'nombre' => $datos['nombre'],
                    'orden' => $orden + 1,
                    'activo' => true,
                ]
            );
        }
    }
}
