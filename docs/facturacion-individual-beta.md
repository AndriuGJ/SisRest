# Facturación individual y resúmenes: configuración y pruebas

## Diagnóstico

SisRest tenía `greenter/lite 4.3.1` y decidía la operación SOAP exclusivamente por el tipo `03`: toda boleta se convertía en un RC. Esto explica por qué dependía de `getStatus`, aun cuando el envío individual probado fuera del sistema devolvía un CDR inmediato. El fallo de consulta de un RC no demuestra que el XML de una factura o de una boleta individual sea incorrecto.

Además, el lector del CDR solo admitía la referencia corta `Fxxx-correlativo` al quitar el prefijo `01-`; ahora hace lo mismo con `03-` para las boletas. El ZIP y la referencia deben corresponder exactamente al documento y al RUC: no se omite esa validación para aceptar cualquier respuesta.

Greenter 5.5.0 sustituye a Lite, no puede instalarse junto con Lite 4.x. Se ejecutó primero el `require --dry-run`, se comprobó ese conflicto y después se simuló y aplicó la sustitución. Versiones resultantes: `greenter/greenter 5.5.0`, `greenter/gre-api 1.0.2`, `greenter/xmldsig 6.0.0`. Se mantienen Laravel y los requisitos de PHP; no se utilizó `--ignore-platform-reqs`. Composer declara explícitamente las extensiones SOAP, OpenSSL, DOM, XML, ZIP, cURL, mbstring y zlib. No se agregó laravel-greenter.

## Modalidades de envío

En **Configuración → Facturación** se guardan por separado:

- **Beta:** inicialmente individual para las nuevas boletas.
- **Producción:** inicialmente resumen diario. La opción individual exige confirmar que el sistema de emisión autorizado del negocio permite esa modalidad. La casilla es una declaración del administrador; no sustituye la autorización de SUNAT ni elimina el error 0111.

Al emitir se congela `configuracion_tributaria.modalidad_envio`. Cambiar la configuración solo afecta emisiones futuras. Ausencia de ese campo significa **boleta histórica por resumen**, sin migrar ni modificar su snapshot. Las facturas continúan siendo individuales.

| Documento | Operación | Resultado verificable |
|---|---|---|
| Factura individual | `sendBill` | ZIP del CDR correspondiente |
| Boleta individual | `sendBill` | ZIP del CDR correspondiente |
| Resumen diario | `sendSummary`, después `getStatus` | Ticket y posteriormente CDR del RC |

`XmlFiscal` sigue utilizando `See` para generar y firmar los Invoice UBL 2.1. `TransporteSunat` conserva el cliente SOAP de Greenter con opciones TLS explícitas y envía el XML ya firmado, equivalente a la operación de `See::sendXml(Invoice::class, ...)`, sin volver a generarlo. Se mantuvo este límite de transporte para conservar el ZIP recibido antes de interpretarlo y el diagnóstico seguro de los SOAP Fault, incluso cuando el CDR no pueda procesarse. No se crea una segunda conexión fiscal paralela.

El transporte distingue un nombre RC de un nombre de comprobante individual; el tipo `03` por sí solo ya no determina `sendSummary`. Las respuestas individuales sin CDR y los tickets inesperados no se convierten en aceptaciones. El lector propio comprueba ZIP, tamaño, XML, emisor, identidad y código. Código 0 con notas queda observado; sin notas, aceptado. Un CDR definitivo de rechazo conserva su contenido y causa. Un fallo HTTP/SOAP o de lectura mantiene el resultado incierto y bloquea repetir el envío.

Las boletas individuales, incluidas las pendientes o inciertas, no son elegibles para el alta en un RC. Un RC ya reservado tampoco puede enviarse por la vía individual. No se modifica el RC histórico pendiente ni su ticket; sigue consultándose en **Facturación → Resúmenes diarios**.

## Uso desde SisRest

1. Guardar la modalidad del entorno y cargar el certificado/credenciales correspondientes.
2. Emitir desde el cobro o POS como hasta ahora. Se conserva la venta pagada y se firma el documento con los importes, descuentos, medios de pago e impuestos reales.
3. Abrir su detalle en **Ventas → Comprobantes**. Una boleta individual ofrece **Enviar a SUNAT**, igual que una factura. Una boleta por RC ofrece **Incluir en resumen diario**.
4. Revisar el estado SUNAT y el historial. El estado del pago es independiente y no se repite el cobro para resolver un error electrónico.

No se introduce un envío automático en el cierre del cobro: la atención, liberación de la mesa e impresión conservan su comportamiento. Para enviar sin esperar la red en la página existe **Enviar en cola** para documentos individuales. Reutiliza el mismo servicio y las mismas reservas, lleva solo IDs y tiene un intento automático. Mantener un trabajador:

```bash
php artisan queue:work database --queue=sunat --tries=1 --timeout=60
```

