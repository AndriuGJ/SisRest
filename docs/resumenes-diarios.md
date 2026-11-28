# Resúmenes diarios y numeración electrónica de SisRest

Desde la modalidad configurable, solo las boletas destinadas a **resumen diario** son elegibles. Las boletas nuevas de Beta pueden enviarse individualmente. Ver [configuración y pruebas de ambas modalidades](facturacion-individual-beta.md). Los documentos antiguos conservan su modalidad original.

## Funcionamiento

En **Facturación → Resúmenes diarios** se listan lotes por fecha de emisión, entorno y estado. Los indicadores separan pendientes de envío, enviados pendientes de CDR, aceptados, observados e inciertos. La tabla muestra fecha de generación, cantidad de boletas, total y ticket. El detalle permite revisar cada documento, descargar su XML firmado/CDR y consultar el historial.

1. Seleccionar fecha y entorno; marcar las boletas elegibles y revisar la selección.
2. Generar: se valida el acceso a todos los documentos, fecha común, moneda PEN, RUC y entorno de la configuración, plazo y SHA-256 de los XML de origen. Se reserva cada boleta, asigna el correlativo RC y firma un XML con una línea por boleta. **Generar no envía.**
3. Enviar desde el detalle: se verifica el hash guardado y se reserva el intento antes de llamar a Greenter `sendSummary`, fuera de la transacción. Una respuesta con ticket deja el lote **Enviado, pendiente de CDR**. No es una aceptación.
4. Consultar: `getStatus` usa únicamente el ticket de ese entorno. Sin CDR válido, conserva ticket, estado y boletas reservadas; cada respuesta o diagnóstico queda en el historial.
5. Solo un CDR que corresponda al nombre/RUC/identificador del resumen puede conciliar el lote. Código `0` sin notas: aceptado; con notas: observado. La aceptación de un CDR del resumen completo alcanza las boletas incluidas. Una respuesta ajena, incompleta, malformada o no definitiva no permite aceptarlas.
6. Ante rechazo verificable del RC, se conserva su CDR e historial y sus boletas quedan pendientes de informar; se libera la reserva para preparar un resumen corregido. No se cambia el número ni XML de las boletas. El resumen rechazado no se reenvía: se genera otro RC y debe revisarse la causa fiscal antes de enviarlo.

Se admiten hasta **500 boletas por selección** como límite operativo de esta interfaz. El alcance implementado es el alta de boletas de venta emitidas; modificaciones, bajas y notas requieren su propio flujo y no se simulan cambiando estados. Los borradores firmados se conservan y no se editan o eliminan desde esta pantalla.

## Reglas e integridad

- `resumenes_diarios`: identidad, entorno, fechas, estado, ticket privado, hashes, rutas y fechas de operación.
- `resumen_diario_detalles`: relación y número fiscal que se informó. La clave única de `comprobante_activo_id` impide reservas simultáneas incluso ante concurrencia; se libera únicamente por rechazo verificable del RC agrupado. Se mantiene el detalle histórico.
- `resumen_diario_eventos`: historial de generación, envío, consultas, CDR y diagnósticos seguros.
- UUID único para repetir una generación de la misma selección sin crear otro documento. Reutilizarlo con otra selección se rechaza.
- Bloqueo de empresa y documentos, secuencia por fecha/entorno y restricción de nombre único. Tanto la vía agrupada como la compatible unitaria comparten `secuencias_resumen`.
- Cualquier envío ya iniciado, con ticket, aceptado, rechazado o de resultado incierto impide repetir ese envío. Procesos interrumpidos se reconocen como inciertos; nunca habilitan reenvíos.
- Permisos existentes: `comprobantes.ver` para consultar y `comprobantes.emitir` para generar/enviar. Un usuario debe tener acceso a **todas** las boletas/cajas incluidas para abrir o descargar el resumen; los listados no exponen lotes parcialmente ajenos.
- Se conserva la configuración SOAP/TLS existente, sin aumentar tiempos de espera por los fallos Beta. Logs y auditorías de diagnóstico no contienen ticket, credenciales, claves, certificados, XML ni sobres SOAP. Los archivos fiscales permanecen en almacenamiento privado.

