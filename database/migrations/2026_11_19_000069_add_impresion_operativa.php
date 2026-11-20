<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trabajos_impresion', function (Blueprint $t) {
            $t->enum('tipo', ['comanda', 'precuenta', 'comprobante', 'ticket'])->change();
            $t->foreignId('pedido_id')->nullable()->constrained('pedidos')->restrictOnDelete();
            $t->json('solicitud')->nullable();
            $t->json('configuracion')->nullable();
            $t->string('usuario_nombre')->nullable();
            $t->text('motivo_reimpresion')->nullable();
            $t->string('clave_documento', 120)->nullable()->unique();
            $t->index(['pedido_id', 'estado']);
        });
        $permisos = ['impresion.comandas', 'impresion.precuentas', 'impresion.tickets', 'impresion.reimprimir'];
        foreach ($permisos as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['administrador', 'cajero', 'mozo'])->get() as $rol) {
            if ($rol->name === 'administrador') {
                $rol->givePermissionTo($permisos);
            } elseif ($rol->hasPermissionTo('pos.ver')) {
                if ($rol->hasPermissionTo('pedidos.enviar')) {
                    $rol->givePermissionTo(['impresion.comandas', 'impresion.precuentas']);
                }
                if ($rol->name === 'cajero' && $rol->hasPermissionTo('cobros.crear')) {
                    $rol->givePermissionTo('impresion.tickets');
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('trabajos_impresion')->where('tipo', 'ticket')->exists()) {
            throw new RuntimeException('Conserva los tickets y su historial antes de revertir esta migración.');
        }
        Schema::table('trabajos_impresion', function (Blueprint $t) {
            $t->dropForeign(['pedido_id']);
            $t->dropIndex(['pedido_id', 'estado']);
            $t->dropColumn('pedido_id');
            $t->dropUnique(['clave_documento']);
            $t->dropColumn(['solicitud', 'configuracion', 'usuario_nombre', 'motivo_reimpresion', 'clave_documento']);
            $t->enum('tipo', ['comanda', 'precuenta', 'comprobante'])->change();
        });
        Permission::where('guard_name', 'web')->whereIn('name', ['impresion.comandas', 'impresion.precuentas', 'impresion.tickets', 'impresion.reimprimir'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
