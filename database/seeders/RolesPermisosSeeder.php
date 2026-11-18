<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermisosSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permisos = [
            'dashboard.ver',
            'pos.ver', 'pedidos.ver_propios', 'pedidos.ver_todos', 'pedidos.crear',
            'pedidos.editar', 'pedidos.enviar', 'pedidos.anular_enviados', 'mesas.liberar',
            'cocina.ver', 'cocina.preparar', 'cocina.marcar_listo', 'entregas.confirmar',
            'delivery.gestionar', 'cobros.crear', 'descuentos.aplicar', 'cortesias.aplicar',
            'devoluciones.crear', 'clientes.ver', 'clientes.crear', 'clientes.editar',
            'productos.ver', 'productos.crear', 'productos.editar',
            'inventario.ver', 'inventario.ajustar', 'inventario.registrar_merma',
            'inventario.registrar_consumo_interno', 'costos.ver',
            'recetas.gestionar', 'preparaciones.ver', 'preparaciones.crear',
            'preparaciones.confirmar', 'preparaciones.ajustar_consumos',
            'compras.ver', 'compras.crear', 'compras.confirmar', 'proveedores.gestionar',
            'pagos_proveedor.registrar', 'cajas.ver_propias', 'cajas.ver_todas',
            'cajas.abrir', 'cajas.cerrar', 'cajas.arquear', 'cajas.registrar_ingreso',
            'cajas.registrar_egreso', 'cajas.retirar',
            'comprobantes.ver', 'comprobantes.emitir', 'comprobantes.reenviar',
            'comprobantes.notas_credito', 'reportes.ver_propios', 'reportes.ver_todos',
            'reportes.exportar', 'usuarios.gestionar', 'roles.gestionar',
            'configuracion.sistema', 'configuracion.restaurante', 'impresoras.gestionar',
            'auditoria.ver',
        ];

        foreach ($permisos as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        // DatabaseSeeder suppresses model events, so newly created permissions need a cache reset.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $porRol = [
            'administrador' => $permisos,
            'cajero' => [
                'dashboard.ver', 'pos.ver', 'pedidos.ver_todos', 'pedidos.crear',
                'pedidos.editar', 'pedidos.enviar', 'mesas.liberar', 'entregas.confirmar',
                'delivery.gestionar', 'cobros.crear', 'clientes.ver', 'clientes.crear',
                'clientes.editar', 'productos.ver', 'cajas.ver_propias', 'cajas.abrir',
                'cajas.cerrar', 'cajas.arquear', 'cajas.registrar_ingreso',
                'cajas.registrar_egreso', 'comprobantes.ver', 'comprobantes.emitir',
                'reportes.ver_propios', 'reportes.exportar',
            ],
            'mozo' => [
                'pos.ver', 'pedidos.ver_propios', 'pedidos.crear', 'pedidos.editar',
                'pedidos.enviar', 'mesas.liberar', 'productos.ver', 'entregas.confirmar',
            ],
            'cocina' => ['cocina.ver', 'cocina.preparar', 'cocina.marcar_listo'],
        ];

        foreach ($porRol as $nombre => $permisosRol) {
            $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

            // Re-running catalogs must not undo an administrator's permission changes.
            if ($rol->wasRecentlyCreated) {
                $rol->syncPermissions($permisosRol);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
