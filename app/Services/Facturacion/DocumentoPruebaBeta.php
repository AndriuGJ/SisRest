<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/** In-memory diagnostic fixture. Never saves a sale, charge, series or fiscal record. */
final class DocumentoPruebaBeta
{
    public function crear(string $tipo, string $serie, int $correlativo, string $ruc): Comprobante
    {
        ReglasFiscales::exigir(in_array($tipo, ['01', '03'], true) && $correlativo > 0 && $correlativo <= 99999999 && preg_match($tipo === '01' ? '/^F[A-Z0-9]{3}$/D' : '/^B[A-Z0-9]{3}$/D', $serie) === 1, 'Documento de prueba inválido.');
        $boleta = $tipo === '03';
        $total = $boleta ? '10.00' : '118.00';
        $base = $boleta ? '8.47' : '100.00';
        $igv = $boleta ? '1.53' : '18.00';
        $c = new Comprobante([
            'entorno' => 'beta', 'correlativo' => $correlativo, 'fecha_emision' => CarbonImmutable::now('America/Lima')->toDateString(), 'moneda' => 'PEN',
            'estado' => 'emitido', 'estado_sunat' => 'pendiente', 'valor_venta' => $base, 'impuestos' => $igv, 'operaciones_gratuitas' => '0.00', 'descuento' => '0.00', 'total' => $total,
            'emisor' => ['ruc' => $ruc, 'razon_social' => 'SISREST PRUEBA BETA SIN VALIDEZ', 'nombre_comercial' => 'PRUEBA BETA', 'direccion' => 'AV PRUEBA 100', 'ubigeo' => '150101', 'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA'],
            'receptor' => ['tipo' => $boleta ? '1' : '6', 'numero' => $boleta ? '12345678' : '20131312955', 'nombre' => 'CLIENTE PRUEBA BETA', 'direccion' => 'AV PRUEBA 100'],
            'configuracion_tributaria' => ['modalidad_envio' => 'individual', 'tipo' => $tipo, 'serie' => $serie, 'correlativo_xml' => str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT), 'entorno' => 'beta', 'tasa' => '18.0000', 'gravadas' => $base, 'exoneradas' => '0.00', 'inafectas' => '0.00', 'igv_gratuitas' => '0.00', 'fecha_hora' => CarbonImmutable::now('America/Lima')->toIso8601String(), 'leyenda' => $boleta ? 'SON DIEZ CON 00/100 SOLES' : 'SON CIENTO DIECIOCHO CON 00/100 SOLES'],
        ]);
        $c->setRelation('detalles', new Collection([new ComprobanteDetalle(['id' => 1, 'codigo_producto' => 'BETA-TEST', 'descripcion' => 'SERVICIO DE PRUEBA BETA SIN VALIDEZ', 'unidad_sunat' => 'NIU', 'cantidad' => '1', 'valor_unitario' => $base, 'precio_unitario' => $total, 'base_imponible' => $base, 'tipo_afectacion_igv' => '10', 'tasa_impuesto' => '18', 'impuestos' => $igv, 'total' => $total, 'valor_referencial' => '0', 'descuento' => '0'])]));

        return $c;
    }
}
