<?php

namespace App\Services\Compras;

use App\Models\Proveedor;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionProveedores
{
    use TransaccionCompras;

    /** @param array{nombre:string,tipoId:string,numero:string,direccion:string,telefono:string,correo:string,activo:bool} $datos */
    public function guardar(User $actor, ?int $id, array $datos, string $version = ''): Proveedor
    {
        return $this->ejecutar($actor, 'proveedores.gestionar', function () use ($id, $datos, $version): Proveedor {
            $proveedor = $id ? Proveedor::findOrFail($id) : new Proveedor;
            if ($id && ! hash_equals(self::huella($proveedor), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El proveedor cambió en otra sesión. Vuelve a abrirlo.']);
            }
            $datos['nombre'] = Str::squish($datos['nombre']);
            $datos['numero'] = Str::upper(trim($datos['numero']));
            Validator::make($datos, [
                'nombre' => ['required', 'string', 'max:200'],
                'tipoId' => ['nullable', 'required_with:numero', 'integer', Rule::exists('tipos_documento_identidad', 'id')],
                'numero' => ['nullable', 'required_with:tipoId', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('proveedores', 'numero_documento')->where('tipo_documento_identidad_id', $datos['tipoId'] ?: null)->ignore($id)],
                'direccion' => ['nullable', 'string', 'max:250'], 'telefono' => ['nullable', 'string', 'max:30'],
                'correo' => ['nullable', 'email', 'max:150'], 'activo' => ['boolean'],
            ], [], ['tipoId' => 'tipo de documento', 'numero' => 'número de documento', 'nombre' => 'nombre o razón social'])->validate();
            $tipo = $datos['tipoId'] !== '' ? TipoDocumentoIdentidad::findOrFail($datos['tipoId']) : null;
            if ($tipo && ((! $tipo->activo && $proveedor->tipo_documento_identidad_id !== $tipo->id)
                || ($tipo->longitud && (strlen($datos['numero']) !== $tipo->longitud || ! ctype_digit($datos['numero']))))) {
                throw ValidationException::withMessages(['numero' => 'Revisa el tipo y la longitud del documento seleccionado.']);
            }
            $proveedor->fill(['nombre' => $datos['nombre'], 'tipo_documento_identidad_id' => $datos['tipoId'] ?: null,
                'numero_documento' => $datos['numero'] ?: null, 'direccion' => trim($datos['direccion']) ?: null,
                'telefono' => trim($datos['telefono']) ?: null, 'correo' => trim($datos['correo']) ?: null, 'activo' => $datos['activo']])->save();

            return $proveedor;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $estado, string $version): void
    {
        $this->ejecutar($actor, 'proveedores.gestionar', function () use ($id, $estado, $version): void {
            $proveedor = Proveedor::findOrFail($id);
            if (! hash_equals(self::huella($proveedor), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El proveedor cambió. Revisa su estado antes de continuar.']);
            }
            $proveedor->update(['activo' => $estado]);
        });
    }

    public static function huella(Proveedor $proveedor): string
    {
        return hash('sha256', json_encode($proveedor->getAttributes(), JSON_THROW_ON_ERROR));
    }
}
