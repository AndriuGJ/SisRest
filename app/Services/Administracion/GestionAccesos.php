<?php

namespace App\Services\Administracion;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GestionAccesos
{
    public const ROLES_SISTEMA = ['administrador', 'cajero', 'mozo', 'cocina'];

    /**
     * @param  array{name: string, username: string, email: string, roleId: string, activo: bool, password: string, password_confirmation: string}  $datos
     */
    public function guardarUsuario(User $actor, ?int $id, array $datos): User
    {
        Gate::forUser($actor)->authorize('usuarios.gestionar');
        $datos['name'] = trim($datos['name']);
        $datos['username'] = Str::lower(trim($datos['username']));
        $datos['email'] = Str::lower(trim($datos['email']));

        return $this->transaccion(function () use ($actor, $id, $datos): User {
            $this->autorizar($actor, 'usuarios.gestionar');
            $usuario = $id ? User::query()->findOrFail($id) : new User;
            if ($id) {
                $this->puedeAdministrar($actor, $usuario);
            }

            $validado = Validator::make($datos, [
                'name' => ['required', 'string', 'max:150'],
                'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique('users', 'username')->ignore($id)],
                'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
                'roleId' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
                'activo' => ['required', 'boolean'],
                'password' => $id ? ['prohibited'] : ['required', 'confirmed', Password::min(12)],
            ], [
                'username.regex' => 'Usa entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo.',
            ], ['name' => 'nombre', 'username' => 'usuario', 'email' => 'correo', 'roleId' => 'rol', 'password' => 'contraseña'])->validate();

            $rol = Role::query()->where('guard_name', 'web')->whereKey($validado['roleId'])->firstOrFail();
            $this->puedeOtorgar($actor, $rol->permissions);
            if ($id === $actor->id) {
                if (! $validado['activo']) {
                    throw ValidationException::withMessages(['activo' => 'No puedes desactivar tu propia cuenta.']);
                }
                if ($usuario->roles()->count() !== 1 || ! $usuario->hasRole($rol)) {
                    throw ValidationException::withMessages(['roleId' => 'Otro administrador debe realizar el cambio de tu rol.']);
                }
            }

            $rolesAnteriores = $usuario->exists ? $usuario->getRoleNames()->all() : [];
            $usuario->fill([
                'name' => $validado['name'],
                'username' => $validado['username'],
                'email' => $validado['email'] ?: null,
                'activo' => $validado['activo'],
            ]);
            if (! $id) {
                $usuario->password = Hash::make($validado['password']);
            } elseif ($usuario->isDirty('email')) {
                $usuario->email_verified_at = null;
            }
            $usuario->save();
            $usuario->syncRoles($rol);
            if (! $usuario->activo) {
                $this->revocarSesiones($usuario);
            }
            $this->conservarAdministrador();
            activity()->causedBy($actor)->performedOn($usuario)->event('accesos_actualizados')
                ->withProperties(['roles_anteriores' => $rolesAnteriores, 'roles_actuales' => [$rol->name]])
                ->log($id ? 'Usuario actualizado' : 'Usuario registrado');

            return $usuario;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $activo): User
    {
        Gate::forUser($actor)->authorize('usuarios.gestionar');

        return $this->transaccion(function () use ($actor, $id, $activo): User {
            $this->autorizar($actor, 'usuarios.gestionar');
            $usuario = User::query()->findOrFail($id);
            $this->puedeAdministrar($actor, $usuario);
            if ($actor->id === $id && ! $activo) {
                throw ValidationException::withMessages(['seguridad' => 'No puedes desactivar tu propia cuenta.']);
            }
            $usuario->update(['activo' => $activo]);
            if (! $activo) {
                $this->revocarSesiones($usuario);
            }
            $this->conservarAdministrador();

            return $usuario;
        });
    }

    public function restablecerPassword(User $actor, int $id, string $password, string $confirmacion): void
    {
        Gate::forUser($actor)->authorize('usuarios.gestionar');
        $validado = Validator::make([
            'newPassword' => $password, 'newPassword_confirmation' => $confirmacion,
        ], [
            'newPassword' => ['required', 'confirmed', Password::min(12)],
        ], [], ['newPassword' => 'nueva contraseña'])->validate();

        $this->transaccion(function () use ($actor, $id, $validado): void {
            $this->autorizar($actor, 'usuarios.gestionar');
            $usuario = User::query()->findOrFail($id);
            $this->puedeAdministrar($actor, $usuario);
            $usuario->update(['password' => Hash::make($validado['newPassword'])]);
            $this->revocarSesiones($usuario);
            activity()->causedBy($actor)->performedOn($usuario)->event('password_restablecido')
                ->log('Contraseña restablecida por un administrador');
        });
    }

    /** @param list<string> $permisos */
    public function guardarRol(User $actor, ?int $id, string $nombre, array $permisos): Role
    {
        Gate::forUser($actor)->authorize('roles.gestionar');

        return $this->transaccion(function () use ($actor, $id, $nombre, $permisos): Role {
            $this->autorizar($actor, 'roles.gestionar');
            $rol = $id ? Role::query()->where('guard_name', 'web')->findOrFail($id) : new Role;
            if ($id) {
                $this->puedeOtorgar($actor, $rol->permissions);
            }
            $nombre = Str::lower(trim($nombre));
            if ($id && in_array($rol->name, self::ROLES_SISTEMA, true) && $nombre !== $rol->name) {
                throw ValidationException::withMessages(['name' => 'El nombre de un rol del sistema se conserva. Puedes ajustar sus permisos.']);
            }
            $validado = Validator::make(['name' => $nombre, 'permissions' => $permisos], [
                'name' => [
                    'required', 'string', 'regex:/^[a-z0-9_-]{3,50}$/',
                    Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($id),
                ],
                'permissions' => ['present', 'array', 'list'],
                'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            ], ['name.regex' => 'Usa entre 3 y 50 caracteres: letras, números, guion o guion bajo.'],
                ['name' => 'nombre del rol', 'permissions' => 'permisos'])->validate();
            $this->puedeOtorgar($actor, Permission::query()->where('guard_name', 'web')->whereIn('name', $validado['permissions'])->get());
            $anteriores = $rol->exists ? $rol->permissions->pluck('name')->all() : [];
            $rol->fill(['name' => $validado['name'], 'guard_name' => 'web'])->save();
            $rol->syncPermissions($validado['permissions']);
            $this->conservarAdministrador();
            activity()->causedBy($actor)->performedOn($rol)->event('permisos_actualizados')
                ->withProperties(['permisos_anteriores' => $anteriores, 'permisos_actuales' => $validado['permissions']])
                ->log($id ? 'Permisos del rol actualizados' : 'Rol registrado');

            return $rol;
        });
    }

    private function autorizar(User $actor, string $permiso): void
    {
        $actor->refresh()->unsetRelations();
        abort_unless($actor->activo, 403);
        Gate::forUser($actor)->authorize($permiso);
    }

    private function puedeAdministrar(User $actor, User $usuario): void
    {
        $this->puedeOtorgar($actor, $usuario->getAllPermissions());
    }

    /** @param iterable<Permission> $permisos */
    private function puedeOtorgar(User $actor, iterable $permisos): void
    {
        // Complete access administrators must also be able to restore an optional
        // permission removed from their own role. Delegated managers remain scoped.
        if (self::tieneGestionCompleta($actor)) {
            return;
        }
        foreach ($permisos as $permiso) {
            abort_unless($actor->can($permiso->name), 403, 'No puedes administrar accesos superiores a los de tu cuenta.');
        }
    }

    public static function tieneGestionCompleta(User $usuario): bool
    {
        return $usuario->can('usuarios.gestionar') && $usuario->can('roles.gestionar');
    }

    private function conservarAdministrador(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $disponible = User::query()->where('activo', true)->get()->contains(
            fn (User $usuario): bool => $usuario->can('usuarios.gestionar') && $usuario->can('roles.gestionar'),
        );
        if (! $disponible) {
            throw ValidationException::withMessages([
                'seguridad' => 'Debe quedar al menos un usuario activo con permisos para administrar usuarios y roles.',
            ]);
        }
    }

    private function revocarSesiones(User $usuario): void
    {
        $usuario->forceFill(['remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $usuario->id)->delete();
    }

    /**
     * Serialize access changes, including simultaneous changes to the last administrators.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    private function transaccion(Closure $operacion): mixed
    {
        try {
            return DB::transaction(function () use ($operacion) {
                Role::query()->where('guard_name', 'web')->orderBy('id')->lockForUpdate()->get();
                User::query()->orderBy('id')->lockForUpdate()->get();

                return $operacion();
            }, 3);
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
