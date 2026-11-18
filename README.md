# SisRest

Base de un sistema de restaurante para un local, con dos cajas, atención en mesas, venta para llevar y delivery.

## Estado

Incluye la base de datos, dependencias y los módulos de **Usuarios y permisos**, **Salones y Mesas**, **Destinos de preparación**, **Impresoras**, **Insumos**, **Productos para la venta**, **Recetas** y **Preparación diaria** implementados con Livewire y Spatie Laravel Permission. Conserva la plantilla Laravel + Livewire + Flux. Las compras, atención, cobros y demás módulos operativos se implementarán en las siguientes entregas.

Las tablas soportan preparación real, reservas de existencias, combos por componentes, adicionales, cuentas divididas, pagos combinados, devoluciones y estados tributarios independientes. La presencia de estas tablas no significa que ya existan los servicios de confirmación de compras, cobros o emisión fiscal. La confirmación de preparación diaria ya está implementada.

## Usuarios y permisos

- `/usuarios`: búsqueda por nombre, usuario o correo; filtros por rol y estado; paginación.
- Creación y edición mediante formularios; un rol por usuario desde esta pantalla.
- Correo opcional, usuario normalizado en minúsculas y contraseña inicial de al menos 12 caracteres.
- Activación y desactivación con confirmación, conservando el historial.
- Restablecimiento de contraseña con confirmación y cierre de sesiones anteriores. Si se restablece la propia contraseña, se regresa al login.
- `/roles`: consulta de usuarios asignados y cantidad de permisos.
- Creación de roles y edición de sus permisos agrupados por módulo, con búsqueda y selección por grupo.
- El catálogo con etiquetas legibles está en `config/permisos.php` y alimenta el seeder.
- Nombres de los cuatro roles del sistema conservados; sus permisos son configurables.
- Autorización en rutas, acciones Livewire y servicios. No es suficiente mostrar u ocultar botones.
- La administración completa requiere ambos permisos de gestión de usuarios y roles, y puede configurar todos los permisos disponibles. Las cuentas con acceso delegado no pueden otorgar ni modificar permisos superiores a los propios.
- Se bloquea la desactivación o cambio de rol de la propia cuenta desde Usuarios.
- Se conserva al menos un usuario activo que pueda administrar usuarios y roles.
- Cambios de acceso serializados en transacciones y registrados en auditoría; las contraseñas no se incluyen en los registros.

Los accesos aparecen en el sidebar según `usuarios.gestionar` y `roles.gestionar`. No se eliminan usuarios desde estas pantallas.

## Salones y Mesas

Accesos desde el sidebar, en **Configuración del restaurante**, con el permiso `configuracion.restaurante`. No hay una segunda navegación entre módulos en la cabecera; se retiró también de Usuarios y Roles.

- `/configuracion/restaurante/salones`: búsqueda, filtro por estado, paginación, registro y edición de nombre, código, orden de visualización y estado. Cada fila permite consultar las mesas del salón.
- `/configuracion/restaurante/mesas`: búsqueda, filtros por salón y estado, paginación, registro y edición de número/nombre de mesa y capacidad opcional.
- Código de salón único, normalizado en minúsculas. Número/nombre de mesa único **dentro del salón**, normalizado en mayúsculas. Dos salones pueden tener una mesa 01.
- Activación/desactivación con SweetAlert2. Los formularios muestran errores junto a sus campos.
- Desactivar un salón conserva el estado individual de sus mesas. Para la futura atención se requiere que **el salón y la mesa estén activos**.
- Se bloquea habilitar una mesa en un salón inactivo. No hay eliminación de salones ni mesas desde estas pantallas.
- Una ocupación registrada o un pedido que aún no esté cerrado/anulado impide editar, mover o desactivar la mesa y desactivar su salón. Incluso un pedido cerrado mantiene bloqueada la mesa si no se liberó su ocupación.
- Las comprobaciones se repiten al guardar dentro de transacciones, con auditoría del responsable y valores anteriores/posteriores.
- El seeder crea Principal solo cuando no existen salones; no vuelve a crearlo si fue renombrado.

