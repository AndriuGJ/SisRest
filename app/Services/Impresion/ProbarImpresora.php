<?php

namespace App\Services\Impresion;

use App\Enums\EstadoTrabajoImpresion;
use App\Exceptions\FalloImpresion;
use App\Models\Impresora;
use App\Models\PruebaImpresion;
use App\Models\User;
use App\Services\Configuracion\BloqueaImpresion;
use App\Services\Configuracion\GestionImpresoras;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProbarImpresora
{
    use BloqueaImpresion;

    public function __construct(private TransporteEscPos $transporte, private TicketPrueba $ticket) {}

    public static function huella(Impresora $impresora): string
    {
        return hash('sha256', json_encode([
            $impresora->nombre, $impresora->host, $impresora->puerto, $impresora->ancho_mm,
            $impresora->activo, $impresora->area_preparacion_id, $impresora->areaPreparacion?->activo,
        ], JSON_THROW_ON_ERROR));
    }

    public function ejecutar(User $actor, int $impresoraId, string $clave, string $huella): PruebaImpresion
    {
        Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
        $enviar = false;
        $prueba = $this->transaccion($actor, 'impresoras.gestionar', function () use ($actor, $impresoraId, $clave, $huella, &$enviar): PruebaImpresion {
            $enviar = false;
            $existente = PruebaImpresion::query()->whereKey($clave)->first();
            if ($existente) {
                abort_unless($existente->usuario_id === $actor->id && $existente->impresora_id === $impresoraId, 403);

                return $existente;
            }
            $impresora = Impresora::query()->findOrFail($impresoraId);
            if (! hash_equals(self::huella($impresora), $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'La configuración cambió. Revisa la impresora y solicita una nueva prueba.']);
            }
            if (! $impresora->activo || ($impresora->areaPreparacion && ! $impresora->areaPreparacion->activo)) {
                throw ValidationException::withMessages(['seguridad' => 'Activa la impresora y su destino antes de imprimir una prueba.']);
            }
            Validator::make(['host' => $impresora->host], ['host' => ['required', 'ipv4']])->validate();
            app(GestionImpresoras::class)->comprobarDisponible($impresora);
            $enviar = true;

            return PruebaImpresion::create([
                'id' => $clave, 'impresora_id' => $impresora->id, 'usuario_id' => $actor->id,
                'configuracion' => ['nombre' => $impresora->nombre, 'host' => $impresora->host,
                    'puerto' => $impresora->puerto, 'ancho_mm' => $impresora->ancho_mm],
                'estado' => 'procesando', 'mensaje' => 'Prueba en curso. Si se interrumpe, revisa el papel antes de solicitar otra.',
            ]);
        });
        if (! $enviar) {
            return $prueba;
        }
        // La prueba queda registrada antes de tocar la red; no hay reintentos.
        $iniciado = false;
        try {
            $contenido = $this->ticket->generar($prueba);
            $iniciado = true;
            $this->transporte->enviar($prueba->configuracion['host'], $prueba->configuracion['puerto'], $contenido);
            $estado = EstadoTrabajoImpresion::ENVIADO;
            $mensaje = 'Ticket enviado. Comprueba en la impresora que el papel salió correctamente.';
        } catch (FalloImpresion $error) {
            $estado = $error->incierto ? EstadoTrabajoImpresion::RESULTADO_INCIERTO : EstadoTrabajoImpresion::ERROR;
            $mensaje = $error->getMessage();
        } catch (Throwable $error) {
            report($error);
            $estado = $iniciado ? EstadoTrabajoImpresion::RESULTADO_INCIERTO : EstadoTrabajoImpresion::ERROR;
            $mensaje = $iniciado ? 'El resultado no se pudo confirmar. Revisa el papel antes de solicitar otra prueba.' : 'No se pudo generar el ticket de prueba.';
        }
        $prueba->update(['estado' => $estado, 'mensaje' => $mensaje]);
        activity()->causedBy($actor)->performedOn(Impresora::query()->findOrFail($impresoraId))->event('prueba_impresion')
            ->withProperties(['estado' => $estado->value, 'impresora_id' => $impresoraId, 'prueba_id' => $prueba->id])->log($mensaje);

        return $prueba;
    }
}