## Numeración: presentación frente a identidad firmada

`comprobantes.correlativo` sigue siendo un número entero y las series configuradas no cambian. `XmlFiscal::numero()` centraliza la presentación `F001-00000001` / `B001-00000001`; lo utilizan listados, detalle, reportes, exportaciones, PDF, tickets y resultado del cobro. Las búsquedas admiten ambas formas, con y sin ceros, y el comando de diagnóstico admite también ocho dígitos.

Para **nuevas emisiones**, el snapshot `configuracion_tributaria.correlativo_xml` conserva los ocho dígitos antes de firmar. `correlativoFiscal()`, `numeroFiscal()` y `nombre()` mantienen coherentes XML, nombre de archivo, referencias del resumen, QR y consulta fiscal. No se alteran el cálculo tributario, el cobro ni la asignación numérica de la serie.

Para **documentos anteriores sin ese snapshot**, la identidad fiscal conserva el correlativo sin relleno que usaba el emisor original. Cambia su presentación en pantalla, no el XML, SHA-256, firma, CDR ni registro de envío. El detalle y la representación impresa indican la identidad original cuando difiere. No se regenera ningún documento histórico. La secuencia RC mantiene su formato propio `RUC-RC-AAAAMMDD-correlativo` (hasta cinco posiciones en la secuencia actual); los ocho dígitos corresponden a las referencias F/B.

## Incorporación de envíos antiguos

La migración `000083` ejecuta `ImportarResumenesHistoricos`: vincula los resúmenes unitarios de `comprobante_envios` cuyo nombre tiene formato RC. Se crea una proyección con `envio_historico_id`, conservando las rutas, hashes, ticket, estado y diagnóstico de origen. **No se escribe en los comprobantes ni en los envíos originales, no se leen credenciales, no se regeneran archivos ni se contacta a SUNAT.**

La operación es idempotente y genérica, sin depender de un ticket concreto. El resumen pendiente `20568780681-RC-20261126-1` queda visible conservando su ticket original, sin publicarlo en logs. El vínculo permite abrir el historial original y descargar sus mismos archivos. Las facturas se excluyen de esta importación.

La consulta de un registro unitario vinculado sigue delegándose al servicio compatible original y su proyección se sincroniza. Los envíos unitarios compatibles que se produzcan también se incorporan al módulo; la interfaz de boletas con modalidad resumen diario orienta a la selección agrupada. Una boleta ya reservada en un RC agrupado no puede volver a enviarse por la antigua vía individual.

Si se necesita repetir solo la incorporación local:

```bash
php artisan facturacion:importar-resumenes
```

No realiza envíos ni consultas remotas. Si una identidad histórica es inconsistente, detiene su incorporación para revisión, conservando los datos originales. Nunca rebaja ni reinicia las secuencias utilizadas.

## Migraciones y actualización

- `2026_11_26_000082_crear_resumenes_diarios.php`: crea las tres tablas, relaciones, restricciones e índices.
- `2026_11_26_000083_vincular_resumenes_historicos.php`: incorpora metadata histórica de forma idempotente.

Actualizar durante una pausa de operaciones:

```bash
git pull origin codex/base-restaurante
composer install
php artisan down
php artisan migrate --force
php artisan optimize:clear
npm ci
npm run build
php artisan view:cache
php artisan up
```

No hay dependencias nuevas. No ejecutar `migrate:fresh`, borrar tablas o cambiar credenciales/certificados/entorno. Las migraciones no contactan SUNAT. La reversión del esquema se bloquea cuando ya contiene resúmenes, para no eliminar trazabilidad.

Después, abrir **Facturación → Resúmenes diarios** y verificar el registro histórico pendiente. Usar **Consultar resultado** si se desea consultar su ticket; no enviarlo otra vez. Para nuevas boletas, generar primero una selección pequeña, revisar XML y detalle y luego confirmar el envío cuando corresponda.

## Referencias y verificación de esquema