Estas pantallas configuran el catálogo. Abrir, atender, cerrar y liberar mesas corresponde al futuro POS. El contrato de bloqueo para ese módulo está en `docs/contratos-operativos.md`.

Salones y Mesas utilizan las tablas iniciales, sin migraciones adicionales para esas pantallas.

## Destinos de preparación e Impresoras

Accesos desde **Config. restaurante** en el sidebar, sin navegación repetida en las cabeceras.

- `/configuracion/restaurante/destinos`: requiere `configuracion.restaurante`. Permite buscar, crear, renombrar y activar/desactivar Cocina, Barra y otros destinos. El código es único, en minúsculas y se conserva después de crear.
- No se desactiva un destino con productos activos asignados, cantidades pendientes de preparación/entrega o una impresión pendiente/en curso. Al desactivarlo se conserva la configuración de sus impresoras.
- `/configuracion/restaurante/impresoras`: requiere **`impresoras.gestionar`**, independiente del permiso anterior. Permite buscar, filtrar, crear y configurar impresoras ESC/POS por red.
- Campos: código estable, nombre, IPv4 literal, puerto (9100 por defecto), papel de 80 o 58 mm, uso y estado. Se permite guardar una impresora inactiva sin IP; activarla requiere configuración válida.
- Uso **Caja**: seleccionar una caja. Guardar sustituye su asignación anterior y retira la impresora de su caja previa; no comparte la misma impresora entre dos cajas. La asignación anterior se informa en el selector y se registra en auditoría.
- Uso **Preparación**: seleccionar un destino; se admite una impresora activa por destino. Uso **General**: sin asignación fija, disponible para selección manual futura.
- No se duplican impresoras activas en la misma IP y puerto. Un destino inactivo no permite activar su impresora ni enviarle una prueba.
- Activación/desactivación y pruebas requieren confirmación SweetAlert2. Guardar la configuración no imprime.

### Prueba de impresión

El botón de impresora envía un ticket ESC/POS sin valor comercial, con nombre, conexión, ancho, fecha, avance y corte. Sale **desde el servidor PHP**, no desde la tablet. El servidor debe tener acceso a la red de la impresora. La versión alojada en hosting requerirá un servicio local de impresión, todavía pendiente; no se incluye un puente al hosting en esta entrega.

La migración `2026_11_18_000058_create_pruebas_impresion_table.php` registra las pruebas separadas de los trabajos operativos: responsable, UUID único, configuración utilizada, estado y mensaje. **Ejecutar `php artisan migrate` al actualizar.** No se modifican migraciones anteriores ni se generan ventas/comprobantes al probar.

- Cada confirmación se procesa una sola vez por UUID, incluso si se repite la petición. Una nueva prueba explícita crea otro UUID.
- La configuración se comprueba otra vez al aceptar; si cambió desde la confirmación, debe solicitarse una nueva prueba.
- La conexión TCP y escritura tienen límites de tiempo. Se gestionan escrituras parciales sin reenviar el contenido ya escrito. No hay reintentos automáticos.
- `enviado`: se enviaron los bytes, **no certifica que salió papel**. `error`: no se pudo generar/conectar. `resultado_incierto`: el envío pudo llegar parcialmente; comprobar papel antes de repetir.
- Una prueba interrumpida queda registrada y se considera sin confirmar al pasar un minuto; la siguiente operación de configuración actualiza ese estado sin volver a imprimir.
- Mientras haya una prueba o trabajo operativo pendiente/en curso, no se permite modificar la impresora. La red se utiliza después de registrar y cerrar la transacción de la prueba.
- La pantalla muestra las diez pruebas más recientes. También se registra el resultado en auditoría, sin volcar errores internos al usuario.

Se verificaron los bytes ESC/POS contra un receptor TCP local y el flujo de navegador. La compatibilidad física, corte y avance de la POS80 se deben comprobar en el equipo del restaurante. Las comandas, precuentas y comprobantes operativos se conectarán a estas asignaciones al implementar sus módulos.

## Inventario: Insumos y Productos para la venta

Accesos desde **Inventario** en el sidebar, con búsqueda, filtros, paginación y confirmaciones SweetAlert2. Los formularios se abren en su propia pantalla para facilitar las presentaciones, fotos y componentes.

