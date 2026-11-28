<?php

namespace App\Services\Facturacion;

use App\Models\Cobro;

/**
 * @phpstan-type Linea array{cobro_detalle_id:int|null,codigo_producto:string,descripcion:string,unidad_sunat:string,cantidad:numeric-string,valor_unitario:numeric-string,precio_unitario:numeric-string,valor_referencial:numeric-string,tipo_afectacion_igv:string,tasa_impuesto:numeric-string,base_imponible:numeric-string,descuento:numeric-string,impuestos:numeric-string,total:numeric-string}
 * @phpstan-type Resultado array{lineas:list<Linea>,gravadas:numeric-string,exoneradas:numeric-string,inafectas:numeric-string,gratuitas:numeric-string,igv_gratuitas:numeric-string,impuestos:numeric-string,valor_venta:numeric-string,total:numeric-string,descuento:numeric-string}
 */
class CalculoTributario
{
    /**
     * @param  numeric-string  $tasa
     * @param  array<int|string,string>  $afectaciones
     * @return Resultado
     */
    public function calcular(Cobro $cobro, string $tasa, array $afectaciones): array
    {
        /** @var Resultado $resultado */
        $resultado = ['lineas' => [], 'gravadas' => '0.00', 'exoneradas' => '0.00', 'inafectas' => '0.00', 'gratuitas' => '0.00', 'igv_gratuitas' => '0.00', 'impuestos' => '0.00', 'valor_venta' => '0.00', 'total' => '0.00', 'descuento' => '0.00'];
        $fuentes = [];
        foreach ($cobro->detalles()->orderBy('id')->get() as $detalle) {
            if (bccomp($detalle->subtotal, '0', 2) === 0 && bccomp($detalle->total, '0', 2) === 0) {
                continue; // Components already included in the paid combo root.
            }
            $fuentes[] = ['id' => $detalle->id, 'descripcion' => $detalle->descripcion, 'cantidad' => $detalle->cantidad, 'subtotal' => $detalle->subtotal, 'total' => $detalle->total, 'unidad' => 'NIU'];
        }
        if (bccomp($cobro->cargo_envio, '0', 2) > 0) {
            $fuentes[] = ['id' => null, 'descripcion' => 'Servicio de delivery', 'cantidad' => '1.0000', 'subtotal' => $cobro->cargo_envio, 'total' => $cobro->cargo_envio, 'unidad' => 'ZZ'];
        }
        foreach ($fuentes as $fuente) {
            $gratis = bccomp($fuente['total'], '0', 2) === 0;
            $codigo = $afectaciones[$fuente['id'] ?? 'envio'] ?? '';
            ReglasFiscales::exigir(isset(ReglasFiscales::AFECTACIONES[$codigo]), 'Selecciona la afectación tributaria de todos los productos, incluidas las cortesías.');
            ReglasFiscales::exigir($gratis === ! in_array($codigo, ['10', '20', '30'], true), 'La afectación elegida no corresponde al importe de '.$fuente['descripcion'].'.');
            $gravado = str_starts_with($codigo, '1');
            $porcentaje = $gravado ? $tasa : '0.0000';
            $factor = bcadd('1', bcdiv($porcentaje, '100', 12), 12);
            $original = ReglasFiscales::redondear(bcdiv($fuente['subtotal'], $factor, 12));
            $base = $gratis ? $original : ReglasFiscales::redondear(bcdiv($fuente['total'], $factor, 12));
            $impuesto = $gratis ? ReglasFiscales::redondear(bcmul($base, bcdiv($porcentaje, '100', 12), 12)) : bcsub($fuente['total'], $base, 2);
            $descuento = $gratis ? '0.00' : bcsub($original, $base, 2);
            $linea = [
                'cobro_detalle_id' => $fuente['id'], 'codigo_producto' => $fuente['id'] ? 'COB-'.$fuente['id'] : 'DELIVERY',
                'descripcion' => ReglasFiscales::texto($fuente['descripcion']), 'unidad_sunat' => $fuente['unidad'], 'cantidad' => $fuente['cantidad'],
                'valor_unitario' => $gratis ? '0.000000' : ReglasFiscales::redondear(bcdiv($original, $fuente['cantidad'], 12), 6),
                'precio_unitario' => $gratis ? '0.000000' : ReglasFiscales::redondear(bcdiv($fuente['total'], $fuente['cantidad'], 12), 6),
                'valor_referencial' => $gratis ? ReglasFiscales::redondear(bcdiv($base, $fuente['cantidad'], 12), 6) : '0.000000',
                'tipo_afectacion_igv' => $codigo, 'tasa_impuesto' => $porcentaje, 'base_imponible' => $base,
                'descuento' => $descuento, 'impuestos' => $impuesto, 'total' => $fuente['total'],
            ];
            $unitario = $gratis ? $linea['valor_referencial'] : $linea['valor_unitario'];
            $baseCalculada = ReglasFiscales::redondear(bcmul($unitario, $fuente['cantidad'], 12));
            ReglasFiscales::exigir(bccomp($baseCalculada, $gratis ? $base : $original, 2) === 0, 'La precisión de la cantidad impide representar el precio tributario de '.$fuente['descripcion'].'.');
            $resultado['lineas'][] = $linea;
            $grupo = $gratis ? 'gratuitas' : match ($codigo) {
                '10' => 'gravadas', '20' => 'exoneradas', default => 'inafectas'
            };
            $resultado[$grupo] = bcadd($resultado[$grupo], $base, 2);
            $resultado[$gratis ? 'igv_gratuitas' : 'impuestos'] = bcadd($resultado[$gratis ? 'igv_gratuitas' : 'impuestos'], $impuesto, 2);
            if (! $gratis) {
                $resultado['valor_venta'] = bcadd($resultado['valor_venta'], $base, 2);
                $resultado['descuento'] = bcadd($resultado['descuento'], bcsub($fuente['subtotal'], $fuente['total'], 2), 2);
            }
            $resultado['total'] = bcadd($resultado['total'], $fuente['total'], 2);
        }
        ReglasFiscales::exigir(count($resultado['lineas']) > 0 && bccomp($resultado['total'], $cobro->total, 2) === 0, 'Los detalles no cuadran con el total del cobro. No se puede emitir.');

        return $resultado;
    }
}
