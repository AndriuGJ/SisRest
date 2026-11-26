# Diagnóstico seguro de envíos a SUNAT

## Qué ocurría

`EnviarComprobante` capturaba `Throwable` sin conservar la excepción. Tanto un fallo de conexión/autenticación como un error al abrir, validar o guardar el CDR terminaban en el mismo mensaje de resultado incierto. El CDR se analizaba antes de guardarse: una respuesta no procesable se perdía.

Esto explica la falta de información; **no demuestra cuál fue el fallo concreto de F001-1**. Si el intento original no conservó un registro externo o archivo de respuesta, la actualización no puede reconstruirlo retrospectivamente.

## Cambios

- `FalloSunat` convierte fallos en un catálogo cerrado: DNS, TLS, tiempo de espera, conexión, autenticación/autorización SOAP, otros SOAP Fault, estructura de respuesta y fallos locales de ZIP/CDR/almacenamiento. Las coincidencias de mensajes de red son indicios, no una prueba de recepción o ausencia de recepción.
- Se extrae únicamente un código numérico de un `faultcode` con formato permitido. No se extraen números arbitrarios de `faultstring` ni `detail`. Los códigos sin descripción catalogada se conservan sin copiar texto remoto. No se interpreta un SOAP Fault como CDR de rechazo.
- El intento conserva `diagnostico_envio`, con etapa, categoría, tipo, código permitido, fecha y referencia UUID. `diagnostico_consulta` conserva el último fallo de consulta sin reemplazar el diagnóstico original.
- Los logs contienen solo esos campos y los IDs internos/entorno. Nunca se registra la excepción original, su traza, `faultstring`, `detail`, XML, SOAP, contraseñas SOL, certificados ni claves privadas. No hay excepción encadenada. Se mantiene `trace=false` y verificación TLS.
- Un CDR de hasta 2 MiB se guarda antes de analizarse en el disco **privado** `local`, bajo `comprobantes/.../recibidos/{UUID}.zip`. Se conserva la primera evidencia y su SHA-256 en el intento. Esto es un archivo de respuesta, no un log ni un CDR aprobado. No se expone por descargas públicas. Solo después de validar ZIP/XML, referencia, emisor y código se asigna `cdr_ruta` y se aplica el estado habitual. Respuestas mayores al límite no se guardan.
- Si guardar el CDR falla, se registra `cdr_guardado` y el estado permanece incierto. Las respuestas de SUNAT con CDR válido siguen distinguiendo aceptación, observación y rechazo como antes.
- No hay reintentos de envío: **la existencia de cualquier intento sigue impidiendo enviar nuevamente**. Consultas e interrupciones no eliminan el historial. No se cambia el entorno y se conserva la restricción de consulta de facturas Beta.

## Actualización

En `codex/base-restaurante`, después de obtener los cambios:

```bash
git pull origin codex/base-restaurante
php artisan migrate
php artisan optimize:clear
php artisan view:cache
```

La migración solo añade columnas anulables. No modifica intentos previos, configuraciones ni estados. No ejecutar `migrate:fresh`.

## Revisar F001-1 sin duplicarla

1. En Ventas → Comprobantes, abrir **F001-1 del entorno Beta** y revisar el intento original y su hora. Una ausencia de diagnóstico antiguo se indica explícitamente; no pulsar acciones de envío ni cambiar a Producción.
2. Desde la carpeta del proyecto ejecutar, sustituyendo `123` por el ID de un usuario activo con acceso al comprobante:

   ```bash
   php artisan facturacion:diagnosticar F001-1 --entorno=beta --usuario=123
   ```

   Es una inspección local de solo lectura. No instancia el transporte, no envía, no consulta SUNAT, no cambia estados, no registra otro intento y no carga credenciales SOL. Comprueba el SHA-256 del XML realmente enviado. Si existe evidencia capturada por esta versión, comprueba su hash y vuelve a analizarla localmente, mostrando solo el código y el diagnóstico seguro. La relectura no concilia ni acepta automáticamente el documento.
3. Si el intento es anterior, revisar **localmente** los registros ya existentes de Laravel/PHP/servidor alrededor de la hora del intento. Puede que no exista ninguna excepción registrada porque el código la descartó. No activar `trace`, no volcar configuración, XML, SOAP ni credenciales; no compartir registros completos sin depurarlos.
4. Si no existe evidencia original, conservar `desconocido`/`resultado_incierto` y conciliar manualmente en el **mismo entorno Beta** con la evidencia disponible o soporte correspondiente. La ausencia de CDR o un fallo de red no confirma que SUNAT no haya recibido el envío. No reenviar para intentar descubrir el error. No usar una consulta de Producción para resolver un envío Beta.

Para nuevos fallos, la pantalla de historial muestra la referencia UUID que permite localizar `Diagnóstico seguro SUNAT` en los logs sin copiar información sensible. Si el proceso se interrumpe antes de persistir el diagnóstico, no se inventa una causa ni se habilita un reenvío.

## Fuentes técnicas