### Insumos

`/inventario/insumos` consulta únicamente materia prima. Requiere `inventario.ver`; registrar requiere además `insumos.crear`, y editar/activar/desactivar requiere `insumos.editar`.

Campos: código, nombre, unidad de inventario, existencia mínima, estado y presentaciones opcionales. Ejemplo: arroz en gramos, mínimo 5000 g y un saco que contiene 50000 g. El código y la unidad se conservan después de crear; el nombre, mínimo y presentaciones se pueden actualizar.

El listado muestra disponible (actual menos reservado), unidad y mínimo. El filtro de nivel bajo incluye cantidades disponibles menores o iguales al mínimo, siempre que el mínimo sea mayor que cero. Los preparados y productos de reventa se administran desde Productos para la venta.

### Productos

`/inventario/productos` requiere `productos.ver`; los formularios requieren además `productos.crear` o `productos.editar`.

- Tipos: plato, bebida, reventa, menú fijo, adicional y combo. Código, nombre, categoría, descripción opcional, precio final en soles, fotografía, estado y visibilidad en POS.
- Platos y menús generan existencias mediante preparación diaria; reventa se compra lista. Bebidas y adicionales permiten elegir el origen.
- Cada producto que no sea combo obtiene automáticamente su propio artículo de inventario. No se solicita crear ese artículo por separado. El código interno usa `PV-` seguido del código del producto; por ello los códigos nuevos admiten hasta 37 caracteres.
- Código, tipo, origen y unidad de venta se conservan después de crear para mantener la identidad y los saldos. Actualizar nombre, precio, imagen o visibilidad no reinicia existencias, reservas ni costo promedio.
- La unidad de venta es una unidad o porción. El destino puede ser un área activa o **Entrega directa**, para productos que no requieren comanda.
- Las fotos admiten JPG/PNG/WebP de hasta 2 MB. Se almacenan en el disco público; reemplazar o quitar una foto retira el archivo anterior. Un error de validación no deja archivos ni artículos parciales.
- Los combos no tienen artículo ni existencias propias. Se configuran por productos no combo y cantidades positivas, sin duplicados ni anidación. Un combo activo requiere al menos un componente habilitado.
- Disponible normal = existencia actual menos reservada si está habilitado. Disponible del combo = menor cantidad entera que permiten sus componentes, respetando sus reservas y destinos activos.

### Presentaciones y protección de operaciones

El editor de presentaciones se reutiliza en Insumos y reventa: nombre, equivalencia en la unidad del artículo y estado. Admite hasta veinte presentaciones; no borra las utilizadas. Una presentación ya incluida en compras conserva su factor y debe reemplazarse por otra si cambia el contenido del paquete. Nunca puede asignarse una presentación de otro artículo.

Se bloquea desactivar artículos con reservas o utilizados por recetas activas, y productos incluidos en combos activos o pedidos en curso. Los estados se vuelven a comprobar al confirmar/guardar. Los cambios quedan auditados.

**Este módulo configura el catálogo y consulta cantidades. No permite escribir saldos manualmente ni inventa ingresos.** Las recetas y preparación diaria ya tienen sus pantallas y servicios descritos abajo; el ingreso de insumos mediante Compras sigue pendiente. Los menús son productos fijos, sin elección dinámica de entrada y segundo.

### Actualización de una instalación existente

~~~console
php artisan optimize:clear
php artisan migrate
php artisan storage:link
npm run build
~~~

`storage:link` se necesita una sola vez para servir las fotografías. La migración `000059` agrega `insumos.crear` e `insumos.editar`: en instalaciones existentes los concede una vez a los roles/usuarios que ya tenían `inventario.ajustar`. Los lectores no reciben gestión. Después pueden ajustarse desde Roles; repetir seeders no restablece permisos revocados. Las instalaciones nuevas reciben los 62 permisos del catálogo para el administrador inicial.

## Recetas y Preparación diaria

### Recetas

Desde **Productos para la venta**, usar el botón de receta de un producto preparado. La ruta `/inventario/productos/{producto}/receta` requiere `productos.ver` y `recetas.gestionar`.

