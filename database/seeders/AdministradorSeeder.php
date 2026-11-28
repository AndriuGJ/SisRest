<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $username = strtolower(trim((string) config('restaurante.administrador.usuario')));

        if (! preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            throw new RuntimeException('ADMIN_USERNAME debe tener entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo.');
        }

        $usuario = User::query()->where('username', $username)->first();

        if ($usuario) {
            if (! $usuario->hasRole('administrador')) {
                throw new RuntimeException('ADMIN_USERNAME ya pertenece a otra cuenta. No se modificaron sus permisos.');
            }

            return;
        }

        $password = (string) config('restaurante.administrador.password');

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('Define ADMIN_PASSWORD con al menos 12 caracteres en .env antes de ejecutar db:seed.');
        }

        $usuario = User::create([
            'name' => (string) config('restaurante.administrador.nombre'),
            'username' => $username,
            'email' => config('restaurante.administrador.correo') ?: null,
            'password' => Hash::make($password),
            'activo' => true,
        ]);

        $usuario->assignRole('administrador');
    }
}
