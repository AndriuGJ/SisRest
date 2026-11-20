<?php

namespace App\Services\Impresion;

use App\Models\AnulacionPedido;
use App\Models\Cobro;
use App\Models\CobroDetalle;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\EnvioCocina;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProcesoCobro;
use App\Models\ResolucionDevolucion;
use App\Models\User;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\RepresentacionFiscal;
use App\Support\Cantidad;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type Linea array{descripcion:string,cantidad:string,nota:string,extra:list<string>,precio?:numeric-string,descuento?:numeric-string,total?:numeric-string}
 * @phpstan-type Contenido array{version:int,titulo:string,empresa:list<string>,referencia:string,fecha:string,datos:list<string>,lineas:list<Linea>,totales:list<array{nombre:string,monto:numeric-string}>,pagos:list<array{nombre:string,monto:numeric-string,extra:list<string>}>,avisos:list<string>,qr?:string,logo?:string|null,tipografia?:array{fuente:string,tamano:int,grafica:bool}}
 * @phpstan-type Identidad array{pedido:Pedido,modelo:class-string,id:int,tipo:string,area:int}
 */
class FuenteImpresion
{
    /** @return class-string */
    public static function modelo(string $tipo): string
    {
        return match ($tipo) {
            'comanda' => EnvioCocina::class, 'precuenta' => Pedido::class, 'ticket' => Cobro::class, 'comprobante' => Comprobante::class,
            'anulacion' => AnulacionPedido::class, 'cancelacion_pagada' => ResolucionDevolucion::class,
            default => abort(404),
        };
    }

    /** @return Identidad */
    public function identificar(User $actor, string $tipo, int $id, int $area): array
    {
        $modelo = self::modelo($tipo);
        $pedidoId = match ($tipo) {
            'comanda' => EnvioCocina::findOrFail($id)->pedido_id,
            'precuenta' => $id,
            'ticket' => Cobro::findOrFail($id)->pedido_id,
            'comprobante' => Comprobante::findOrFail($id)->cobro()->firstOrFail()->pedido_id,
            'anulacion' => AnulacionPedido::findOrFail($id)->pedido_id,
            'cancelacion_pagada' => ResolucionDevolucion::findOrFail($id)->detalleDevolucion->devolucion->cobro->pedido_id,
            default => abort(404),
        };
        $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
        AccesoImpresion::pedido($actor, $pedido, $tipo);
        if ($tipo === 'ticket') {
            AccesoImpresion::cobro($actor, Cobro::findOrFail($id));
        }
        if ($tipo === 'comprobante') {
            AccesoFacturacion::cobro($actor, Comprobante::findOrFail($id)->cobro()->firstOrFail());
        }
        if ($tipo === 'comanda') {
            $area = EnvioCocina::findOrFail($id)->area_preparacion_id;
        } elseif (in_array($tipo, ['anulacion', 'cancelacion_pagada'], true)) {
            if (! collect($this->anulados($tipo, $id))->contains('area', $area)) {
                throw ValidationException::withMessages(['impresion' => 'La anulación no contiene productos de ese destino.']);
            }
        } else {
            $area = 0;
        }

        return ['pedido' => $pedido, 'modelo' => $modelo, 'id' => $id, 'tipo' => $tipo, 'area' => $area];
    }

