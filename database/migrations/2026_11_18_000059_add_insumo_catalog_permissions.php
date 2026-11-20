<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $anterior = DB::table('permissions')->where('name', 'inventario.ajustar')->where('guard_name', 'web')->value('id');
        foreach (['insumos.crear', 'insumos.editar'] as $nombre) {
            DB::table('permissions')->insertOrIgnore(['name' => $nombre, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $nuevo = DB::table('permissions')->where('name', $nombre)->where('guard_name', 'web')->value('id');
            if ($anterior !== null) {
                foreach (DB::table('role_has_permissions')->where('permission_id', $anterior)->get() as $fila) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $nuevo, 'role_id' => $fila->role_id]);
                }
                foreach (DB::table('model_has_permissions')->where('permission_id', $anterior)->get() as $fila) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $nuevo, 'model_type' => $fila->model_type, 'model_id' => $fila->model_id]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['insumos.crear', 'insumos.editar'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