La cola usa la conexión `database` explícita, incluso si el proyecto tiene `QUEUE_CONNECTION=sync`. Tras terminar, pulsar Actualizar en el detalle. Sin trabajador, el documento permanece pendiente. Aunque se duplique una tarea, el intento persistido impide repetir el envío remoto. No usar `queue:retry` como sustituto de una conciliación de un envío incierto.

Los documentos individuales Beta inciertos no muestran una consulta a un endpoint de Producción. Si se capturó un CDR, **Reprocesar CDR almacenado** funciona también para boletas individuales: comprueba el SHA-256, conserva el diagnóstico original y no realiza tráfico a SUNAT.

## Certificados y credenciales

Se admiten PEM con certificado y clave privada, P12 y PFX. Se comprueba vigencia, presencia de la clave y correspondencia con el certificado. Un PEM protegido utiliza la contraseña indicada. El archivo original permanece cifrado en almacenamiento privado. No se copia a `public/` ni se agrega a Git.

Un P12 antiguo con RC2 que OpenSSL 3 no pueda leer debe convertirse localmente de forma controlada. La aplicación no activa proveedores legacy globalmente, no cambia OpenSSL ni desactiva TLS. Los errores de importación indican ese posible caso sin mostrar la clave.

Ingresar el usuario SOL **sin anteponer el RUC**. La composición se hace una sola vez. El servicio rechaza localmente credenciales vacías o con el RUC duplicado. El diagnóstico de 0111 indica revisar la autorización del emisor/usuario para el servicio; no marca aceptado ni cambia de entorno.

## Pruebas aisladas Beta

Los comandos siguientes **no leen la configuración comercial ni escriben en tablas comerciales**. Usan documentos transitorios con `XmlFiscal`, `CertificadoFiscal`, `TransporteSunat` y `LeerCdr`, los mismos servicios de SisRest. Las series por defecto son `FTS1` para facturas, `BTS1` para boletas individuales y `BTR1` para las referencias de los resúmenes. Mantienen secuencias propias bajo bloqueo de archivo. Se puede indicar `--serie` si se requiere otra serie exclusivamente de pruebas.

Se requiere un certificado de pruebas vigente proporcionado localmente. No se suministra un certificado privado en el repositorio ni se utiliza el certificado comercial sin indicarlo explícitamente. Para estas pruebas, desde Bash:

```bash
export SUNAT_TEST_CERTIFICADO='/ruta/privada/certificado-de-pruebas.pem'
export SUNAT_TEST_USUARIO_SOL='MODDATOS'
export SUNAT_TEST_CLAVE_SOL='moddatos'
# Solo si el certificado está protegido:
export SUNAT_TEST_CLAVE_CERTIFICADO='contraseña-local-del-certificado-de-pruebas'
```

En PowerShell se asignan con `$env:SUNAT_TEST_CERTIFICADO = 'C:\ruta\privada\certificado-pruebas.pem'`, y de la misma forma las otras variables. No copiar secretos reales a comandos compartidos, capturas ni Git. Las variables deben estar exportadas en la consola que ejecuta Artisan; no dependen de la caché de configuración comercial.

Primero, generar sin red:

```bash
php artisan sunat:test-factura
php artisan sunat:test-boleta
php artisan sunat:test-resumen
```

Enviar pruebas **nuevas** exclusivamente a Beta:

```bash
php artisan sunat:test-factura --enviar
php artisan sunat:test-factura --enviar
php artisan sunat:test-boleta --enviar
php artisan sunat:test-boleta --enviar
php artisan sunat:test-resumen --enviar
```

La factura de prueba usa base 100.00, IGV 18.00, total 118.00; la boleta, base 8.47, IGV 1.53, total 10.00. Esos importes y la tasa de 18% existen únicamente en la fixture de diagnóstico. La facturación comercial sigue usando su cálculo y tasa configurada.

Cada ejecución reserva un número nuevo: no reenvía el XML de una ejecución anterior, ni siquiera si la red falló. Los XML, SHA-256, estado, ticket, CDR y diagnóstico se conservan en `storage/app/private/sunat-pruebas-beta/{RUC}/` (o la raíz configurada del disco local). No borrar ese directorio para reiniciar números. No hay opción para usar Producción.

El comando imprime la ruta relativa privada del JSON del resumen. Consultar **ese ticket de prueba**, sin reenviarlo:

```bash
php artisan sunat:test-ticket --archivo='sunat-pruebas-beta/20000000001/20000000001-RC-AAAAMMDD-N.json' --enviar
```

Sustituir el nombre por el JSON que indicó el comando, no por el registro de un RC comercial. Se comprueban entorno, RUC, ticket e integridad del XML. Sin `--enviar` solo revisa localmente. Una consulta fallida conserva el ticket y estado enviado, agrega su diagnóstico y devuelve fallo de consola: no significa rechazo fiscal. La aceptación solo se muestra al verificar un CDR real. Los cuatro alias llaman a `sunat:probar {factura|boleta|resumen|ticket}`; no tienen implementaciones paralelas.

