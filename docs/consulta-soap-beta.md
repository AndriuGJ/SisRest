# Verificación aislada del cliente SOAP de SUNAT Beta

## Resultado de la revisión

La selección del servicio y la construcción de `getStatus` son correctas. `WsdlProvider::getBillPath()` apunta al **WSDL local distribuido por Greenter**, que importa también su definición local `billService.ns1.wsdl`. Consultar por HTTPS el WSDL publicado por SUNAT no es lo mismo que ejecutar esta operación. Para tickets se usa BillService, `parameters.ticket`, SOAP 1.1 y `SOAPAction: urn:getStatus`; la ubicación se fija explícitamente a:

```
https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService
```

No se utiliza BillConsultService de facturas ni un endpoint de Producción como alternativa. La comprobación del entorno del comprobante sigue vigente.

Se encontró una limitación concreta de la configuración anterior: `connection_timeout=10` limita la conexión, pero el `http.timeout=25` del contexto **no controla la lectura de respuestas del cliente SOAP nativo**. El [manual de PHP](https://www.php.net/manual/en/soapclient.construct.php) documenta `default_socket_timeout` para esta espera y enumera las opciones HTTP de contexto admitidas.

## Configuración aplicada

| Elemento | Configuración |
| --- | --- |
| Contrato | WSDL local de Greenter, `WSDL_CACHE_NONE`, sin reutilizar una definición cacheada antigua |
| Protocolo | SOAP 1.1 sobre HTTP/1.1 |
| Conexión | `connection_timeout=10` segundos |
| Lectura | `default_socket_timeout=25`, establecido solo durante la llamada y restaurado en `finally`, incluso si falla construir el cliente |
| Persistencia de conexión | `keep_alive=false`; PHP genera `Connection: close` |
| TLS | `verify_peer=true`, `verify_peer_name=true`, `allow_self_signed=false` |
| Cabeceras | Content-Type, Content-Length, Host, Connection y SOAPAction generados por PHP/WSDL; sin duplicarlos manualmente |
| Autenticación | WS-Security de Greenter con RUC + usuario y contraseña; sin HTTP Basic |
| Trazas | `trace=false`; no se capturan ni registran sobres o credenciales |

La espera de lectura no es una promesa de duración total exacta: la operación incluye conexión, negociación y lecturas. No se cambió permanentemente `php.ini`. La configuración se concentra en `TransporteSunat::opcionesCliente()`. Si no es posible aplicar el límite, se informa `soap_configuracion_local` y no se llama al servicio. Estas opciones son del transporte compartido; no modifican XML firmados ni correlativos.

Hacer explícito el cierre de conexión y desactivar la caché del WSDL local hace el comportamiento determinista; **no demuestra que Keep-Alive o la caché fueran la causa del fallo real**.

## Qué comprueban las pruebas nuevas

`tests/Support/soap-http-fixture.php` inicia un servidor HTTP de un solo uso en `127.0.0.1`, con puerto efímero. Solo un adaptador de prueba sustituye la ubicación Beta por ese servidor. El cliente real Greenter/PHP crea y transmite la solicitud; no se sustituye `__doRequest`, no se simula el serializador ni se evita el transporte HTTP nativo.

El servidor compara en memoria el método POST y ruta, HTTP/1.1, unicidad y corrección de Content-Type, SOAPAction, Host y Content-Length, cierre de conexión, ausencia de compresión/HTTP Basic/Expect, operación única `getStatus`, ticket y WS-Security. Devuelve **solo trece booleanos de comprobación**, nunca la solicitud, sus cabeceras completas, usuario, contraseña o ticket. Las credenciales y comprobantes usados son exclusivamente de prueba; los datos de la petición no se guardan en archivos ni logs.

Las respuestas simuladas cubren:

- Estado `98`, Content-Length normal, transferencia chunked y respuesta delimitada por cierre de conexión.
- CDR válido de aceptación y rechazo, con decodificación base64 real de PHP y validación posterior de SisRest.
- HTTP 500 con un SOAP Fault `Server.200`, HTTP 503 con cuerpo no XML y HTTP 503 sin cuerpo.
- Conexión cerrada sin cabeceras, respuesta no XML y CDR de otro emisor.
- Restauración de `default_socket_timeout` después de éxito, fallo SOAP y error al construir el cliente; verificación de opciones TLS y ausencia de trazas.

Los CDR son fixtures en la base de pruebas y almacenamiento simulado, no documentos añadidos a la instalación del restaurante. Las pruebas locales HTTP no verifican la negociación TLS con SUNAT: comprueban que la aplicación mantiene las opciones de validación TLS activas.

### Una limitación importante observada

En el PHP probado, un **HTTP 503 con cuerpo no XML** puede convertirse en `Client / looks like we got no XML document`, mientras que un 503 vacío se comunica como fallo HTTP reconocible. Con `trace=false`, el primero no permite recuperar de forma fiable el estado HTTP desde la excepción. Por eso se conserva `soap_decodificacion`, sin inventar un 503, ni habilitar trazas. HTTP 500 con un Fault válido conserva el código SOAP; `Server.200` no significa HTTP 200 ni aceptación.

`http_sin_cabeceras` indica que PHP no recibió cabeceras HTTP utilizables. Puede corresponder a espera, cierre remoto, proxy o servicio Beta. Las simulaciones reproducen esa condición cerrando la conexión; **no identifican cuál de esas causas ocurrió en el intento real**.

## Conclusión operativa y actualización

No se consultó un ticket real ni se envió un resumen a SUNAT durante este trabajo. Las pruebas confirman la solicitud y procesamiento locales, pero no certifican disponibilidad ni respuesta real de `getStatus` en SUNAT Beta. Se documenta el incidente como **limitación de conectividad o del servicio Beta todavía no confirmada**. El ajuste del tiempo de espera corrige una deficiencia local, sin garantizar resolver el fallo remoto.

Mientras no exista respuesta verificable se conserva **Enviado, pendiente de CDR**, junto con el ticket, diagnóstico e historial. No se cambia a Producción, se reenvía el resumen ni se marca aceptación sin CDR válido.

Para actualizar (este cambio no añade migraciones ni dependencias):

```bash
git pull origin codex/base-restaurante
php artisan optimize:clear
php artisan view:cache
```

Tras actualizar, una consulta manual usa el mismo ticket y la configuración corregida. El diagnóstico local de solo lectura sigue disponible mediante `facturacion:diagnosticar`, sin conectarse a SUNAT.

## Validación ejecutada

- Pruebas específicas de facturación y diagnóstico: **136 aprobadas, 1466 aserciones**.
- Suite completa: **604 pruebas, 596 aprobadas, 8 omitidas, 4474 aserciones**.
- PHPStan de `TransporteSunat.php` y `FalloSunat.php`: **0 errores**.
- Pint, `optimize:clear`, `view:cache` y `git diff --check`: correctos.

La validación utiliza PHP 8.4.24 en este entorno. La representación exacta de un fallo HTTP puede variar entre versiones/runtime; las categorías no sustituyen una respuesta real verificable.