Definir el rendimiento y los ingredientes correspondientes a ese rendimiento. Ejemplo ilustrativo: para 10 porciones se registran las cantidades de arroz, pollo y huevo en sus unidades de inventario; al preparar 5, se utiliza la mitad. Se admiten hasta 50 ingredientes, sin repetidos, sin consumir el propio producto y sin dependencias circulares. Un preparado puede ser ingrediente de otro: se consume su existencia preparada, sin volver a consumir su materia prima.

Guardar una receta no mueve existencias. Las ediciones se auditan y se bloquea sobrescribir una versión cambiada desde otra sesión. La receta puede desactivarse; sus preparaciones guardadas conservan cantidades y datos anteriores.

### Flujo de preparación

Acceso **Preparación diaria** en el sidebar (`/inventario/preparaciones`), con historial, búsqueda por código/producto, filtros de estado y fechas y paginación.

1. **Nueva preparación**: elegir producto con receta activa, cantidad y fecha no futura.
2. **Calcular ingredientes**: aplica la receta vigente en las unidades base de cada artículo. Se utilizan cuatro decimales y se redondea el consumo hacia arriba para evitar consumos cero por pérdida de precisión.
3. Revisar el consumo estándar y el real. Cambiar cantidades requiere `preparaciones.ajustar_consumos` y un motivo de al menos cinco caracteres. Se admite omitir un ingrediente en ese lote, pero no registrar todos los consumos en cero.
4. **Guardar borrador**: no consume ni reserva insumos y no produce existencias. Se guarda una instantánea de nombres, unidades, rendimiento y cantidades.
5. Desde el detalle, **Confirmar producción** con SweetAlert2. Se vuelven a comprobar permisos, estado del borrador, productos/ingredientes activos y cantidades disponibles descontando reservas.
6. En una sola transacción, descontar consumos reales, registrar movimientos de consumo, ingresar la cantidad producida, registrar su movimiento y guardar responsable, fecha y costos de confirmación.

Ejemplo: diez chaufas confirmados ingresan diez porciones y descuentan los ingredientes realmente usados. Guardar precios o cobrar después no vuelve a consumir esos ingredientes. Las existencias permanecen entre días; filtrar por fecha no las reinicia.

### Costos, historial y correcciones

Los costos de ingredientes se toman del costo promedio vigente **al confirmar**, no de valores enviados por el navegador. Los importes se calculan con BCMath a seis decimales; el artículo producido actualiza su costo promedio ponderado considerando las existencias previas. Los costos del lote quedan fijos en sus detalles e historial y solo se muestran con `costos.ver`.

Un borrador conserva la receta con la que fue guardado y puede confirmarse con esa instantánea aunque luego cambie la definición estándar, mientras la receta siga activa. Al editarlo/recalcularlo se utiliza la receta vigente y se restablecen consumos estándar; guardar exige revisar el nuevo cálculo. Las revisiones simultáneas de recetas y borradores y las confirmaciones sobre borradores cambiados se rechazan.

Las preparaciones confirmadas son de solo lectura. Esta entrega **solo anula borradores**, con motivo, sin movimientos ni restituciones automáticas. Las correcciones de producción confirmada se definirán mediante los movimientos de inventario que correspondan; no se devuelve materia prima usada automáticamente.

### Permisos y actualización

- `preparaciones.ver`: historial y detalle.
- `preparaciones.crear`: nuevos borradores, edición y anulación de los propios. Con `preparaciones.confirmar` también se pueden administrar borradores de otros responsables.
- `preparaciones.confirmar`: confirmar la producción. No necesita permiso para modificar recetas.
- `preparaciones.ajustar_consumos`: modificar consumos reales, con motivo; no habilita editar recetas.
- `costos.ver`: consultar los costos, que no se incluyen en propiedades públicas de Livewire.

Estos permisos ya estaban en el catálogo inicial. La nueva migración `000060` agrega instantáneas, UUID para no duplicar borradores y responsables de confirmación/anulación. Ejecutar `php artisan migrate` al actualizar.