- Implementación instalada de Greenter: `vendor/greenter/ws/src/Ws/Services/SoapClient.php` (lanza `SoapFault`; no se modifica el paquete).
- [SUNAT, Manual del programador SEE](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf): WS-Security, Beta, `sendBill`/`sendSummary`, CDR y formato de `faultcode` (anexo C.3). No se copian mensajes SOAP remotos a los logs.

## Validación

Las pruebas usan XML/CDR y certificados de prueba generados, almacenamiento simulado y transporte SOAP simulado. Cubren clasificación de errores, ausencia de secretos en logs/diagnósticos, CDR inválido y ajeno, XML inseguro, fallo de almacenamiento, conservación de evidencia e idempotencia, revisión local de intentos antiguos y permisos. No usan el comprobante real F001-1 ni credenciales del restaurante.

Validación del cambio inicial de diagnóstico: `optimize:clear` y `view:cache` correctos; suite completa **523 aprobadas, 8 omitidas, 3634 aserciones**; análisis PHPStan de los seis archivos PHP del módulo modificados, **0 errores**; formato Pint correcto. Se volvió a ejecutar el grupo unitario tras precisar la clasificación de advertencias PHP no reconocidas.

## Reprocesamiento local de un CDR capturado (F001-2)

Cuando el ZIP ya está en `comprobante_envios.cdr_recibido_ruta`, con su SHA-256 guardado, ahora se puede conciliar explícitamente una **factura** cuyo resultado quedó incierto:

1. Actualizar `codex/base-restaurante` y limpiar cachés (`php artisan optimize:clear`, `php artisan view:cache`). Este cambio no necesita otra migración; requiere las columnas de diagnóstico del cambio anterior.
2. Ir a **Ventas → Comprobantes**, abrir **F001-2 de Beta**, y en su intento pulsar **Reprocesar CDR almacenado**. Se requiere usuario activo con acceso al pedido/caja y permisos `comprobantes.ver` y `comprobantes.emitir`.
3. Confirmar la operación local. Se valida la ruta privada del comprobante, existencia, lectura limitada a 2 MiB, SHA-256, ZIP, XML, nombre esperado, RUC y referencia de factura. No se usa el transporte SUNAT, ni credenciales SOL ni certificado actual.
4. Con código `0` y notas —incluida la observación `4093`— se registra **observado**. Con código `0` sin notas, **aceptado**. Un CDR de rechazo válido conserva las reglas de rechazo existentes. Un código no definitivo, archivo alterado o CDR ajeno no modifica el documento.

El lector admite el XML esperado junto a entradas de directorios vacíos de nombre seguro (máximo 65 entradas). No extrae archivos, no permite otros archivos, rutas peligrosas ni XML con entidades/DOCTYPE. Conserva límites de tamaño y comprueba un único `ApplicationResponse` con una respuesta de documento.

El servicio es `EnviarComprobante::reprocesarCdr($actor, $comprobanteId, $envioId)`. La interfaz transmite identificadores bloqueados y el servidor vuelve a validar permisos, pertenencia y datos actuales. No permite actuar durante un envío/consulta en curso.

Resultado y auditoría se guardan en una misma transacción con bloqueo de comprobante e intento. Se enlaza el ZIP validado como CDR, se añade su mensaje al historial y se registra actor, comprobante, intento, hash, código y transición de estado. Se conservan el mensaje previo, fechas originales, XML, cobro, correlativo y diagnósticos originales. No se crea otro intento ni emisión. Repetir la misma operación válida no cambia fechas/mensajes ni genera otra auditoría. Un resultado fiscal definitivo distinto no se sobrescribe.

El comando `facturacion:diagnosticar` anterior sigue siendo de **solo lectura**: no reprocesa ni concilia. La nueva acción de la pantalla es la que registra explícitamente el resultado validado. Esta función no corrige ni vuelve a firmar el ubigeo del XML emitido; conserva la observación recibida.

Validación del reprocesamiento local: **550 pruebas en total, 542 aprobadas, 8 omitidas, 3820 aserciones**; grupo específico de facturación/diagnóstico, **82 aprobadas**. PHPStan de los tres archivos PHP modificados: **0 errores**. `optimize:clear`, `view:cache`, Pint y `git diff --check` correctos. Las pruebas incluyen recuperación con observación `4093`, aceptación sin observaciones, rechazo, alteración/ausencia/tamaño/hash/ruta del archivo, CDR ajeno, directorios inseguros, permisos, confirmación e identificadores Livewire, idempotencia, conflicto con un resultado definitivo y reversión ante fallo de auditoría.

## Consulta de tickets de boletas en SUNAT Beta

`getStatus` de resúmenes usa **BillService**, con `parameters.ticket`, en `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService`. No usa el `getStatus` de BillConsultService ni `getStatusCdr` de facturas. El WSDL local de Greenter define `SOAPAction: urn:getStatus`; la ubicación se fija explícitamente al endpoint del entorno. Se añadió una comprobación previa que bloquea la consulta si el entorno actual difiere del comprobante; nunca deriva un ticket Beta a Producción.

