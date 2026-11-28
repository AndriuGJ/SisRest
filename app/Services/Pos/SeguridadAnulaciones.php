<?php

namespace App\Services\Pos;

use App\Models\ConfiguracionAnulacion;
use App\Models\MotivoAnulacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SeguridadAnulaciones
{
    /**
     * Configura o cambia la clave de autorización.
     */
    public function guardarClave(
        User $actor,
        #[\SensitiveParameter] string $claveNueva,
        #[\SensitiveParameter] ?string $claveActual = null
    ): void {
        // Solo usuarios autorizados para la configuración
        // sensible del sistema podrán cambiar la clave.
        Gate::forUser($actor)->authorize('configuracion.sistema');

        $usuario = User::findOrFail($actor->id);
        abort_unless($usuario->activo, 403);

        Validator::make(
            ['claveNueva' => $claveNueva],
            [
                'claveNueva' => [
                    'required',
                    'regex:/^[0-9]{6,12}$/',
                ],
            ],
            [
                'claveNueva.regex' => 'La clave debe contener entre 6 y 12 dígitos.',
            ]
        )->validate();

        DB::transaction(function () use (
            $actor,
            $claveActual,
            $claveNueva
        ) {
            $config = ConfiguracionAnulacion::query()
                ->where('singleton', 1)
                ->lockForUpdate()
                ->first();

            if (! $config) {
                $config = ConfiguracionAnulacion::create([
                    'singleton' => 1,
                    'clave_hash' => null,
                ]);
            }

            // Si ya existe una clave, exigir la anterior.
            if ($config->clave_hash !== null) {
                $this->verificarClave(
                    $actor,
                    (string) $claveActual
                );
            }

            $config->update([
                'clave_hash' => Hash::make($claveNueva),
            ]);

            // No incluir claves ni hashes en el historial.
            activity()
                ->causedBy($actor)
                ->performedOn($config)
                ->event('configuracion_restaurante')
                ->log('Clave de anulaciones configurada o actualizada');
        }, 3);
    }

    /**
     * Comprueba la clave y limita los intentos fallidos.
     */
    public function verificarClave(
        User $actor,
        #[\SensitiveParameter] string $claveAutorizacion
    ): void {
        $usuario = User::findOrFail($actor->id);
        abort_unless($usuario->activo, 403);

        $limite = 'anulaciones:intentos:'.$actor->id;

        if (RateLimiter::tooManyAttempts($limite, 5)) {
            throw ValidationException::withMessages([
                'claveAutorizacion' => 'Demasiados intentos incorrectos. Inténtalo más tarde.',
            ]);
        }

        $config = ConfiguracionAnulacion::query()
            ->where('singleton', 1)
            ->first();

        if (! $config || ! $config->clave_hash) {
            throw ValidationException::withMessages([
                'claveAutorizacion' => 'El administrador todavía no configuró la clave de anulaciones.',
            ]);
        }

        if (
            ! preg_match('/^[0-9]{6,12}$/', $claveAutorizacion)
            || ! Hash::check(
                $claveAutorizacion,
                $config->clave_hash
            )
        ) {
            // Máximo cinco intentos fallidos durante 15 minutos.
            RateLimiter::hit($limite, 900);

            throw ValidationException::withMessages([
                'claveAutorizacion' => 'La clave de autorización es incorrecta.',
            ]);
        }

        RateLimiter::clear($limite);
    }

    /**
     * Obtiene un motivo activo y válido.
     */
    public function motivoActivo(
        int|string $motivoId,
        bool $bloquear = false
    ): MotivoAnulacion {
        Validator::make(
            ['motivoId' => $motivoId],
            ['motivoId' => ['required', 'integer', 'min:1']]
        )->validate();

        $motivo = MotivoAnulacion::query()
            ->whereKey($motivoId)
            ->where('activo', true)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->first();

        if (! $motivo) {
            throw ValidationException::withMessages([
                'motivoId' => 'Selecciona un motivo de anulación activo.',
            ]);
        }

        return $motivo;
    }

    /**
     * Autoriza una anulación utilizando un motivo activo
     * y la clave configurada del restaurante.
     */
    public function autorizarAnulacion(
        User $actor,
        int|string $motivoId,
        string $motivoRevisado,
        #[\SensitiveParameter] string $claveAutorizacion
    ): string {
        $motivo = $this->motivoActivo($motivoId);

        // Evita confirmar una operación si el motivo
        // fue modificado después de revisar la anulación.
        if (! hash_equals($motivo->nombre, $motivoRevisado)) {
            throw ValidationException::withMessages([
                'motivoId' => 'El motivo de anulación cambió. Revisa nuevamente la operación.',
            ]);
        }

        // Verificación de la clave y límite de intentos.
        $this->verificarClave(
            $actor,
            $claveAutorizacion
        );

        return $motivo->nombre;
    }
}