Las claves de movimientos y el estado bloqueado impiden duplicar consumos al repetir una confirmación. Además de Pest en SQLite/MySQL, CI ejecuta `tests/Support/production-concurrency.php` con procesos simultáneos: dos lotes compitiendo por las últimas existencias y dos confirmaciones del mismo borrador. Ese script solo funciona con `APP_ENV=testing`, controlador mysql y base desechable `sisrest_test`.

**Para confirmar debe haber insumos disponibles.** Los seeders normales no inventan existencias. Las pruebas utilizan bases y existencias desechables; el módulo Compras será el siguiente ingreso operativo de materia prima.

## Alertas reutilizables

SweetAlert2 se compila con Vite, sin depender de un CDN. `resources/js/alerts.js` concentra comportamiento, textos y tiempos; `resources/css/app.css` define su apariencia clara/oscura. Los layouts incluyen un único componente `<x-alertas />` para mensajes después de una redirección.

En cualquier componente Livewire, incluir `use App\Concerns\ConAlertas;` y usar el trait `ConAlertas`:

~~~php
$this->notificar('Registro guardado.'); // Aviso breve de éxito.
$this->notificar('No se puede continuar.', 'error', toast: false); // Requiere aceptar.
$this->confirmar('Desactivar registro', '¿Deseas continuar?', 'confirmarDesactivacion', 'Desactivar', peligro: true);
~~~

La confirmación llama al método indicado **del mismo componente** solo al aceptar. Ese método debe autorizar y validar la operación en el servidor; los identificadores seleccionados deben mantenerse bloqueados. La confirmación visual no sustituye esas reglas. Usuarios ya aplica este flujo al activar/desactivar.

Para notificar después de redirigir, desde Livewire o un controlador:

~~~php
\App\Support\Alertas::flash('Registro guardado.');
return redirect()->route('dashboard');
~~~

Desde JavaScript o Alpine:

~~~js
window.Alertas.notificar({ text: 'Registro guardado.', type: 'success' });
const resultado = await window.Alertas.confirmar({ title: '¿Continuar?', text: 'Revisa los datos.' });
if (resultado.isConfirmed) { /* Acción autorizada por el servidor. */ }
~~~

Tipos: `success`, `error`, `warning`, `info` y `question`. Los textos se muestran literalmente; no se admite HTML de usuarios. Los avisos breves duran 4,5 segundos, pueden cerrarse y pausan su tiempo al pasar el puntero o recibir foco. Los errores que requieran lectura usan `toast: false`. Las validaciones de formularios continúan junto a sus campos. Los mensajes flash se consumen una sola vez y los listeners se registran una vez durante la navegación Livewire.

## Requisitos

- PHP **8.4.1 o superior**, compatible con las versiones fijadas.
- Extensiones: BCMath, ctype, cURL, DOM, fileinfo, GD, iconv, intl, mbstring, OpenSSL, PDO MySQL, SimpleXML, SOAP, tokenizer, XML, XMLReader, XMLWriter y ZIP. Para las pruebas rápidas: PDO SQLite.
- Composer 2.
- Node.js 22 o superior compatible con Vite Plus, y npm.
- MySQL **8.0.16 o superior**, o MariaDB **10.11 o superior**. Se usan CHECK y columnas generadas con índices únicos.

## Instalación en Windows / Laragon

1. Clonar el repositorio y entrar en su carpeta.
2. Ejecutar:

   ~~~console
   composer install
   npm ci
   ~~~

3. Copiar `.env.example` a `.env` y ejecutar:

   ~~~console
   php artisan key:generate
   ~~~

4. Crear una base vacía llamada `sisrest` en MySQL. Revisar en `.env`:

   ~~~dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sisrest
   DB_USERNAME=root
   DB_PASSWORD=
   ADMIN_USERNAME=admin
   ADMIN_NAME=Administrador
   ADMIN_EMAIL=
   ADMIN_PASSWORD=
   ~~~

   Definir **ADMIN_PASSWORD** con una contraseña propia de al menos 12 caracteres. El correo es opcional. No existe una contraseña compartida predeterminada.

5. Ejecutar:

   ~~~console
   php artisan migrate --seed
   npm run build
   ~~~

6. Abrir la aplicación con el servidor web de Laragon. Para desarrollo también puede usarse `composer dev`.