## Actualización y compatibilidad

```bash
php artisan down
git pull origin codex/base-restaurante
composer install
composer check-platform-reqs
php artisan migrate --force
php artisan optimize:clear
npm ci
npm run build
php artisan view:cache
php artisan up
```

Migración agregada: `2026_11_28_000084_modalidad_envio_boletas.php`, tres campos de configuración. No actualiza comprobantes ni envíos existentes. No cambia números, XML, CDR, nombres, hashes, credenciales, certificados ni tickets históricos. Continúan los ocho dígitos y los correlativos enteros. No ejecutar `migrate:fresh`.

Después de actualizar, comprobar la modalidad de Beta en Configuración y emitir una boleta **nueva**. Las boletas antiguas seguirán en su modalidad RC. No se ofrece cambiarles la modalidad ni emitir otra boleta por el mismo cobro para sortear un error.

## Límites y validación pendiente

No se realizaron envíos reales ni consultas del ticket comercial durante esta implementación: no se proporcionó un certificado dedicado a las pruebas remotas. Las respuestas de las pruebas automatizadas son simuladas y no representan aceptaciones reales de SUNAT. La prueba de Producción queda separada, pendiente de autorización SOL, certificado y validación operativa.

Los fallos de `getStatus` Beta pueden persistir para los RC; el envío individual reduce esa dependencia únicamente para nuevas boletas configuradas así. No se aumentaron arbitrariamente los tiempos de espera.

`composer audit` detectó 16 avisos en el lock anterior y 7 tras actualizar. Los siete restantes afectan paquetes Symfony ya presentes (http-kernel, mailer, routing y serializer); no se actualizan aquí módulos ajenos para resolverlos. No se ocultaron avisos con exclusiones.

Fuentes primarias consultadas: [Greenter: XML y envío](https://greenter.dev/usage/), [Greenter: BillSender y SummarySender](https://greenter.dev/packages/ws/), [SUNAT: anexo 6, métodos SendBill/SendSummary/GetStatus](https://www.sunat.gob.pe/legislacion/superin/2019/anexoVI-114-2019.pdf). La posibilidad técnica de enviar en Beta no acredita por sí misma la habilitación de la empresa en Producción.

## Archivos principales

Creados: `ModalidadBoletas.php`, `DocumentoPruebaBeta.php` en servicios de facturación; `app/Console/Commands/ProbarSunatBeta.php`; `app/Jobs/EnviarComprobanteSunat.php`; migración 000084 y esta guía.

Modificados: `composer.json`, `composer.lock`, `.env.example`; modelo `ConfiguracionFacturacion`; servicios `ConfigurarFacturacion`, `CertificadoFiscal`, `EmitirComprobante`, `EnviarComprobante`, `TransporteSunat`, `FalloSunat`, `LeerCdr`, `XmlFiscal`, `GestionResumenes`; componentes `ConfiguracionFiscal`, `ComprobantePantalla`, `Comprobantes`; vistas de configuración, listado y detalle de comprobantes; guía de resúmenes, pruebas `EmisionTest.php` y `fiscal-concurrency.php`.

## Resultados de las pruebas de esta implementación

- Suite completa: **650 pruebas, 642 aprobadas, 8 omitidas, 0 fallos; 4793 aserciones**. Se conservaron las pruebas existentes y se añadieron escenarios de envío individual, CDR, 0111, HTTP, timeout, PEM, modalidades, cola, recuperación local y comandos aislados.
- PHPStan de las clases modificadas/creadas: **0 errores**. Pint correcto.
- Concurrencia real en MariaDB, exclusivamente en la base desechable `sisrest_test`: **11 comprobaciones aprobadas**. Incluyen correlativos, reservas, RC unitario/agrupado, consulta única e individual enviado una sola vez ante un CDR incierto. No se ejecutó `migrate:fresh`.
- Vistas compiladas, `npm run build`, validación de Composer y extensiones: correctos. El aviso por fijar Greenter exactamente en 5.5.0 es intencional para reproducir la versión solicitada.
- Entorno de ejecución local de esta revisión: PHP 8.4.24 compatible con el requisito `^8.4.1` del proyecto; no se afirma haber ejecutado aquí PHP 8.5.12. La validación final en la máquina del usuario forma parte de los comandos anteriores.
- Tráfico real SUNAT: **no realizado**, por no disponer de un certificado dedicado a esas pruebas. Ningún CDR simulado se incorporó a documentos comerciales.
- Revalidación externa del paquete XSD en esta revisión: la descarga pública de SUNAT devolvió HTTP 503. No se presenta como una validación XSD nueva; la comprobación previa documentada en la guía de resúmenes pertenece a la implementación anterior. Las pruebas actuales verifican la estructura, tasas, firma y procesamiento de CDR de forma aislada.