La clasificación anterior agrupaba errores de transporte diferentes en `conexion_red`. Ahora se distinguen:

| Tipo | Evidencia y alcance |
| --- | --- |
| `http_servidor` / `http_cliente` | El cliente nativo informa un fallo `HTTP` con un motivo estándar reconocido. `estado_http` se deduce de ese motivo (p. ej. Service Unavailable → 503); no es una captura de cabeceras ni prueba de qué servidor/intermediario lo generó. Un 401 HTTP no demuestra por sí solo una clave SOL incorrecta. |
| `http_sin_cabeceras` | Error Fetching http headers: no se recibieron cabeceras válidas. Sin estado HTTP confirmado; podría ser espera agotada, cierre remoto o intermediario. No se inventa un 500/503. |
| `soap_sunat` / `soap_autenticacion` | SOAP Fault con su código numérico permitido. `Server.200` es un **código SOAP 200**, nunca HTTP 200 ni CDR aceptado. No se reclasifica como conexión por palabras de su mensaje. |
| `soap_decodificacion` | El cliente no pudo interpretar la respuesta como XML SOAP. No confirma el resultado fiscal. |
| `soap_wsdl_local`, `entorno_inconsistente`, `error_no_clasificado` | Problema local de definición/configuración u otro error sin evidencia suficiente para atribuirlo al remoto. |
| `conexion_dns`, `conexion_tls`, `conexion_timeout`, `conexion_red` | Se conservan los indicios de DNS, TLS, tiempo de espera y conexión, sin copiar el texto original. |

Solo se guardan valores de un catálogo cerrado, código permitido, método SOAP y servicio previsto (`getStatus` / `fe_beta`), etapa y referencia. No se guardan sobres, XML, `faultstring`, `detail`, contraseñas, certificados, claves ni tickets en los logs o auditorías de diagnóstico. Se conserva `trace=false`, verificación TLS y los tiempos de espera existentes. El ticket permanece en su registro privado original.

Ante una consulta fallida, la boleta/resumen conserva su estado **enviado, pendiente de CDR verificable**, el ticket, mensaje y diagnóstico originales. Las fechas de consulta se separan del envío mediante `consulta_iniciada_at` y `consulta_finalizada_at`; no se reconstruyen fechas antiguas que versiones previas ya hubieran reemplazado. Cada nuevo fallo de consulta queda en la auditoría con actor y referencia, y `diagnostico_consulta` sigue mostrando el último fallo. No hay consultas ni reenvíos automáticos. Un `98`, un `0` o un `99` sin CDR no equivale a aceptación. Un ZIP inválido o un estado `98` acompañado contradictoriamente de un CDR tampoco acepta el documento.

Para desplegar esta ampliación:

```bash
git pull origin codex/base-restaurante
php artisan migrate
php artisan optimize:clear
php artisan view:cache
```

La migración solo añade dos fechas anulables al intento. Después se puede pulsar **Consultar resultado**, que únicamente consulta el ticket guardado. No pulsar acciones de emisión/envío, cambiar el entorno, borrar intentos ni alterar estados para resolver una indisponibilidad. El comando `facturacion:diagnosticar` permite inspeccionar el diagnóstico local sin consultar SUNAT.

### Qué puede concluirse sobre SUNAT Beta

El endpoint y la estructura de la llamada de SisRest eran correctos. La deficiencia encontrada en SisRest era la clasificación demasiado amplia de los errores y la reutilización de las fechas de envío para consultas. Descargar el WSDL mediante GET (curl/PHP) no prueba que funcione el POST SOAP `getStatus` ni el servicio interno que procesa tickets.

Existen [reportes operativos de otro integrador](https://github.com/carlosvidal/sunat-service/blob/main/README.md#11-notas-operativas) que describen `Server.200 / Failed to establish a backside connection` en `getStatus` Beta. Son un indicio externo, **no una confirmación oficial ni una comprobación de este ticket o de disponibilidad actual**. No se realizó una consulta autenticada real en este trabajo. Los antiguos `conexion_red` no permiten reconstruir cuál fue el error remoto; las nuevas consultas distinguen la evidencia disponible sin adivinar la causa.

Referencia del contrato del servicio: [documentación de Greenter, BillService y endpoints](https://fe-primer.greenter.dev/docs/webservices/), y las implementaciones instaladas `SoapClient`, `ExtService` y los WSDL de Greenter. Las pruebas usan mocks o respuestas locales del cliente SOAP nativo; no contactan SUNAT ni fabrican una aceptación operativa.

Validación de esta ampliación: suite completa **588 pruebas, 580 aprobadas, 8 omitidas, 4164 aserciones**. Después del último refuerzo para conservar el estado ante un CDR no definitivo, se repitió el grupo de facturación/diagnóstico: **121 aprobadas, 1165 aserciones**. PHPStan de los cuatro archivos PHP modificados: **0 errores**. Pint, compilación Blade y comprobación de diferencias correctos. No se consultó un ticket real ni se envió un resumen en estas pruebas.
