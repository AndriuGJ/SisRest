<?php

namespace App\Services\Configuracion;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Impresora;
use App\Models\PruebaImpresion;
use App\Models\TrabajoImpresion;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionImpresoras
{
    use BloqueaImpresion;

    /** @param array{codigo: string, nombre: string, host: string, puerto: string, ancho: string, uso: string, cajaId: string, destinoId: string, activo: bool} $datos */
    public function guardar(User $actor, ?int $id, array $datos): Impresora
    {
        return $this->transaccion($actor, 'impresoras.gestionar', function () use ($actor, $id, $datos): Impresora {
            $impresora = $id ? Impresora::query()->findOrFail($id) : new Impresora;
            if ($id) {
                $this->comprobarDisponible($impresora);
            }
            $datos['codigo'] = Str::lower(trim($datos['codigo']));
            $datos['nombre'] = Str::squish($datos['nombre']);
            $datos['host'] = trim($datos['host']);
            $validado = Validator::make($datos, [
                'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('impresoras', 'codigo')->ignore($id)],
                'nombre' => ['required', 'string', 'max:100'],
                'host' => [$datos['activo'] ? 'required' : 'nullable', 'ipv4', 'not_in:0.0.0.0,255.255.255.255'],
                'puerto' => ['required', 'integer', 'min:1', 'max:65535'],
                'ancho' => ['required', Rule::in(['58', '80'])],
                'uso' => ['required', Rule::in(['general', 'caja', 'preparacion'])],
                'cajaId' => [Rule::requiredIf($datos['uso'] === 'caja'), 'nullable', 'integer', Rule::exists('cajas', 'id')],
                'destinoId' => [Rule::requiredIf($datos['uso'] === 'preparacion'), 'nullable', 'integer', Rule::exists('areas_preparacion', 'id')],
                'activo' => ['required', 'boolean'],
            ], [], ['codigo' => 'código', 'nombre' => 'nombre', 'host' => 'dirección IP', 'puerto' => 'puerto', 'ancho' => 'ancho de papel', 'uso' => 'uso', 'cajaId' => 'caja', 'destinoId' => 'destino'])->validate();
            if ($id && $impresora->codigo !== $validado['codigo']) {
                throw ValidationException::withMessages(['codigo' => 'El código se conserva después de crear la impresora.']);
            }
            $destino = $validado['uso'] === 'preparacion'
                ? AreaPreparacion::query()->whereKey($validado['destinoId'])->firstOrFail() : null;
            if ($validado['activo']) {
                $this->validarActivacion($id, $validado['host'], (int) $validado['puerto'], $destino);
            }
            $caja = $validado['uso'] === 'caja' ? Caja::query()->whereKey($validado['cajaId'])->firstOrFail() : null;
            if ($caja?->impresora_id && $caja->impresora_id !== $id) {
                $this->comprobarDisponible(Impresora::query()->whereKey($caja->impresora_id)->firstOrFail());
            }
            $antes = $impresora->only($impresora->getFillable());
            $cajasAntes = $id ? $impresora->cajas()->pluck('id')->all() : [];
            $sustituida = $caja?->impresora_id;
            $impresora->fill([
                'codigo' => $validado['codigo'], 'nombre' => $validado['nombre'], 'host' => $validado['host'] ?: null,
                'puerto' => $validado['puerto'], 'ancho_mm' => $validado['ancho'],
                'area_preparacion_id' => $destino?->id, 'activo' => $validado['activo'],
            ])->save();
            // Conserva la caja seleccionada: su modelo puede tener ya este id y no emitir otro UPDATE.
            Caja::query()->where('impresora_id', $impresora->id)
                ->when($caja, fn ($query) => $query->whereKeyNot($caja->id))
                ->update(['impresora_id' => null]);
            $caja?->update(['impresora_id' => $impresora->id]);
            activity()->causedBy($actor)->performedOn($impresora)->event('impresora_configurada')
                ->withProperties(['antes' => $antes, 'despues' => $impresora->only($impresora->getFillable()),
                    'cajas_anteriores' => $cajasAntes, 'caja_actual' => $caja?->id, 'impresora_sustituida' => $sustituida])
                ->log('Impresora y asignaciones configuradas');

            return $impresora;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $activo): Impresora
    {
        return $this->transaccion($actor, 'impresoras.gestionar', function () use ($actor, $id, $activo): Impresora {
            $impresora = Impresora::query()->findOrFail($id);
            $this->comprobarDisponible($impresora);
            if ($activo) {
                $this->validarActivacion($id, $impresora->host ?? '', $impresora->puerto, $impresora->areaPreparacion);
            }
            $antes = $impresora->activo;
            $impresora->update(['activo' => $activo]);
            activity()->causedBy($actor)->performedOn($impresora)->event('impresora_configurada')
                ->withProperties(['activo_antes' => $antes, 'activo_despues' => $activo])->log('Estado de impresora actualizado');

            return $impresora;
        });
    }

    private function validarActivacion(?int $id, string $host, int $puerto, ?AreaPreparacion $destino): void
    {
        Validator::make(['host' => $host, 'puerto' => $puerto], [
            'host' => ['required', 'ipv4', 'not_in:0.0.0.0,255.255.255.255'], 'puerto' => ['integer', 'min:1', 'max:65535'],
        ], [], ['host' => 'dirección IP', 'puerto' => 'puerto'])->validate();
        if ($destino && ! $destino->activo) {
            throw ValidationException::withMessages(['destinoId' => 'Activa el destino antes de habilitar su impresora.']);
        }
        if (Impresora::query()->when($id, fn ($query) => $query->whereKeyNot($id))->where('activo', true)
            ->where('host', $host)->where('puerto', $puerto)->exists()) {
            throw ValidationException::withMessages(['host' => 'Otra impresora activa ya utiliza esta dirección IP y puerto.']);
        }
        if ($destino && Impresora::query()->when($id, fn ($query) => $query->whereKeyNot($id))
            ->where('activo', true)->where('area_preparacion_id', $destino->id)->exists()) {
            throw ValidationException::withMessages(['destinoId' => 'Este destino ya tiene una impresora activa. Desactívala antes de habilitar otra.']);
        }
    }

    public function comprobarDisponible(Impresora $impresora): void
    {
        if (PruebaImpresion::query()->where('impresora_id', $impresora->id)->where('estado', 'procesando')->exists()
            || TrabajoImpresion::query()->where('impresora_id', $impresora->id)->whereIn('estado', ['pendiente', 'procesando'])->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'La impresora tiene un envío pendiente o en curso. Espera a que termine antes de modificarla.']);
        }
    }
}