Si cambiaste valores con configuración cacheada, ejecutar `php artisan config:clear`.

`migrate:fresh` elimina las tablas: utilizarlo únicamente en bases desechables de pruebas.

## Seeders

- Roles administrador, cajero, mozo y cocina, con permisos detallados.
- DNI, RUC, carné de extranjería y pasaporte.
- Factura, boleta, nota de crédito y otros documentos de compra.
- Efectivo, Yape, Plin, tarjeta y transferencia.
- Unidades, categorías y motivos de descuentos/cortesías.
- Empresa pendiente de datos reales; moneda PEN y zona America/Lima.
- Salón Principal, Cocina y Barra; Barra inicia desactivada.
- Dos cajas e impresoras pendientes de IP, desactivadas.
- Series compartidas F001, B001 y notas FC01/BC01, inicialmente desactivadas.
- Facturación deshabilitada, en beta y **sin una tasa tributaria elegida**.
- Administrador inicial según `.env`.

Se pueden repetir con `php artisan db:seed`: no reinician contraseñas, permisos personalizados, configuración, saldos, correlativos ni operaciones.

No crean ventas, compras, turnos abiertos, clientes ficticios ni existencias iniciales. La empresa, configuración tributaria, certificado, credenciales y series se completarán antes de habilitar facturación.

### Ejemplos opcionales

~~~console
php artisan db:seed --class=EjemploInventarioSeeder
~~~

Solo en local/testing. Crea arroz, pollo, huevo, chaufa, gaseosa, un combo y un adicional, con receta ilustrativa. No fabrica existencias ni operaciones. Puede repetirse.

## Acceso

- Usuario y contraseña; sin verificación de correo obligatoria.
- Registro público desactivado.
- Usuarios administrados desde el módulo autorizado. Se bloqueó la eliminación de la propia cuenta desde el perfil.
- Una cuenta desactivada no inicia sesión y pierde acceso desde sesiones existentes.
- Se conservan doble factor y passkeys de la plantilla sin imponerlos al personal.
- Recuperación por correo para cuentas con correo y envío configurado; también se pueden restablecer contraseñas desde Usuarios con autorización.

## Dependencias añadidas

| Paquete | Uso previsto |
| --- | --- |
| spatie/laravel-permission | Roles y permisos |
| spatie/laravel-activitylog | Auditoría |
| greenter/lite | XML, firma y comunicación de facturación |
| mike42/escpos-php | ESC/POS por red |
| maatwebsite/excel | Exportación Excel |
| barryvdh/laravel-dompdf | Reportes PDF |
| chart.js | Gráficos |
| sweetalert2 | Alertas y confirmaciones reutilizables |

Greenter Lite evita exigir wkhtmltopdf. Los PDF de reportes se generarán con DomPDF; los tickets tendrán su integración ESC/POS.

Chart.js está disponible en `resources/js/charts.js` para importar desde los futuros widgets y empaquetarse localmente.

Las credenciales fiscales se cifran al guardar mediante `ConfiguracionFacturacion`, y se excluyen de serialización y auditoría. Certificados y archivos fiscales corresponden al disco privado.

## Pruebas

~~~console
composer test
~~~

Incluye Pint, PHPStan/Larastan y Pest. Por defecto, Pest usa SQLite en memoria. CI ejecuta también MySQL 8.4 para verificar las restricciones del motor.

Para pruebas con MySQL local, establecer las variables DB_* en el entorno antes de ejecutar `php artisan test` y usar una **base exclusiva de pruebas**. RefreshDatabase puede reconstruir esa base.

Consultar [modelo de datos](docs/modelo-datos.md) y [contratos operativos](docs/contratos-operativos.md).

### Auditoría de herramientas de compilación

La auditoría completa de npm señala diez alertas transitivas derivadas de Picomatch, utilizado por herramientas de compilación y pruebas de la plantilla. El aviso [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) afecta hasta 4.0.7 y no tiene versión corregida publicada al realizar esta entrega.

Se conservaron las versiones compatibles de la plantilla. Las herramientas de compilación se clasificaron como dependencias de desarrollo; esto no corrige el aviso de origen. Se debe revisar de nuevo cuando el proveedor publique una corrección.