    /**
     * @param  Identidad  $fuente
     * @return Contenido
     */
    public function contenido(array $fuente): array
    {
        if ($fuente['tipo'] === 'comprobante') {
            return app(RepresentacionFiscal::class)->contenido(Comprobante::findOrFail($fuente['id']));
        }
        $pedido = $fuente['pedido'];
        $empresa = Empresa::first();
        $encabezado = [$empresa?->nombre_comercial ?: ($empresa?->razon_social ?: 'RESTAURANTE')];
        if ($empresa?->ruc) {
            $encabezado[] = 'RUC: '.$empresa->ruc;
        }
        if ($empresa?->direccion) {
            $encabezado[] = $empresa->direccion;
        }
        if ($empresa?->telefono) {
            $encabezado[] = 'Tel: '.$empresa->telefono;
        }
        $tipografia = app(IdentidadVisual::class)->tipografia();
        $datos = [$this->atencion($pedido)];
        if ($pedido->mozo_nombre) {
            $datos[] = 'Mozo: '.$pedido->mozo_nombre;
        }
        if ($pedido->comensales) {
            $datos[] = 'Comensales: '.$pedido->comensales;
        }
        $base = ['logo' => $empresa?->logo_ruta, 'tipografia' => ['fuente' => $tipografia['fuente'], 'tamano' => $tipografia['tickets'], 'grafica' => $tipografia['grafica']], 'version' => 1, 'titulo' => '', 'empresa' => $encabezado, 'referencia' => $pedido->codigo, 'fecha' => $pedido->abierto_at->timezone('America/Lima')->format('d/m/Y H:i'), 'datos' => $datos, 'lineas' => [], 'totales' => [], 'pagos' => [], 'avisos' => []];

        return match ($fuente['tipo']) {
            'comanda' => $this->comanda($base, $fuente['id']),
            'precuenta' => $this->precuenta($base, $pedido),
            'ticket' => $this->ticket($base, Cobro::findOrFail($fuente['id'])),
            default => $this->aviso($base, $fuente['tipo'], $fuente['id'], $fuente['area']),
        };
    }

    private function atencion(Pedido $pedido): string
    {
        return match ($pedido->modalidad->value) {
            'mesa' => ($pedido->salon_nombre ? $pedido->salon_nombre.' / ' : '').'Mesa '.($pedido->mesa_codigo ?? ($pedido->mesa_id ? $pedido->mesa->codigo : '')),
            'rapida' => 'PARA LLEVAR', 'delivery' => 'DELIVERY',
        };
    }

    /**
     * @param  Contenido  $base
     * @return Contenido
     */
    private function comanda(array $base, int $id): array
    {
        $envio = EnvioCocina::with(['detalles.detallePedido.padre', 'areaPreparacion', 'pedido'])->findOrFail($id);
        $base['titulo'] = 'COMANDA';
        $base['referencia'] .= ' / lote '.$envio->numero_envio;
        $base['fecha'] = $envio->enviado_at->timezone('America/Lima')->format('d/m/Y H:i');
        $base['datos'][] = 'Destino: '.($envio->area_nombre ?? $envio->areaPreparacion->nombre);
        foreach ($envio->detalles as $d) {
            $cantidad = bcsub($d->cantidad, $d->cantidad_anulada, 4);
            if (bccomp($cantidad, '0', 4) <= 0) {
                continue;
            }
            $extra = [];
            if ($d->detallePedido->padre) {
                $extra[] = 'Combo: '.$d->detallePedido->padre->descripcion;
            }
            $extra[] = 'Sin iniciar: '.Cantidad::mostrar(bcsub($cantidad, $d->cantidad_iniciada, 4)).' / preparando: '.Cantidad::mostrar(bcsub($d->cantidad_iniciada, $d->cantidad_lista, 4));
            $extra[] = 'Listos: '.Cantidad::mostrar(bcsub($d->cantidad_lista, $d->cantidad_entregada, 4)).' / entregados: '.Cantidad::mostrar($d->cantidad_entregada);
            if (bccomp($d->cantidad_anulada, '0', 4) > 0) {
                $extra[] = 'Ya anulados: '.Cantidad::mostrar($d->cantidad_anulada);
            }
            $base['lineas'][] = ['descripcion' => $d->descripcion, 'cantidad' => Cantidad::mostrar($cantidad), 'nota' => $d->nota ?? '', 'extra' => $extra];
        }
        if ($base['lineas'] === []) {
            throw ValidationException::withMessages(['impresion' => 'Este envío no tiene cantidades vigentes que imprimir. Consulta sus avisos de anulación.']);
        }
        if ($envio->pedido->observaciones) {
            $base['datos'][] = 'Observación: '.$envio->pedido->observaciones;
        }
        $base['avisos'] = ['Verificar lote y avance antes de preparar.', 'Los nuevos pedidos llevan otro número de lote.'];

        return $base;
    }

