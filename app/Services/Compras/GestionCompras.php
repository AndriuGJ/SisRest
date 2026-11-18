<?php

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Models\Articulo;
use App\Models\Compra;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\PagoCompra;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionCompras
{
    use TransaccionCompras;

    /**
     * @param  array{proveedorId:string,tipoId:string,serie:string,numero:string,fecha:string,vencimiento:string,condicion:string,observaciones:string}  $datos
     * @param  array<string,array{articuloId:string,presentacionId:string,cantidad:string,costo:string,descuento:string,impuestos:string}>  $filas
     */
    public function guardar(User $actor, ?int $id, string $clave, array $datos, array $filas, string $version = ''): Compra
    {
        return $this->ejecutar($actor, 'compras.crear', function () use ($actor, $id, $clave, $datos, $filas, $version): Compra {
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if (! $id && ($existente = Compra::where('clave_registro', $clave)->first())) {
                abort_unless($existente->usuario_id === $actor->id, 403);

                return $existente;
            }
            $compra = $id ? Compra::query()->lockForUpdate()->findOrFail($id) : new Compra;
            if ($id) {
                $this->editor($actor, $compra);
                $this->borrador($compra, $version);
            }
            $datos['serie'] = Str::upper(trim($datos['serie']));
            $datos['numero'] = Str::upper(trim($datos['numero']));
            Validator::make($datos, [
                'proveedorId' => ['required', 'integer', Rule::exists('proveedores', 'id')->where('activo', true)],
                'tipoId' => ['required', 'integer', Rule::exists('tipos_comprobante', 'id')->where('activo', true)->whereIn('codigo', ['00', '01', '03'])],
                'serie' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
                'numero' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/'],
                'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'condicion' => ['required', Rule::in(['contado', 'credito'])],
                'vencimiento' => ['nullable', 'required_if:condicion,credito', 'date_format:Y-m-d', 'after_or_equal:fecha'],
                'observaciones' => ['nullable', 'string', 'max:2000'],
            ], [], ['proveedorId' => 'proveedor', 'tipoId' => 'documento de compra', 'vencimiento' => 'fecha de vencimiento'])->validate();
            $tipo = TipoComprobante::findOrFail($datos['tipoId']);
            if (($tipo->codigo !== '00' && ($datos['serie'] === '' || $datos['numero'] === ''))
                || (($datos['serie'] === '') !== ($datos['numero'] === ''))) {
                throw ValidationException::withMessages(['numero' => 'Indica serie y número juntos. Son obligatorios para boleta y factura.']);
            }
            if ($datos['numero'] !== '' && Compra::where('proveedor_id', $datos['proveedorId'])->where('tipo_comprobante_id', $datos['tipoId'])
                ->where('serie', $datos['serie'])->where('numero', $datos['numero'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['numero' => 'Este documento ya está registrado para el proveedor.']);
            }
            Validator::make(['filas' => $filas], [
                'filas' => ['required', 'array', 'min:1', 'max:50'],
                'filas.*.articuloId' => ['required', 'integer', 'distinct', Rule::exists('articulos', 'id')],
                'filas.*.presentacionId' => ['present', 'nullable', 'integer'],
                'filas.*.cantidad' => ['required', 'string', 'max:25'], 'filas.*.costo' => ['required', 'string', 'max:25'],
                'filas.*.descuento' => ['required', 'string', 'max:25'], 'filas.*.impuestos' => ['required', 'string', 'max:25'],
            ], [], ['filas' => 'artículos', 'filas.*.articuloId' => 'artículo'])->validate();
            $detalles = [];
            $subtotal = $descuento = $impuestos = $total = '0.00';
            foreach ($filas as $claveFila => $fila) {
                $articulo = Articulo::with(['unidadMedida', 'presentaciones'])->findOrFail($fila['articuloId']);
                $this->articuloComprable($articulo);
                $presentacion = $fila['presentacionId'] !== '' ? $articulo->presentaciones->firstWhere('id', (int) $fila['presentacionId']) : null;
                if ($fila['presentacionId'] !== '' && (! $presentacion || ! $presentacion->activo)) {
                    throw ValidationException::withMessages(['filas.'.$claveFila.'.presentacionId' => 'La presentación debe estar activa y pertenecer a este artículo.']);
                }
                $factor = $fila['presentacionId'] === '' ? '1.0000' : $presentacion->factor_conversion;
                $cantidad = CalculoProduccion::cantidad($fila['cantidad'], 'filas.'.$claveFila.'.cantidad');
                $baseExacta = bcmul($cantidad, $factor, 8);
                $base = CalculoProduccion::cantidad(bcadd($baseExacta, '0', 4), 'filas.'.$claveFila.'.cantidad');
                if (bccomp($base, $baseExacta, 8) !== 0) {
                    throw ValidationException::withMessages(['filas.'.$claveFila.'.cantidad' => 'La conversión debe poder expresarse con hasta cuatro decimales en la unidad base.']);
                }
                $costo = ImportesCompra::importe($fila['costo'], 'filas.'.$claveFila.'.costo', 6, true);
                $bruto = ImportesCompra::redondear(bcmul($cantidad, $costo, 10));
                $rebaja = ImportesCompra::importe($fila['descuento'], 'filas.'.$claveFila.'.descuento');
                $neto = bcsub($bruto, $rebaja, 2);
                $tributo = ImportesCompra::importe($fila['impuestos'], 'filas.'.$claveFila.'.impuestos');
                if (bccomp($neto, '0', 2) <= 0 || bccomp($tributo, $neto, 2) > 0) {
                    throw ValidationException::withMessages(['filas.'.$claveFila.'.descuento' => 'El total debe ser positivo y el impuesto incluido no puede superar ese total.']);
                }
                $subtotal = ImportesCompra::redondear(bcadd($subtotal, bcsub($bruto, $tributo, 2), 2));
                $descuento = ImportesCompra::redondear(bcadd($descuento, $rebaja, 2));
                $impuestos = ImportesCompra::redondear(bcadd($impuestos, $tributo, 2));
                $total = ImportesCompra::redondear(bcadd($total, $neto, 2));
                $detalles[] = ['articulo_id' => $articulo->id, 'presentacion_articulo_id' => $presentacion?->id,
                    'descripcion' => $articulo->nombre, 'unidad_codigo' => $articulo->unidadMedida->codigo,
                    'presentacion_nombre' => $fila['presentacionId'] === '' ? $articulo->unidadMedida->nombre : $presentacion->nombre,
                    'cantidad' => $cantidad, 'factor_conversion' => $factor, 'cantidad_base' => $base, 'costo_unitario' => $costo,
                    'descuento' => $rebaja, 'impuestos' => $tributo, 'total' => $neto];
            }
            $proveedor = Proveedor::with('tipoDocumento')->findOrFail($datos['proveedorId']);
            if (! $id) {
                $compra->fill(['clave_registro' => $clave, 'codigo' => 'COMP-'.Str::ulid(), 'usuario_id' => $actor->id, 'estado' => 'borrador', 'moneda' => 'PEN']);
            }
            $compra->fill(['proveedor_id' => $proveedor->id, 'proveedor_nombre' => $proveedor->nombre,
                'proveedor_documento' => $proveedor->numero_documento ? ($proveedor->tipoDocumento?->nombre.' '.$proveedor->numero_documento) : null,
                'tipo_comprobante_id' => $tipo->id, 'documento_nombre' => $tipo->nombre, 'serie' => $datos['serie'] ?: null, 'numero' => $datos['numero'] ?: null,
                'fecha' => $datos['fecha'], 'fecha_vencimiento' => $datos['condicion'] === 'credito' ? $datos['vencimiento'] : null,
                'condicion_pago' => $datos['condicion'], 'subtotal' => $subtotal, 'descuento' => $descuento, 'impuestos' => $impuestos, 'total' => $total,
                'observaciones' => trim($datos['observaciones']) ?: null])->save();
            $compra->detalles()->delete();
            $compra->detalles()->createMany($detalles);
            activity()->causedBy($actor)->performedOn($compra)->event('compra_borrador')->log($id ? 'Compra actualizada sin movimientos' : 'Compra registrada como borrador');

            return $compra;
        });
    }

    /** @param array{origen:string,medioId:string,turnoId:string,referencia:string,observaciones:string,monto:string} $pago */
    public function confirmar(User $actor, int $id, string $version, string $clavePago, array $pago): Compra
    {
        return $this->ejecutar($actor, 'compras.confirmar', function () use ($actor, $id, $version, $clavePago, $pago): Compra {
            $compra = Compra::with(['detalles', 'proveedor'])->lockForUpdate()->findOrFail($id);
            if ($compra->estado === EstadoCompra::CONFIRMADA) {
                return $compra;
            }
            $this->borrador($compra, $version);
            if (! $compra->clave_registro || $compra->detalles->isEmpty() || ! $compra->proveedor->activo) {
                throw ValidationException::withMessages(['seguridad' => 'Revisa y guarda la compra con un proveedor activo antes de confirmar.']);
            }
            $entradas = [];
            foreach ($compra->detalles as $detalle) {
                $articulo = Articulo::with('unidadMedida')->findOrFail($detalle->articulo_id);
                $this->articuloComprable($articulo);
                if ($detalle->unidad_codigo !== $articulo->unidadMedida->codigo) {
                    throw ValidationException::withMessages(['seguridad' => 'Cambió la unidad de un artículo. Revisa el borrador.']);
                }
                $saldo = CalculoProduccion::cantidad(bcadd($articulo->stock_actual, $detalle->cantidad_base, 4));
                $costo = CalculoProduccion::costo(bcdiv($detalle->total, $detalle->cantidad_base, 7));
                $promedio = CalculoProduccion::costo(bcdiv(bcadd(bcmul($articulo->stock_actual, $articulo->costo_promedio, 10), $detalle->total, 10), $saldo, 7));
                $entradas[] = ['articulo' => $articulo, 'detalle' => $detalle, 'saldo' => $saldo, 'costo' => $costo, 'promedio' => $promedio];
            }
            // El pago al contado pertenece a la misma transacción: si falla, no se recibe inventario.
            $compra->update(['estado' => 'confirmada', 'confirmada_por' => $actor->id, 'confirmada_at' => now()]);
            if ($compra->condicion_pago->value === 'contado') {
                $this->pagar($actor, $compra, $clavePago, array_replace($pago, ['monto' => $compra->total]));
            }
            foreach ($entradas as $fila) {
                $articulo = $fila['articulo'];
                $detalle = $fila['detalle'];
                $antes = $articulo->stock_actual;
                $articulo->update(['stock_actual' => $fila['saldo'], 'costo_promedio' => $fila['promedio']]);
                MovimientoInventario::create(['articulo_id' => $articulo->id, 'usuario_id' => $actor->id, 'tipo' => 'compra',
                    'cantidad' => $detalle->cantidad_base, 'saldo_anterior' => $antes, 'saldo_posterior' => $fila['saldo'], 'costo_unitario' => $fila['costo'],
                    'origen_type' => Compra::class, 'origen_id' => $compra->id, 'clave_idempotencia' => 'compra:'.$compra->id.':'.$articulo->id, 'registrado_at' => now()]);
            }
            activity()->causedBy($actor)->performedOn($compra)->event('compra_confirmada')->log('Compra recibida y existencias actualizadas');

            return $compra;
        });
    }

    /** @param array{origen:string,medioId:string,turnoId:string,referencia:string,observaciones:string,monto:string} $datos */
    public function registrarPago(User $actor, int $id, string $clave, array $datos): PagoCompra
    {
        return $this->ejecutar($actor, 'pagos_proveedor.registrar', function () use ($actor, $id, $clave, $datos): PagoCompra {
            $compra = Compra::query()->lockForUpdate()->findOrFail($id);

            return $this->pagar($actor, $compra, $clave, $datos);
        });
    }

    /** @param array{origen:string,medioId:string,turnoId:string,referencia:string,observaciones:string,monto:string} $datos */
    private function pagar(User $actor, Compra $compra, string $clave, array $datos): PagoCompra
    {
        $actual = User::findOrFail($actor->id);
        Gate::forUser($actual)->authorize('pagos_proveedor.registrar');
        Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
        if ($pago = PagoCompra::where('clave_idempotencia', $clave)->first()) {
            abort_unless($pago->compra_id === $compra->id && $pago->usuario_id === $actor->id, 403);

            return $pago;
        }
        if ($compra->estado !== EstadoCompra::CONFIRMADA) {
            throw ValidationException::withMessages(['seguridad' => 'Solo se pagan compras confirmadas.']);
        }
        Validator::make($datos, [
            'origen' => ['required', Rule::in(['caja', 'empresa', 'dueno'])],
            'medioId' => ['required', 'integer', Rule::exists('medios_pago', 'id')->where('activo', true)],
            'turnoId' => ['nullable', 'required_if:origen,caja', 'integer'],
            'referencia' => ['nullable', 'string', 'max:150'], 'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [], ['medioId' => 'medio de pago', 'turnoId' => 'caja abierta'])->validate();
        $monto = ImportesCompra::importe($datos['monto'], 'monto', 2, true);
        if (bccomp($monto, $compra->saldoPendiente(), 2) > 0) {
            throw ValidationException::withMessages(['monto' => 'El pago supera el saldo pendiente de la compra.']);
        }
        $turno = null;
        if ($datos['origen'] === 'caja') {
            Gate::forUser($actual)->authorize('cajas.registrar_egreso');
            $turno = TurnoCaja::query()->lockForUpdate()->findOrFail($datos['turnoId']);
            abort_unless($turno->usuario_id === $actor->id || $actual->can('cajas.ver_todas'), 403);
            if ($turno->estado->value !== 'abierta' || ! $turno->caja->activo) {
                throw ValidationException::withMessages(['turnoId' => 'Selecciona un turno de caja abierto.']);
            }
        } elseif ($datos['turnoId'] !== '') {
            throw ValidationException::withMessages(['turnoId' => 'Los pagos externos no deben vincularse a un turno de caja.']);
        }
        $medio = MedioPago::query()->lockForUpdate()->findOrFail($datos['medioId']);
        if (! $medio->activo || ($turno && ! $medio->afecta_efectivo) || ($datos['origen'] === 'empresa' && $medio->afecta_efectivo)) {
            throw ValidationException::withMessages(['medioId' => 'Usa efectivo para caja, un medio digital para empresa o el origen dinero del dueño.']);
        }
        if ($turno && bccomp($monto, $turno->efectivoEsperado(), 2) > 0) {
            throw ValidationException::withMessages(['monto' => 'El turno no tiene suficiente efectivo esperado para este pago.']);
        }
        $pago = $compra->pagos()->create(['usuario_id' => $actor->id, 'medio_pago_id' => $medio->id, 'medio_pago_nombre' => $medio->nombre,
            'turno_caja_id' => $turno?->id, 'origen_fondos' => $datos['origen'], 'monto' => $monto,
            'referencia' => trim($datos['referencia']) ?: null, 'observaciones' => trim($datos['observaciones']) ?: null,
            'clave_idempotencia' => $clave, 'pagado_at' => now()]);
        if ($turno) {
            MovimientoCaja::create(['turno_caja_id' => $turno->id, 'usuario_id' => $actor->id, 'tipo' => 'egreso',
                'concepto' => 'Pago de compra '.$compra->codigo, 'monto' => $monto, 'clave_idempotencia' => 'pago_compra:'.$pago->id,
                'origen_type' => PagoCompra::class, 'origen_id' => $pago->id, 'registrado_at' => now()]);
        }
        activity()->causedBy($actor)->performedOn($compra)->event('compra_pagada')->withProperties(['pago_id' => $pago->id, 'monto' => $monto, 'origen' => $datos['origen']])->log('Pago a proveedor registrado');

        return $pago;
    }

    public function anular(User $actor, int $id, string $version, string $motivo): void
    {
        $this->ejecutar($actor, 'compras.crear', function () use ($actor, $id, $version, $motivo): void {
            $compra = Compra::query()->lockForUpdate()->findOrFail($id);
            $this->editor($actor, $compra);
            if ($compra->estado === EstadoCompra::ANULADA) {
                return;
            }
            $this->borrador($compra, $version);
            Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
            $compra->update(['estado' => 'anulada', 'anulada_por' => $actor->id, 'anulada_at' => now(), 'motivo_anulacion' => trim($motivo)]);
            activity()->causedBy($actor)->performedOn($compra)->event('compra_anulada')->log('Borrador de compra anulado sin movimientos');
        });
    }

    public static function huella(Compra $compra): string
    {
        return hash('sha256', json_encode([$compra->getAttributes(), $compra->detalles()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    private function borrador(Compra $compra, string $version): void
    {
        if ($compra->estado !== EstadoCompra::BORRADOR || ! hash_equals(self::huella($compra), $version)) {
            throw ValidationException::withMessages(['seguridad' => 'La compra cambió o ya no es un borrador. Vuelve a abrirla antes de continuar.']);
        }
    }

    private function editor(User $actor, Compra $compra): void
    {
        abort_unless($compra->usuario_id === $actor->id || User::findOrFail($actor->id)->can('compras.confirmar'), 403);
    }

    private function articuloComprable(Articulo $articulo): void
    {
        if (! in_array($articulo->tipo->value, ['insumo', 'reventa'], true) || ! $articulo->activo || ! $articulo->unidadMedida->activo) {
            throw ValidationException::withMessages(['seguridad' => 'Solo se compran insumos o artículos de reventa activos, con unidad activa.']);
        }
    }
}