Se revisaron los documentos primarios publicados por SUNAT: [guía de resumen 2.0 / estructura 1.1](https://cpe.sunat.gob.pe/sites/default/files/inline-files/GUIA_Resumen_de_Boletas_11-01-2018%20%282%29_2_0%20%281%29_0.pdf), [reglas de boletas y plazo](https://cpe.sunat.gob.pe/tipos_de_comprobantes/boleta), y [Manual del programador, apartado 4.1](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf). Agrupación de una fecha, límite de ocho caracteres del correlativo y rechazo completo del RC se aplican conforme a esas reglas. La numeración rellena no representa un segundo comprobante.

También se descargó el [paquete XSD publicado en Guías y Manuales](https://cpe.sunat.gob.pe/sites/default/files/inline-files/Archivos%20XSD%20%281%29.zip). El XML agrupado firmado se validó con `2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd` y sus imports locales, además de verificar criptográficamente su firma con Greenter. El archivo XSL 2.0 publicado admite referencias `Bxxx` con uno a ocho dígitos, excluyendo un correlativo formado solo por ceros.

La prueba permite repetir la validación XSD sin descargas de red durante el test, indicando la ruta a ese esquema local:

```bash
SISREST_RESUMEN_XSD='/ruta/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd' php artisan test tests/Feature/Facturacion/EmisionTest.php --filter='multiple summary lines'
```

Esto no sustituye la validación tributaria remota. La conectividad de `getStatus` Beta continúa siendo una limitación no confirmada; no se fabrica un CDR, se simula aceptación operativa ni se usa Producción como alternativa.

## Archivos

Creados:

- Modelos `app/Models/ResumenDiario.php`, `ResumenDiarioDetalle.php`, `ResumenDiarioEvento.php`.
- Servicios `app/Services/Facturacion/GestionResumenes.php`, `AccesoResumenes.php`, `ImportarResumenesHistoricos.php`.
- `app/Console/Commands/ImportarResumenesDiarios.php`.
- `app/Livewire/Facturacion/ResumenesDiarios.php` y `ResumenDiarioPantalla.php`.
- `app/Http/Controllers/Facturacion/ArchivoResumenController.php`.
- Vistas `resources/views/livewire/facturacion/resumenes.blade.php` y `resumen-diario.blade.php`.
- Las dos migraciones descritas y este documento.

Modificados:

- `XmlFiscal.php`, `XmlResumen.php`, `EmitirComprobante.php`, `EnviarComprobante.php`, `TransporteSunat.php` y `RepresentacionFiscal.php` en `app/Services/Facturacion`.
- `app/Models/Comprobante.php`, `app/Livewire/Facturacion/Comprobantes.php`, `app/Console/Commands/DiagnosticarComprobante.php`.
- `routes/web.php`, sidebar, detalle del comprobante y resultado de cobro (solo formato de número).
- `tests/Feature/Facturacion/EmisionTest.php` y `tests/Support/fiscal-concurrency.php`.

No se modifica la lógica de pedidos, ventas, caja ni POS; su referencia visible usa el formateador común.

## Resultado de la verificación

- Suite completa `php artisan test --compact`: **627 pruebas, 619 aprobadas, 8 omitidas, 0 fallos; 4656 aserciones**. Las omisiones ya pertenecen a la suite y no se eliminó ninguna prueba.
- Análisis PHPStan de las clases PHP creadas/modificadas en `app`: **0 errores**.
- `php artisan view:cache` y `npm run build`: correctos.
- `tests/Support/fiscal-concurrency.php`: **10 comprobaciones aprobadas** en MariaDB, base desechable `sisrest_test`, incluyendo reservas concurrentes, secuencias, envío único y consulta única. El mismo script ya forma parte del flujo existente de CI con MySQL 8.4.
- XML de dos boletas: firma criptográfica y esquema oficial SUNAT verificados, además de respuestas SOAP simuladas de envío/consulta. No se hicieron envíos ni consultas de tickets reales. La aceptación operativa en Beta sigue pendiente de una respuesta remota verificable.