    /**
     * @param  Contenido  $base
     * @return Contenido
     */
    private function precuenta(array $base, Pedido $pedido): array
    {
        if ($pedido->estado->value === 'anulado' || $pedido->detalles()->where('estado', 'borrador')->exists()) {
            throw ValidationException::withMessages(['impresion' => 'Confirma los productos en borrador antes de imprimir la precuenta. No se imprimen atenciones anuladas.']);
        }
        $base['titulo'] = 'PRECUENTA';
        $bruto = $descuento = '0.00';
        $raices = $pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'confirmado')->orderBy('id')->get();
        if ($raices->isEmpty()) {
            throw ValidationException::withMessages(['impresion' => 'No hay productos confirmados para la precuenta.']);
        }
        foreach ($raices as $d) {
            $cobrada = '0';
            foreach (CobroDetalle::where('pedido_detalle_id', $d->id)->whereHas('cobro', fn ($q) => $q->where('estado', 'confirmado'))->get() as $cobro) {
                $cobrada = bcadd($cobrada, $cobro->cantidad, 4);
            }
            if (bccomp($cobrada, $d->cantidad_cobrada, 4) !== 0) {
                throw ValidationException::withMessages(['impresion' => 'La cantidad cobrada requiere conciliación antes de imprimir.']);
            }
            $cantidad = bcsub(bcsub($d->cantidad, $d->cantidad_anulada, 4), $d->cantidad_cobrada, 4);
            if (bccomp($cantidad, '0', 4) < 0) {
                throw ValidationException::withMessages(['impresion' => 'La cuenta tiene cantidades cobradas inconsistentes.']);
            }
            if (bccomp($cantidad, '0', 4) === 0) {
                continue;
            }
            $subtotal = bcmul($cantidad, $d->precio_unitario, 2);
            $beneficio = CalculoBeneficios::paraCantidad($d, $cantidad, true);
            $base['lineas'][] = ['descripcion' => $d->descripcion, 'cantidad' => Cantidad::mostrar($cantidad), 'nota' => '', 'extra' => $d->es_cortesia ? ['Cortesía autorizada'] : [], 'precio' => $d->precio_unitario, 'descuento' => $beneficio, 'total' => bcsub($subtotal, $beneficio, 2)];
            $bruto = bcadd($bruto, $subtotal, 2);
            $descuento = bcadd($descuento, $beneficio, 2);
        }
        $envio = EstadoCuenta::envioPendiente($pedido, true);
        if (bccomp($envio, '0', 2) < 0) {
            throw ValidationException::withMessages(['impresion' => 'El cargo de envío requiere conciliación.']);
        }
        $base['totales'] = [['nombre' => 'Productos pendientes', 'monto' => $bruto], ['nombre' => 'Descuentos pendientes', 'monto' => bcsub('0', $descuento, 2)], ['nombre' => 'Envío pendiente', 'monto' => $envio], ['nombre' => 'TOTAL PENDIENTE', 'monto' => bcadd(bcsub($bruto, $descuento, 2), $envio, 2)]];
        $base['avisos'] = ['Solo cantidades confirmadas sin cobrar.', 'No acredita pago. Sin valor tributario.', 'Los descuentos generales se aplican al cobrar.'];

