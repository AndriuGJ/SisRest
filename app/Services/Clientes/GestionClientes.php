<?php

namespace App\Services\Clientes;

use App\Enums\TipoCliente;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Pedido;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionClientes
{
    private const CAMPOS = ['tipo', 'nombre', 'tipo_documento_identidad_id', 'numero_documento', 'direccion', 'telefono', 'correo', 'activo'];

    /**
     * @template T
     *
     * @param  Closure():T  $operacion
     * @return T
     */
    private function transaccion(User $actor, string $permiso, Closure $operacion): mixed
    {
        Gate::forUser($actor)->authorize($permiso);
        try {
            return DB::transaction(function () use ($actor, $permiso, $operacion) {
                // Catálogo pequeño: serializa altas y cambios de identidad sin bloquear inventario/cajas.
                TipoDocumentoIdentidad::orderBy('id')->lockForUpdate()->get();
                $actual = User::findOrFail($actor->id);
                abort_unless($actual->activo, 403);
                Gate::forUser($actual)->authorize('clientes.ver');
                Gate::forUser($actual)->authorize($permiso);

                return $operacion();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero' => 'Este documento ya está registrado. Revisa el listado antes de crear otro cliente.']);
        }
    }

    /** @param array{tipo:string,nombre:string,tipoId:string,numero:string,direccion:string,telefono:string,correo:string,activo:bool} $datos */
    public function guardar(User $actor, ?int $id, string $clave, array $datos, string $version = ''): Cliente
    {
        return $this->transaccion($actor, $id ? 'clientes.editar' : 'clientes.crear', function () use ($actor, $id, $clave, $datos, $version): Cliente {
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if (! $id && ($existente = Cliente::where('clave_registro', $clave)->lockForUpdate()->first())) {
                abort_unless($existente->creado_por === $actor->id, 403);

                return $existente;
            }
            $cliente = $id ? Cliente::lockForUpdate()->findOrFail($id) : new Cliente;
            if ($id && ! hash_equals(self::huella($cliente), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El cliente cambió en otra sesión. Vuelve a abrirlo antes de guardar.']);
            }
            $datos['nombre'] = Str::squish($datos['nombre']);
            $datos['numero'] = Str::upper(trim($datos['numero']));
            $datos['direccion'] = trim($datos['direccion']);
            $datos['telefono'] = trim($datos['telefono']);
            $datos['correo'] = trim($datos['correo']);
            Validator::make($datos, [
                'tipo' => ['required', Rule::enum(TipoCliente::class)], 'nombre' => ['required', 'string', 'max:200'],
                'tipoId' => ['nullable', 'required_with:numero', 'required_if:tipo,empresa', 'integer', Rule::exists('tipos_documento_identidad', 'id')],
                'numero' => ['nullable', 'required_with:tipoId', 'required_if:tipo,empresa', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/',
                    Rule::unique('clientes', 'numero_documento')->where('tipo_documento_identidad_id', $datos['tipoId'] ?: null)->ignore($id)],
                'direccion' => ['nullable', 'required_if:tipo,empresa', 'string', 'max:250'],
                'telefono' => ['nullable', 'string', 'max:30'], 'correo' => ['nullable', 'email', 'max:150'], 'activo' => ['boolean'],
            ], ['direccion.required_if' => 'Indica la dirección de la empresa.'], ['nombre' => $datos['tipo'] === 'empresa' ? 'razón social' : 'nombres y apellidos',
                'tipoId' => 'tipo de documento', 'numero' => 'número de documento', 'direccion' => 'dirección', 'correo' => 'correo'])->validate();
            $tipo = $datos['tipoId'] !== '' ? TipoDocumentoIdentidad::findOrFail($datos['tipoId']) : null;
            $identidadCambia = $cliente->exists && ($cliente->tipo->value !== $datos['tipo'] || ($cliente->numero_documento !== null && ((string) ($cliente->tipo_documento_identidad_id ?? '') !== $datos['tipoId'] || $cliente->numero_documento !== $datos['numero'])));
            if ($identidadCambia && self::vinculado($cliente, bloquear: true)) {
                throw ValidationException::withMessages(['seguridad' => 'Este cliente ya tiene pedidos o cobros. Conserva su tipo y documento; puedes actualizar sus datos de contacto.']);
            }
            if ($datos['tipo'] === 'empresa' && $tipo?->codigo !== '6') {
                throw ValidationException::withMessages(['tipoId' => 'Las empresas se registran con RUC.']);
            }
            if ($tipo) {
                $mismoDocumento = $cliente->exists && $cliente->tipo_documento_identidad_id === $tipo->id && $cliente->numero_documento === $datos['numero'];
                if (! $tipo->activo && ! $mismoDocumento) {
                    throw ValidationException::withMessages(['tipoId' => 'Selecciona un tipo de documento activo.']);
                }
                $longitud = match ($tipo->codigo) {
                    '1' => 8, '6' => 11, default => $tipo->longitud
                };
                if ($longitud && (strlen($datos['numero']) !== $longitud || ! ctype_digit($datos['numero']))) {
                    throw ValidationException::withMessages(['numero' => 'El documento '.$tipo->nombre.' debe contener '.$longitud.' dígitos.']);
                }
            }
            $antes = $cliente->exists ? $cliente->only(self::CAMPOS) : [];
            if (! $id) {
                $cliente->fill(['clave_registro' => $clave, 'creado_por' => $actor->id]);
            }
            $cliente->fill(['tipo' => $datos['tipo'], 'nombre' => $datos['nombre'], 'tipo_documento_identidad_id' => $datos['tipoId'] ?: null,
                'numero_documento' => $datos['numero'] ?: null, 'direccion' => $datos['direccion'] ?: null,
                'telefono' => $datos['telefono'] ?: null, 'correo' => $datos['correo'] ?: null, 'activo' => $datos['activo']])->save();
            activity()->causedBy($actor)->performedOn($cliente)->event($id ? 'cliente_actualizado' : 'cliente_creado')
                ->withProperties(['antes' => $antes, 'despues' => $cliente->only(self::CAMPOS)])->log($id ? 'Cliente actualizado' : 'Cliente registrado');

            return $cliente;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $estado, string $version): void
    {
        $this->transaccion($actor, 'clientes.editar', function () use ($actor, $id, $estado, $version): void {
            $cliente = Cliente::lockForUpdate()->findOrFail($id);
            if ($cliente->activo === $estado) {
                return;
            }
            if (! hash_equals(self::huella($cliente), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El cliente cambió. Revisa su información antes de cambiar el estado.']);
            }
            $cliente->update(['activo' => $estado]);
            activity()->causedBy($actor)->performedOn($cliente)->event('cliente_estado')->withProperties(['activo' => $estado])->log($estado ? 'Cliente activado' : 'Cliente desactivado');
        });
    }

    public static function huella(Cliente $cliente): string
    {
        return hash('sha256', json_encode($cliente->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public static function vinculado(Cliente $cliente, bool $bloquear = false): bool
    {
        $pedidos = Pedido::where('cliente_id', $cliente->id);
        $cobros = Cobro::where('cliente_id', $cliente->id);
        if ($bloquear) {
            $pedidos->lockForUpdate();
            $cobros->lockForUpdate();
        }

        return $pedidos->first(['id']) !== null || $cobros->first(['id']) !== null;
    }
}