        return $base;
    }

    /**
     * @param  Contenido  $base
     * @return Contenido
     */
    private function ticket(array $base, Cobro $cobro): array
    {
        if ($cobro->estado->value !== 'confirmado') {
            throw ValidationException::withMessages(['impresion' => 'Solo se imprimen tickets de cobros confirmados.']);
        }
        $base['titulo'] = ProcesoCobro::where('cobro_id', $cobro->id)->where('documento', 'interno')->exists() ? 'NOTA DE VENTA' : 'TICKET DE COBRO';
        $base['referencia'] = $cobro->codigo;
        $base['fecha'] = $cobro->cobrado_at?->timezone('America/Lima')->format('d/m/Y H:i') ?? '';
        $base['datos'][] = 'Caja: '.($cobro->caja_nombre ?? $cobro->turnoCaja->caja->nombre);
        if ($cobro->grupo_pagador) {
            $base['datos'][] = 'Grupo: '.$cobro->grupo_pagador;
        }
        if ($cobro->cliente_nombre) {
            $base['datos'][] = 'Cliente: '.$cobro->cliente_nombre;
        }
        if ($cobro->cliente_numero_documento) {
            $base['datos'][] = ($cobro->cliente_tipo_documento ?? 'Documento').': '.$cobro->cliente_numero_documento;
        }
        foreach ($cobro->detalles()->orderBy('id')->get() as $d) {
            $base['lineas'][] = ['descripcion' => $d->descripcion, 'cantidad' => Cantidad::mostrar($d->cantidad), 'nota' => '', 'extra' => $d->es_cortesia ? ['Cortesía autorizada'] : [], 'precio' => $d->precio_unitario, 'descuento' => $d->descuento, 'total' => $d->total];
        }
        $base['totales'] = [['nombre' => 'Productos', 'monto' => $cobro->subtotal], ['nombre' => 'Descuentos por producto', 'monto' => bcsub('0', $cobro->descuento_productos, 2)], ['nombre' => 'Descuento general', 'monto' => bcsub('0', $cobro->descuento_general, 2)], ['nombre' => 'Envío', 'monto' => $cobro->cargo_envio], ['nombre' => 'TOTAL COBRADO ORIGINAL', 'monto' => $cobro->total]];
        foreach ($cobro->pagos()->with('medioPago')->orderBy('id')->get() as $pago) {
            $extra = [];
            if ($pago->afecta_efectivo ?? $pago->medioPago->afecta_efectivo) {
                $extra[] = 'Recibido S/ '.$pago->monto_entregado.' / vuelto S/ '.$pago->vuelto;
            }
            if ($pago->referencia) {
                $extra[] = 'Referencia: '.$pago->referencia;
            }
            $base['pagos'][] = ['nombre' => $pago->medio_pago_nombre ?? $pago->medioPago->nombre, 'monto' => $pago->monto, 'extra' => $extra];
        }
        $base['avisos'] = ['Registro interno. NO ES BOLETA NI FACTURA.', 'Las devoluciones se registran por separado.'];

        return $base;
    }

    /** @return list<array{detalleId:int,area:int,descripcion:string,cantidad:numeric-string}> */
    public function anulados(string $tipo, int $id): array
    {
        $resultado = [];
        if ($tipo === 'anulacion') {
            foreach (AnulacionPedido::findOrFail($id)->detalles as $d) {
                $hoja = PedidoDetalle::findOrFail($d->pedido_detalle_id);
                if ($hoja->area_preparacion_id) {
                    $resultado[] = ['detalleId' => $hoja->id, 'area' => $hoja->area_preparacion_id, 'descripcion' => $d->descripcion, 'cantidad' => bcadd($d->cantidad_liberada, $d->cantidad_merma, 4)];
                }
            }
        } elseif ($tipo === 'cancelacion_pagada') {
            $resolucion = ResolucionDevolucion::findOrFail($id);
            if ($resolucion->accion !== 'cancelar_pendiente') {
                abort(404);
            }
            foreach ($resolucion->detalles as $d) {
                $hoja = PedidoDetalle::findOrFail($d->pedido_detalle_id);
                if ($hoja->area_preparacion_id) {
                    $resultado[] = ['detalleId' => $hoja->id, 'area' => $hoja->area_preparacion_id, 'descripcion' => $d->descripcion, 'cantidad' => $d->cantidad];
                }
            }
        }

        return $resultado;
    }

    /**
     * @param  Contenido  $base
     * @return Contenido
     */
    private function aviso(array $base, string $tipo, int $id, int $area): array
    {
        $base['titulo'] = 'ANULACIÓN DE COMANDA';
        $base['referencia'] .= ' / aviso '.$tipo.' #'.$id;
        $base['datos'][] = 'NO PREPARAR / NO ENTREGAR estas cantidades';
        foreach ($this->anulados($tipo, $id) as $fila) {
            if ($fila['area'] === $area) {
                $hoja = PedidoDetalle::with('padre')->findOrFail($fila['detalleId']);
                $base['lineas'][] = ['descripcion' => $fila['descripcion'], 'cantidad' => Cantidad::mostrar($fila['cantidad']), 'nota' => '', 'extra' => $hoja->padre ? ['Combo: '.$hoja->padre->descripcion] : []];
            }
        }
        $base['avisos'] = ['Aviso de una anulación ya registrada.', 'No vuelve a anular, cobrar ni modificar stock.'];

        return $base;
    }
}
