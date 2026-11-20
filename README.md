# SisRest

Base de un sistema de restaurante para un local, con dos cajas, atención en mesas, venta para llevar y delivery.

## Estado

Incluye la base de datos, dependencias y los módulos de **Usuarios y permisos**, **Salones y Mesas**, **Destinos de preparación**, **Impresoras**, **Insumos**, **Productos para la venta**, **Recetas**, **Preparación diaria**, **Compras y Proveedores**, **Cajas y Arqueos**, **Clientes**, **POS**, **Cocina/Barra**, **Cobros** y **Anulaciones/Beneficios**, implementados con Livewire y Spatie Laravel Permission. Conserva la plantilla Laravel + Livewire + Flux. POS ya confirma y reserva productos, Cocina registra el avance y la entrega descuenta existencias. Cobros permite dividir por productos y combinar medios de pago. La emisión de boletas y facturas con Greenter ya está integrada con los cobros, con firma, PDF, QR, impresión de red y seguimiento de SUNAT independiente del pago.

Las tablas soportan preparación real, reservas de existencias, combos por componentes, adicionales, cuentas divididas, pagos combinados, devoluciones y estados tributarios independientes. Los cobros internos y la emisión tributaria son operaciones distintas; cada comprobante conserva sus propias instantáneas y estados. La confirmación de compras y preparación diaria ya está implementada.

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

Estas pantallas configuran el catálogo. Desde POS ya se pueden abrir pedidos y descartar borradores, liberando su mesa; el cierre manual de una atención confirmada exige productos pagados y entregados. El contrato de bloqueo está en `docs/contratos-operativos.md`.

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

Se verificaron los bytes ESC/POS contra un receptor TCP local y el flujo de navegador. La compatibilidad física, corte y avance de la POS80 se deben comprobar en el equipo del restaurante. Las comandas, precuentas y tickets internos ya usan estas asignaciones; los comprobantes tributarios también usan esas asignaciones y agregan su QR fiscal.

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
- Las fotos admiten JPG/PNG/WebP de hasta 2 MB. Se almacenan en el disco público; reemplazar o quitar una foto retira el archivo anterior. Un error de validación no deja archivos ni artículos parciales. Listado y edición utilizan `/inventario/productos/{producto}/imagen`, con permiso de consulta, MIME verificado y una URL relativa que funciona al acceder por IP local aunque `APP_URL` tenga otro host. No se necesita volver a subir los archivos existentes si siguen en `storage/app/public/productos`. La URL cambia al reemplazar la foto.
- Los combos no tienen artículo ni existencias propias. Se configuran por productos no combo y cantidades positivas, sin duplicados ni anidación. Un combo activo requiere al menos un componente habilitado.
- Disponible normal = existencia actual menos reservada si está habilitado. Disponible del combo = menor cantidad entera que permiten sus componentes, respetando sus reservas y destinos activos.

### Presentaciones y protección de operaciones

El editor de presentaciones se reutiliza en Insumos y reventa: nombre, equivalencia en la unidad del artículo y estado. Admite hasta veinte presentaciones; no borra las utilizadas. Una presentación ya incluida en compras conserva su factor y debe reemplazarse por otra si cambia el contenido del paquete. Nunca puede asignarse una presentación de otro artículo.

Se bloquea desactivar artículos con reservas o utilizados por recetas activas, y productos incluidos en combos activos o pedidos en curso. Los estados se vuelven a comprobar al confirmar/guardar. Los cambios quedan auditados.

**Este módulo configura el catálogo y consulta cantidades. No permite escribir saldos manualmente ni inventa ingresos.** Las recetas y preparación diaria ya tienen sus pantallas y servicios descritos abajo; el ingreso de insumos se realiza desde Compras. Los menús son productos fijos, sin elección dinámica de entrada y segundo.

### Actualización de una instalación existente

~~~console
php artisan optimize:clear
php artisan migrate
npm run build
~~~

Las fotografías de productos se sirven desde una ruta autenticada del mismo origen; no requieren `storage:link`. El enlace puede conservarse para futuros archivos públicos. La migración `000059` agrega `insumos.crear` e `insumos.editar`: en instalaciones existentes los concede una vez a los roles/usuarios que ya tenían `inventario.ajustar`. Los lectores no reciben gestión. Después pueden ajustarse desde Roles; repetir seeders no restablece permisos revocados. Las instalaciones nuevas reciben los 62 permisos del catálogo para el administrador inicial.

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

**Para confirmar debe haber insumos disponibles.** Los seeders normales no inventan existencias. Las pruebas utilizan bases y existencias desechables; el módulo Compras ya permite ingresar la materia prima recibida.

## Compras y Proveedores

### Proveedores

`/compras/proveedores` requiere `proveedores.gestionar`. Incluye búsqueda, filtros, paginación, registro, edición y activación/desactivación con SweetAlert2. Nombre o razón social obligatorio; documento y contacto opcionales. Si se registra documento, deben seleccionarse tipo y número; se comprueban longitud, formato y duplicados. Los cambios se auditan y las ediciones concurrentes no se sobrescriben.

Desactivar impide nuevas compras y confirmar borradores; no elimina historial ni impide pagar deudas existentes. Desde el proveedor se puede consultar su listado de compras y saldos si se tiene `compras.ver`.

### Recepción de compras

`/compras` requiere `compras.ver`; permite buscar por código/proveedor/número y filtrar proveedor, estado, fechas y saldo pendiente/pagada. Importes en soles. Solo se registran compras **ya recibidas**, sin recepciones parciales.

1. **Nueva compra** con proveedor activo, documento recibido, fecha y condición contado/crédito. Factura y boleta requieren serie y número; otro documento admite ambos vacíos. No se repite el mismo documento del proveedor. Crédito exige vencimiento no anterior a la compra.
2. Seleccionar hasta 50 insumos o artículos de reventa, sin repetir; los preparados se obtienen mediante producción. Elegir unidad base o presentación propia activa y cantidad positiva.
3. Registrar el precio final por presentación (hasta seis decimales). Cantidad × equivalencia genera la cantidad base con hasta cuatro decimales exactos. No se redondea una fracción para inventar existencias.
4. Opcionalmente indicar descuento por línea y el importe del impuesto **ya incluido**. Se resta el descuento; el impuesto se desglosa sin volver a sumarlo. No se aplica una tasa tributaria supuesta. Los totales se calculan y redondean a céntimos en el servidor; el detalle guardado permite revisarlos.
5. **Guardar borrador** no modifica existencias ni registra pagos. El creador o un confirmador con permiso de creación puede editarlo/anularlo con motivo. Se comprueba la versión antes de guardar o confirmar.
6. **Confirmar compra** ingresa cantidades y actualiza el costo promedio ponderado de cada artículo en una transacción, conservando sus reservas. La valorización operativa usa el total final de adquisición de cada línea, incluidos sus impuestos; no calcula recuperación tributaria ni contabilidad fiscal. Nombres, unidades, presentaciones, factores, costos y responsables quedan conservados como instantánea.
7. Contado confirma recepción y pago total juntos: si el pago falla, tampoco ingresa inventario. Crédito ingresa existencias con saldo pendiente y admite pagos posteriores parciales o completos.

Confirmar otra vez no duplica ingresos. Las compras confirmadas y sus pagos son de solo lectura; devoluciones al proveedor y correcciones de recepción se definirán con sus movimientos correspondientes. Esta entrega solo anula borradores. No se modifican compras reales mediante `migrate:fresh` ni se inventan saldos en seeders.

### Pagos y origen del dinero

`pagos_proveedor.registrar` autoriza pagos; `compras.confirmar` autoriza la recepción. Para contado se necesitan ambos permisos. Los pagos se registran con fecha/hora real, usuario, medio, referencia opcional y UUID; nunca superan el saldo confirmado.

- **Medios digitales de la empresa**: Yape, Plin, tarjeta o transferencia; sin turno de caja ni movimiento de efectivo.
- **Dinero del dueño**: efectivo o digital externo; no modifica el efectivo del restaurante ni crea un ingreso ficticio en caja.
- **Efectivo de caja abierta**: exige `cajas.registrar_egreso`, turno abierto propio (o `cajas.ver_todas`), medio que afecte efectivo y saldo esperado suficiente. Genera un único egreso enlazado al pago. No permite asociar un pago digital a una salida física de caja.

El detalle muestra pagos y saldo; pagar no vuelve a ingresar existencias. El usuario revisa el pago y lo confirma con SweetAlert2; los valores revisados quedan bloqueados durante la confirmación. La apertura y el cierre se realizan desde Cajas y Arqueos; el selector muestra únicamente turnos abiertos autorizados. No se crea un turno automáticamente al pagar una compra.

### Actualización y concurrencia

Los permisos ya estaban en el catálogo. La migración `000061` agrega UUID, instantáneas y responsables a compras/detalles, y el nombre del medio en pagos. Ejecutar `php artisan migrate` y recompilar recursos.

CI añade `tests/Support/purchase-concurrency.php` en MySQL: recepciones simultáneas del mismo artículo conservan cantidades y costo promedio; repetir una recepción no duplica movimientos; dos pagos no pueden superar la misma deuda; repetir un pago con su UUID no lo duplica. El script solo opera en `APP_ENV=testing` y base MySQL desechable `sisrest_test`, y limpia sus propias filas.

## Cajas y Arqueos

`/cajas` requiere `cajas.ver_propias` o `cajas.ver_todas`. El sidebar contiene un solo enlace. Incluye tarjetas de las dos cajas iniciales, estado y acceso al turno e historial con filtros por caja, estado y fechas. Los importes e historial ajenos solo se muestran con `cajas.ver_todas`; el cajero ve los suyos y únicamente “en uso” en la caja ocupada por otro responsable.

### Apertura

- `cajas.abrir`: abrir una caja activa sin turno abierto, con fondo inicial no negativo y hasta dos decimales. El cajero es responsable de su turno; con `cajas.ver_todas` se puede asignar a otro usuario activo con acceso a cajas. Se guarda también quién abrió el turno.
- Revisar y confirmar con SweetAlert2 antes de escribir. UUID de apertura y restricción de una caja abierta por caja física: repetir no duplica fondos ni turnos.
- Fondo inicial guardado en el turno; no se crea además un ingreso ni una venta. El mismo usuario puede ser responsable de más de una caja física si la operación lo necesita; cada turno mantiene sus propios movimientos.

### Efectivo y movimientos

El detalle `/cajas/turnos/{turno}` separa fondo, ingresos, egresos, retiros y efectivo esperado. **Esperado = fondo + ingresos − egresos − retiros**. Solo son movimientos físicos; un pago digital no aumenta el efectivo.

Ingresos (`cajas.registrar_ingreso`), egresos (`cajas.registrar_egreso`) y retiros (`cajas.retirar`) requieren monto positivo, concepto y motivo. Las salidas no pueden superar el efectivo esperado; se comprueba rango y precisión decimal en el servidor. Cada movimiento conserva usuario, fecha/hora, origen y UUID, y se confirma con SweetAlert2. No hay edición ni borrado de movimientos registrados.

Los pagos de proveedores hechos con efectivo desde ese turno aparecen como egresos automáticos, identificados en el filtro de origen. No se registran una segunda vez de forma manual. Pagos digitales de empresa y dinero externo del dueño no se asocian a caja ni alteran su efectivo.

El resumen de cobros por medio utiliza pagos de ventas **confirmadas**, antes de devoluciones. Monto aplicado y vuelto se distinguen: solo el importe aplicado en efectivo corresponde al movimiento de ingreso. Ese resumen no incluye fondos ni aportes manuales y no se suma de nuevo al efectivo esperado. Si todavía no existen ventas, indica que no hay cobros; los cobros del POS alimentan este resumen y sus ingresos de efectivo.

### Arqueos y cierre

- Arqueo parcial (`cajas.arquear`): indicar efectivo físico contado; se conserva esperado, contado y diferencia (contado − esperado). Si hay diferencia, se exige explicación de al menos cinco caracteres. No ajusta el dinero ni cierra el turno.
- Cierre (`cajas.cerrar`): cuenta final en la misma transacción que cierra el turno. Conserva un único arqueo final, fecha/hora, responsable del cierre y resumen de movimientos/cobros. Cerrar requiere permiso de cierre, sin exigir además el de arqueo parcial.
- Los importes revisados quedan bloqueados hasta confirmar. Si el turno cambia mientras se revisa, se exige actualizar y repetir la revisión. Se leen los movimientos y cobros con bloqueo actual al escribir, evitando usar una instantánea anterior de MySQL después de esperar otro movimiento.
- Un turno cerrado no recibe ingresos, egresos, retiros, pagos a proveedores ni más arqueos. Sus importes y resumen final se conservan; no se puede reabrir el mismo turno.
- Se permite cerrar aunque existan pedidos pendientes; no se modifican ni liberan mesas/pedidos por cerrar caja. Los cobros posteriores corresponderán al turno que los reciba.
- Para usar nuevamente la caja se crea otro turno con un fondo explícito. No se arrastra efectivo ni se inventa una transferencia automática del cierre anterior.

Los nombres de caja y responsable se conservan desde la apertura. El historial de arqueos y movimientos tiene paginación independiente; la consulta y los filtros se autorizan siempre en servidor. “Consultar todas” amplía el alcance, pero cada acción mantiene su permiso específico.

### Actualización y pruebas

La migración `000062` agrega UUID y responsables/instantáneas de apertura, y UUID/resumen de arqueos. Los turnos previos conservan sus datos; los campos nuevos son opcionales para registros antiguos. No se agregan permisos ni se restablecen roles existentes. Actualizar con `php artisan migrate` y `npm run build`.

CI ejecuta `tests/Support/cash-concurrency.php` en MySQL: apertura doble de una caja, egreso frente a cierre, cierre repetido, pago a proveedor frente a cierre y movimiento manual repetido. Usa barreras para iniciar procesos simultáneos y se restringe al entorno testing y base desechable `sisrest_test`. Limpia solo sus propios datos.

## Clientes

`/clientes` requiere `clientes.ver`. Incluye listado con nombre, documento, teléfono, correo, dirección y estado; búsqueda por nombre/documento/teléfono/correo; filtros por persona/empresa y activo/inactivo; paginación y contador de resultados. La navegación permanece únicamente en el sidebar.

- **Persona natural**: nombres y apellidos obligatorios. Documento y contacto opcionales. Puede registrarse sin documento o con DNI, RUC, carné de extranjería o pasaporte del catálogo.
- **Empresa**: razón social, RUC y dirección obligatorios como regla del registro. El formulario selecciona RUC al elegir Empresa; una persona con RUC no se convierte automáticamente en empresa.
- Si se indica documento, se exigen tipo y número juntos. DNI de ocho dígitos y RUC de once, conservando ceros iniciales; otros documentos admiten letras/números/guion y hasta veinte caracteres según el catálogo. Se normalizan espacios externos y letras a mayúsculas. No se duplica el mismo tipo/número, aunque el cliente esté inactivo.
- Teléfono, correo y dirección de persona son opcionales; se validan formato de correo y longitudes. La captura es manual: no consulta RENIEC/SUNAT, no comprueba existencia o condición tributaria ni completa automáticamente nombres.

`clientes.crear` habilita altas y `clientes.editar` habilita edición y cambios de estado, además del permiso de consulta. Editar no requiere permiso de crear. Se vuelven a comprobar usuario activo y permisos en el servicio transaccional; ID, versión y UUID permanecen bloqueados en Livewire.

**Guardar** notifica con SweetAlert2 tras redirigir. **Activar/Desactivar** pide confirmación con la alerta global. No se borran clientes ni se alteran sus pedidos, cobros o comprobantes. Los cambios de información y estado se auditan con responsable y valores anteriores/nuevos. El POS permite seleccionar clientes existentes con permiso de consulta; el alta se realiza desde Clientes.

Las ediciones comprueban una versión para no sobrescribir cambios de otra sesión. Si ya existen pedidos/cobros, se conserva el tipo de cliente y el documento previamente registrado; se permite completar un documento que faltaba, sin cambiar el tipo, y actualizar nombre/contacto. El formulario indica y bloquea los campos protegidos; el servidor comprueba la misma regla.

La migración `000063` agrega UUID de registro y usuario creador para impedir altas repetidas, incluso sin documento. Los clientes anteriores conservan sus datos y no reciben una autoría ficticia. No se crean clientes de ejemplo ni se modifican permisos existentes. Actualizar con `php artisan migrate` y `npm run build`.

## POS: pedidos en borrador

`/pos` requiere `pos.ver` y acceso a pedidos propios o a todos. Incluye selector de salón, mesas libres/ocupadas, pedidos activos con filtros por modalidad y acceso al detalle `/pos/pedidos/{pedido}`. La navegación de módulos permanece en el sidebar.

1. **Abrir caja** desde Cajas y Arqueos. Abrir o guardar un pedido requiere seleccionar un turno abierto de una caja activa; los mozos solo consultan su nombre y número de turno, sin acceso a sus importes.
2. Seleccionar una mesa habilitada, **Para llevar** o **Delivery**. La apertura guarda responsable, caja/turno de origen y nombres de salón/mesa. Delivery requiere destinatario, teléfono y dirección; referencia y costo de envío son opcionales. La asignación de repartidor/taxi y el despacho se implementarán después.
3. Buscar productos por nombre/código y filtrar por categoría. Agregar cantidades enteras, notas por producto, observación general, comensales y cliente opcional. Solo se ofrece el catálogo activo y visible; los productos con entrega directa no necesitan destino de cocina.
4. **Guardar borrador** conserva precios del servidor, nombres, destinos y componentes del combo. Los componentes tienen precio cero para no cobrar dos veces. Cambiar después el precio o composición del catálogo no reescribe lo ya guardado. Se comprueba la disponibilidad del carrito completo, incluyendo componentes compartidos entre combos y productos individuales.
5. Reabrir el pedido permite editar sus borradores. Se rechaza sobrescribir cambios de otra sesión. Las líneas confirmadas son de solo lectura, y pueden convivir con nuevas líneas en borrador sin reiniciar su preparación.

**Guardar no reserva ni descuenta existencias, no envía a Cocina, no cobra ni imprime.** Distintos borradores pueden depender de las mismas existencias; la confirmación descrita a continuación vuelve a comprobar y reserva las cantidades. El total es referencial, incluye el envío en delivery y todavía no constituye una venta ni un comprobante.

`pedidos.crear` autoriza apertura; `pedidos.editar`, guardar o descartar. `pedidos.ver_propios` limita al responsable/creador; `pedidos.ver_todos` amplía el alcance y permite asignar otro responsable habilitado. El pedido ajeno no se abre con un ID directo. Seleccionar/cambiar cliente exige `clientes.ver`; un mozo sin ese permiso no recibe el directorio. Los inactivos no se ofrecen para nuevas asignaciones y una referencia anterior se conserva.

**Descartar borrador** exige motivo y confirmación SweetAlert2. Solo se admite si no tiene líneas confirmadas, reservas, envíos ni cobros. Conserva el pedido anulado y sus detalles; libera únicamente su propia ocupación. En mesa requiere además `mesas.liberar`. Puede descartarse aunque se haya cerrado la caja, pues no registra venta ni dinero. Cerrar caja nunca descarta pedidos: pueden continuar usando otro turno abierto, conservando el turno de apertura original.

La migración `000064` agrega UUID, turno de apertura, nombres históricos y responsable/motivo de anulación al pedido, y factor del componente a sus detalles. Los campos nuevos son opcionales para registros anteriores. No hay permisos ni dependencias nuevas; actualizar con `php artisan migrate` y `npm run build`. La ruta autenticada de fotos admite `productos.ver` **o** `pos.ver`, sin conceder al mozo la gestión del catálogo.

CI ejecuta `tests/Support/pos-concurrency.php` en MySQL: dos aperturas de una mesa, repetición del UUID de apertura, descarte repetido y ediciones concurrentes del mismo borrador. Se restringe al entorno testing y base desechable `sisrest_test`, y limpia sus propias filas.

## Confirmación, Cocina y entregas

Después de **Guardar borrador**, **Confirmar y enviar** (`pedidos.enviar`) pide confirmación SweetAlert2. Si hay cambios sin guardar, solicita guardarlos primero. Requiere un turno abierto de una caja activa y mantiene el alcance propio/general del pedido.

- Revisa de nuevo estado, productos, destinos históricos y disponibilidad agregada. Si falta cualquier cantidad, revierte el lote completo.
- Registra un lote con UUID, responsable, fecha y turno de confirmación. Repetir el mismo UUID devuelve el resultado original, sin duplicar reservas ni envíos.
- Reserva cada producto preparado/reventa; el combo reserva sus componentes guardados, sin reservar ni cobrar otra vez la raíz. No vuelve a consumir ingredientes ni modifica efectivo.
- Crea un envío por destino con las cantidades, nombres y notas de las líneas nuevas. Conserva el número de lote y nombre del destino. Los productos de entrega directa no generan envío y quedan listos para entregar.
- Pedir más en la misma atención genera otro lote; las cantidades iniciadas/listas/entregadas de los anteriores permanecen intactas. Las líneas confirmadas no se editan como borrador; sus anulaciones y beneficios se gestionan con las acciones autorizadas descritas abajo.

### Pantalla de Cocina

`/cocina`, con `cocina.ver`, muestra envíos por orden de llegada y tiempo transcurrido. Filtra por destino (Cocina, Barra u otro) y pendientes de preparación, listos para entregar o todos sin entregar. Actualiza cada cinco segundos mediante Livewire, sin recargar la página completa; dispone también de actualización manual. No requiere acceso al POS ni muestra precios, clientes o importes de caja.

Cada línea muestra descripción, cantidad, nota, combo de origen si aplica, pendientes de iniciar, preparando y listos. Indicar **Cantidad a procesar**; si se deja vacía se utiliza una unidad. Se admiten hasta cuatro decimales para componentes fraccionarios.

- **Iniciar preparación** requiere `cocina.preparar` y no supera lo pendiente.
- **Marcar listo** requiere `cocina.marcar_listo` y solo avanza cantidades ya iniciadas.
- Cada acción vuelve a comprobar permisos, estado y versión de esa línea. Si otro equipo la modificó, no vuelve a aplicar la cantidad y solicita revisar el avance actualizado.
- Preparar y marcar listo conservan existencias y reservas. No exigen mantener abierta la caja del envío.

### Entrega desde el POS

Los confirmados muestran cantidades listas y entregadas, con actualización cada cinco segundos. Con `entregas.confirmar` se indica cantidad y se acepta la confirmación SweetAlert2. Una nueva revisión conserva la cantidad elegida aunque se modifique el input después de abrir la alerta. **Actualizar pedido** permite volver a abrir la versión actual; si hay cambios sin guardar, pide confirmación antes de descartarlos.

Solo se entrega lo confirmado: productos con destino necesitan cantidad lista en Cocina; entrega directa no necesita esa preparación. En una transacción consume la reserva, reduce existencia actual y reservada y registra un movimiento de salida `entrega` con cantidad/costo/responsable. No modifica el costo promedio ni genera dinero, venta, pago o comprobante. Una petición con la versión anterior no puede descontar nuevamente. El stock reservado para otros productos/pedidos permanece protegido.

Los combos se entregan por componentes; la raíz muestra las unidades completas entregadas según sus factores históricos, sin otro movimiento de inventario. El pedido pasa a **Listo** cuando todo lo confirmado está listo y a **Entregado** cuando todo se entrega. Una nueva confirmación vuelve a reflejar lo que aún falta preparar. Entregar no cierra automáticamente la atención ni libera la mesa: se cierra manualmente cuando todo esté pagado y entregado.

La caja puede estar cerrada al terminar preparación/entrega de productos ya confirmados. Para agregar/guardar/confirmar nuevos productos se necesita un turno abierto. El seguimiento del reparto, la asignación de repartidor/taxi, los ajustes de inventario ajenos a estos flujos y la emisión fiscal se integrarán después. Los envíos alimentan Cocina y pueden imprimirse desde **Comandas / precuenta**. Confirmar productos no imprime automáticamente.

La migración `2026_11_19_000065_create_confirmaciones_pedido.php` agrega los lotes, su vínculo con detalles/envíos y el nombre histórico del destino; conserva opcionales los vínculos de registros anteriores. Actualizar con `php artisan migrate` y `npm run build`. No hay permisos ni dependencias nuevas y no se cambian roles existentes.

CI añade `tests/Support/kitchen-concurrency.php` en MySQL: confirmaciones que compiten por el stock, UUID de lote repetido, avance simultáneo de Cocina y entrega repetida. Usa procesos y barreras en entorno testing y base desechable `sisrest_test`. Comprueba también los movimientos y limpia solo sus datos.

## Cobros, división de cuenta y pagos combinados

Desde el pedido, **Cobrar / dividir cuenta** abre `/pos/pedidos/{pedido}/cobrar`. Requiere `cobros.crear`, acceso POS al pedido y consulta de cajas propia/general. Cada nuevo cobro utiliza una caja activa con turno abierto del cajero; `cajas.ver_todas` permite seleccionar otro turno autorizado. No se usa obligatoriamente la caja de apertura del pedido y no se abre un turno automáticamente.

1. Elegir las cantidades de los productos confirmados que pagará el grupo. **Seleccionar pendientes** prepara el resto; **Limpiar selección** permite escoger solo algunos. Las cantidades son unidades enteras, como en POS. Los combos se cobran en su raíz con el precio guardado, sin sumar otra vez sus componentes.
2. Opcionalmente indicar grupo/pagador y cliente de ese cobro. Una familia puede tener un cliente distinto al de otra, sin cambiar el cliente general del pedido. Seleccionar otro cliente exige `clientes.ver` y que esté activo; se permite conservar la referencia previa del pedido si ese cliente fue desactivado.
3. En delivery, elegir si se incluye el **envío pendiente completo**. Se cobra una sola vez entre todos los grupos. Puede cobrarse también por separado, después de los productos. No se edita su precio desde la pantalla de cobro.
4. Agregar uno o varios medios activos, sin repetir el mismo medio. Indicar importe aplicado; referencia obligatoria cuando el medio lo exige. La suma debe coincidir exactamente con productos seleccionados más envío. No admite abonos de un importe arbitrario ni cantidades ya liquidadas.
5. Efectivo admite recibido mayor al importe aplicado y calcula el vuelto; recibido vacío significa pago exacto. Un medio digital no admite vuelto. El resumen muestra lo aplicado, faltante, ingreso de efectivo y vuelto.
6. **Revisar y cobrar** valida en servidor y abre SweetAlert2 con los importes. Aceptar registra únicamente los datos revisados, aunque se cambien inputs después de abrir la alerta. Si cambió el pedido, cliente o configuración del medio, se exige revisar nuevamente.

Ejemplo: total S/ 80, efectivo aplicado S/ 50 con billete de S/ 100, más Yape S/ 30. Se registran S/ 50 de vuelto, un ingreso físico de S/ 50 y cobros por medios de S/ 80. El efectivo esperado de caja no suma Yape ni el billete completo antes del vuelto.

El cobro, sus productos, pagos, cantidades cobradas y movimientos de caja se confirman en una transacción. No vuelve a reservar/descontar productos ni consume ingredientes. Solo los medios que afectan efectivo generan ingreso automático, enlazado a su pago: no se registra además un ingreso manual. Se valida el rango del efectivo esperado antes de escribir. Los productos del catálogo con precio cero pueden liquidarse sin líneas de pago ni movimientos ficticios; los descuentos/cortesías autorizados por producto se conservan y prorratean al cobrar.

El resto de cantidades queda pendiente para los siguientes grupos, incluso desde otra caja autorizada. Cada cobro tiene UUID: repetir la misma petición devuelve su registro original y no duplica pagos ni movimientos, aunque el turno se haya cerrado después. Dos revisiones competidoras no pueden cobrar las mismas unidades; deben volver a consultar el pendiente.

### Consulta y cierre de atención

`/pos/cobros/{cobro}` muestra el detalle interno de solo lectura: productos, grupo/cliente, caja, responsable, pagos, referencias, recibido y vuelto. Conserva nombres y datos del cliente/medio/caja/cajero al registrar. El historial del pedido y los accesos directos a cobros respetan también el alcance de cajas del usuario. Los mozos sin `cobros.crear` no acceden al cobro ni a su historial financiero.

Cajas distingue el origen **Cobro de venta** en sus movimientos, y conserva el resumen por medios separado del efectivo esperado. Los medios digitales no generan movimiento físico; las denominaciones guardadas se utilizan al consultar el historial.

**Cuenta pagada** se calcula desde cantidades liquidadas y envío; no equivale a productos entregados. Tras completar ambos, **Cerrar atención** pide confirmación y vuelve a comprobar borradores, cobros, entregas, reservas y envíos pendientes. Requiere `pedidos.editar` y, si hay mesa, `mesas.liberar`. Guarda fecha/estado de cierre y libera solamente su ocupación. Puede cerrarse con caja cerrada, pues no mueve dinero. Pagar no cierra automáticamente ni libera una mesa con productos pendientes.

El módulo Cobros registra **cobros internos**. La boleta/factura se emite después desde el detalle del cobro, con su propia configuración y cálculo tributario. Pago y emisión seguirán separados. Los cobros/pagos confirmados no se editan ni eliminan; las devoluciones de dinero y productos se registran por separado, conservando el cobro original. La emisión tributaria se describe en la sección Facturación electrónica.

### Actualización y pruebas

La migración `2026_11_19_000066_add_cobro_payment_snapshots.php` añade instantáneas opcionales del grupo/cliente/caja/cajero al cobro y nombre/clasificación de efectivo al pago. Conserva intactos los registros previos, sin inventar nombres históricos, permisos ni saldos. Actualizar con `php artisan migrate` y `npm run build`; no hay dependencias nuevas.

CI MySQL añade `tests/Support/cobros-concurrency.php`: dos grupos compitiendo por las mismas unidades, UUID repetido, cargo de delivery cobrado una sola vez y cobro frente a cierre de caja. Comprueba que el cobro no modifica inventario. Se restringe a testing y base desechable `sisrest_test`, y limpia solo sus datos.

## Anulaciones, descuentos y cortesías

En los productos confirmados del POS aparecen **Anular producto** y **Descuento / cortesía**, según permisos. Antes de abrirlos se exige guardar los cambios del borrador. Cada operación se revisa con SweetAlert2 y conserva UUID, responsable, motivo y datos históricos; el historial de las últimas operaciones está al pie del pedido. No modifica cobros confirmados ni devuelve dinero automáticamente.

### Anular productos confirmados

Requiere `pedidos.anular_enviados` y acceso al pedido. Seleccionar una cantidad entera y explicar el motivo con al menos cinco caracteres. Solo alcanza cantidades no cobradas y no entregadas; en combos se comprueban también todos sus componentes y factores históricos. Si ya se cobró o entregó lo solicitado, corresponde el flujo de devolución desde el detalle del cobro.

- Se toma primero cantidad **sin iniciar**: libera la reserva y vuelve a estar disponible, sin mover existencia actual ni restituir ingredientes.
- Si la cantidad alcanza productos **iniciados o listos**, la revisión muestra por producto cuánto se libera y cuánto será **merma**. Esa parte exige además `inventario.registrar_merma`, consume la reserva y genera una salida de merma del artículo preparado/reventa con su costo vigente. No vuelve a estar disponible ni devuelve materia prima.
- Cocina conserva el total originalmente enviado y aumenta anulado. Se ajustan iniciados/listos para mantener sus límites; antes/después quedan en el detalle de anulación. La parte no anulada continúa normalmente y la pantalla de Cocina muestra las cantidades anuladas.
- Todo ocurre en una transacción. Repetir UUID no duplica liberaciones ni merma. Cambios de Cocina, entrega o cobro entre revisar y aceptar invalidan la revisión. La caja puede estar cerrada, pues la operación no mueve dinero.

Las líneas permanecen en el pedido con sus cantidades anuladas. Anular todos los productos no libera automáticamente la mesa: puede agregarse otro pedido o cerrarse manualmente cuando no queden productos, envíos o importes pendientes. El cargo de delivery conserva su propia liquidación; anular productos no lo elimina.

### Beneficio por producto

Requiere `descuentos.aplicar` o `cortesias.aplicar`, respectivamente. Solo se modifica una raíz confirmada vigente **antes de su primer cobro**; los cobros ya existentes no se reescriben. Puede autorizarse después de preparar/entregar, si el producto todavía no tiene cobros. Una cortesía de un artículo concreto puede registrarse en su propia línea del pedido.

- Descuento en **porcentaje** (mayor que cero y hasta 100) o **soles** (descuento total de esa línea, sin superar su importe vigente).
- Un descuento que cubra el importe completo (porcentaje, soles o redondeo) exige además permiso de cortesía; no se puede sustituir esa autorización con un descuento del 100 %.
- Cortesía conserva cantidad/precio original y autoriza el importe completo como beneficio. Se entrega y consume inventario normalmente; al liquidarse no crea pagos ni ingresos ficticios.
- Elegir motivo activo compatible (Cumpleaños, Promoción, Atención comercial u Otro) y explicar la autorización. Se conserva responsable, fecha y motivo; no se comprueba cumpleaños automáticamente.
- Retirar/reemplazar un beneficio conserva su historial y requiere permiso para el beneficio anterior y para el nuevo cuando corresponda. No requiere caja abierta, pues todavía no está registrando dinero.

Al dividir la cuenta se distribuye el presupuesto restante del beneficio entre las cantidades pendientes, ajustando céntimos al último cobro. Ejemplo: S/ 1 de descuento para tres unidades se reparte S/ 0,33 + S/ 0,34 + S/ 0,33. Si se anula cantidad no cobrada, se retira únicamente su parte del beneficio pendiente; los descuentos de cobros anteriores se conservan.

### Descuento general del cobro

En **Cobrar / dividir cuenta**, con `descuentos.aplicar`, elegir porcentaje o soles, motivo y detalle. Afecta solo productos seleccionados de ese cobro, sin incluir envío ni grupos ya liquidados. Para descontar toda la cuenta pendiente, seleccionar todos sus productos.

No se acumula con descuentos/cortesías por producto dentro del mismo cobro. Se reparte entre los detalles con precisión de céntimos y se conserva el autorizador y motivo en el recibo interno. La suma de los pagos debe cubrir el **total neto**; un total cero no necesita medios de pago. La fila de pago completamente vacía se ignora, sin generar un pago cero. El cajero puede liquidar beneficios de producto ya autorizados sin obtener por ello permiso para conceder otros.

### Persistencia y comprobación

La migración `2026_11_19_000067_add_pos_cancellations_and_benefits.php` incorpora historial de anulaciones y sus efectos por artículo, historial de beneficios, instantáneas de autorización y desglose de descuentos en los detalles cobrados. Conserva registros anteriores y no crea beneficios, existencias ni permisos nuevos. Actualizar con `php artisan migrate` y `npm run build`.

CI añade `tests/Support/pos-adjustments-concurrency.php` en MySQL: anulación frente a Cocina/cobro, beneficio frente a cobro y repetición de anulaciones con merma/beneficios. Solo testing y base desechable `sisrest_test`; limpia sus propios datos. Las devoluciones y la impresión operativa se describen a continuación; la emisión tributaria sigue pendiente.

## Devoluciones de cobros y productos

En **POS → Historial de cobros** se buscan registros por código de cobro/pedido, grupo, cliente o documento, incluidos turnos y atenciones cerrados, con filtro de devoluciones y alcance de pedidos/cajas. Desde el detalle de un cobro, **Devolver productos / pago** abre `/pos/cobros/{cobro}/devolver`. Requiere `devoluciones.crear`, acceso al pedido, al turno del cobro original y al turno actual. El administrador puede conceder este permiso; el rol cajero no lo recibe automáticamente.

1. Indicar cantidades enteras del cobro original aún no devueltas. Se calcula su **neto realmente cobrado**, incluidos los descuentos por producto y generales; las devoluciones parciales distribuyen los céntimos restantes sin superar el original. Puede devolverse también el cargo de envío completo restante, incluso sin productos.
2. Registrar los importes reintegrados por cada **pago original**. Se conserva el medio y su condición histórica de efectivo; cada importe se limita a lo aplicado menos devoluciones anteriores. El vuelto recibido originalmente no es reintegrable. En esta etapa no se cambia de medio. Si la configuración actual exige referencia, registrar la referencia de la nueva devolución, aunque el medio esté inactivo.
3. Seleccionar una **caja actualmente abierta y autorizada**, explicar el motivo y revisar con SweetAlert2. Registrar el dinero efectivamente entregado/transferido: la aplicación no ejecuta transferencias bancarias ni de Yape. El efectivo requiere además `cajas.registrar_egreso` y saldo suficiente. Los digitales no generan egreso físico.
4. El registro interno `/pos/devoluciones/{devolucion}` permite resolver **después y por partes** los productos. Devolver dinero no cancela entregas ni reingresa inventario automáticamente.

La devolución puede referirse a un turno cerrado: su egreso pertenece al turno actual, sin reescribir el cierre original. Una cortesía o producto de importe cero admite devolución de cantidades sin pagos ficticios, con turno abierto igualmente. UUID, revisión bloqueada y revalidación del saldo impiden duplicados o aceptar cambios posteriores a la revisión. Los históricos que no concilien productos/pagos/devoluciones se bloquean para conciliación, sin atribuir importes o costos supuestos.

### Resolver los productos

Cada cantidad devuelta admite una decisión independiente con motivo, usuario y fecha:

| Decisión | Efecto |
| --- | --- |
| Mantener entrega | Decisión comercial solo sobre dinero; no cancela ni registra retorno. La cantidad queda resuelta y mantiene su entrega acordada. |
| Cancelar lo pendiente | Solo unidades pagadas aún no entregadas; requiere `pedidos.anular_enviados`. Lo no iniciado libera reserva; lo iniciado/listo consume reserva y stock como merma con `inventario.registrar_merma`. Cocina deja de tener esa cantidad pendiente. |
| Retorno físico | Solo cantidades entregadas sin retorno previo. Cada componente de combo elige reingreso, merma o sin recuperar stock. |

**Reingreso** requiere `inventario.ajustar` y un artículo de reventa activo, con unidad activa y condición apta para venderse otra vez. Recupera stock al costo histórico de su salida, asignando primero las entregas más antiguas no retornadas y actualizando el costo promedio ponderado. Los preparados no vuelven a disponibilidad. **Merma** requiere `inventario.registrar_merma`; en productos ya entregados se registra su disposición sin volver a descontar stock. **Sin recuperar stock** registra el retorno sin movimiento de inventario.

Una cantidad solo puede resolverse una vez, aunque una devolución se divida entre varias decisiones. Los combos usan sus factores guardados y verifican cada componente. Los contadores originales de cobrado/entregado se conservan; cancelaciones pagadas y retornos tienen acumulados separados. No se genera una nueva deuda al devolver dinero. Las atenciones cerradas no se reabren ni se altera la ocupación actual de mesas; una atención pagada con entregas canceladas puede cerrarse manualmente cuando no mantenga reservas o envíos pendientes.

### Caja y comprobantes

El turno conserva **cobrado bruto**, **devuelto por medio** y **cobrado menos devuelto**. Este neto puede ser negativo al devolver cobros anteriores y no equivale al efectivo físico. El egreso automático queda enlazado al reintegro; el filtro de movimientos incluye **Devolución de venta**. Las devoluciones digitales también invalidan una revisión de cierre anterior. Los arqueos ya cerrados conservan su instantánea, incluidos cierres de versiones anteriores sin esos campos.

Cobro y pagos originales permanecen confirmados e inmutables. Esta devolución interna no modifica ni emite comprobantes o estados SUNAT. **La impresión operativa y la emisión con Greenter se describen a continuación. Las notas de crédito y bajas tributarias siguen pendientes.**

### Verificación

`tests/Feature/Cobros/DevolucionesTest.php` cubre netos y céntimos, pagos combinados/vuelto, referencias, envío, importes cero, permisos, caja de otro turno, stock separado, cancelación pagada, retornos, costos históricos, combos, alcance de acceso, revisiones y formularios Livewire. CI MySQL añade `tests/Support/devoluciones-concurrency.php`: saldo competido, UUID de devolución y retorno repetidos, mismo producto retornado dos veces, cancelación pagada frente a entrega y devolución frente a cierre. Solo usa testing y base desechable `sisrest_test`; limpia sus propias filas.

## Impresión de comandas, precuentas y tickets

La impresión operativa usa ESC/POS por TCP desde PHP. Funciona con el servidor en la misma red accesible que las impresoras, incluidos cajeros Windows y mozos en tablet. No abre el cuadro de impresión del navegador ni requiere instalar un controlador en cada tablet. El acceso desde hosting a impresoras de la red del local necesitará un puente local posterior; no está incluido.

### Cómo imprimir

1. Configurar y activar las impresoras en **Configuración → Impresoras**: una por destino (Cocina/Barra) y una propia por caja. Se usan los formatos **80 mm / 48 columnas** y **58 mm / 32 columnas**, con texto de la impresora, avance y corte. Las pruebas de conexión siguen disponibles.
2. En un pedido, abrir **Comandas / precuenta**. Exige guardar los cambios del formulario. Cada envío confirmado aparece por lote y destino; los productos de entrega directa no generan comanda. **Ver comanda** abre su vista previa. No vuelve a reservar, preparar ni entregar productos.
3. Para una **precuenta**, seleccionar la caja que debe imprimir y abrir **Vista previa de precuenta**. Muestra solo cantidades confirmadas sin cobrar, sus descuentos por producto restantes y envío aún pendiente. No incluye borradores ni descuenta dos veces grupos ya pagados. Una devolución no crea una nueva deuda. Los descuentos generales se deciden al cobrar y no se presuponen aquí.
4. Desde el detalle del cobro, **Imprimir ticket** abre el documento de ese cobro y sus pagos originales. Incluye productos, descuentos/cortesías, envío, total, medios combinados, referencia y efectivo recibido/vuelto. Puede imprimirse con el turno original ya cerrado; no recibe otro pago. La salida puede dirigirse a otra caja activa autorizada.
5. Revisar el documento, pulsar **Imprimir documento** y aceptar SweetAlert2. Se registra primero la solicitud y luego se envían los bytes fuera de la transacción de base de datos. Cancelar la confirmación no envía nada. Un error de impresión conserva pedido, inventario, cobro y pagos.

Confirmar productos y registrar cobros no imprimen automáticamente. El usuario solicita cada documento revisado. El ticket es **interno**, no una boleta/factura, y no cambia el estado en SUNAT. La cabecera usa los datos disponibles de Empresa; no se inventa un desglose de IGV. Texto ASCII y fuente incorporada en la impresora evitan depender de fuentes del navegador; las tildes se transliteran. La salida física y el corte deben comprobarse en el modelo concreto del restaurante.

### Lotes, anulaciones y copias

La comanda identifica pedido, atención/mesa, mozo, lote y destino; conserva notas y componentes de combos, sin precios ni costos. Muestra cantidades vigentes y avance de Cocina para evitar preparar de nuevo lo ya iniciado/listo/entregado. Los nuevos productos generan otro lote.

Las anulaciones y cancelaciones pagadas pendientes generan **avisos imprimibles por destino** en la misma pantalla. Indican cantidades ya canceladas y “no preparar / no entregar”; imprimir el aviso no vuelve a ejecutar la anulación. Es una solicitud explícita: verificar que Cocina/Barra recibió el aviso.

Un original de comanda/aviso/ticket solo se envía una vez, incluso con otra UUID o desde otro equipo. Volver a abrirlo muestra el documento registrado. La precuenta tiene versiones según su contenido pendiente; repetir la misma versión muestra su original. Si cambian las cantidades/beneficios/envío pendientes, se prepara una versión nueva.

Para otro ejemplar, usar **Preparar copia** en el historial, con `impresion.reimprimir`, motivo obligatorio y confirmación. La copia mantiene el contenido histórico, aparece marcada **COPIA / REIMPRESIÓN**, se enlaza al original y conserva su propio usuario/fecha/UUID. Puede usar la conexión actual corregida de la impresora o una caja autorizada distinta, sin reescribir la conexión original. Una copia de comanda no constituye un pedido nuevo.

### Estados y recuperación

| Estado | Significado / acción |
| --- | --- |
| En curso | Solicitud persistida y envío en ejecución; bloquea otro envío y cambios de esa impresora. |
| Enviado | Bytes enviados; verificar salida física, papel y corte. No se puede confirmar el resultado del papel por TCP. |
| Error | No pudo generarse el documento o conectarse con la impresora. Corregir la causa y solicitar una copia explícita. |
| Resultado sin confirmar | El envío pudo llegar total o parcialmente, o se interrumpió el proceso. Revisar el papel antes de solicitar una copia. |

No hay reintentos automáticos de red. La repetición de una petición devuelve su registro sin reenviar. **Actualizar estado** identifica solicitudes en curso de más de un minuto como resultado sin confirmar, sin volver a enviarlas. El historial conserva contenido, conexión, responsable, estado y motivo de copia. Los controles de configuración usan el mismo bloqueo de impresora y no se modifican destinos/conexión durante un envío.

### Permisos y actualización

- `impresion.comandas`: comandas y avisos de anulación, con acceso al pedido.
- `impresion.precuentas`: precuentas del pedido autorizado; no da acceso a tickets ni movimientos financieros.
- `impresion.tickets`: tickets, además de `cobros.crear` y alcance de la caja original; la salida debe ser una caja autorizada.
- `impresion.reimprimir`: copias, además del permiso de impresión del documento original.

La migración **000069** crea estos cuatro permisos y agrega las instantáneas y claves de documento al historial. Concede los cuatro al administrador existente; comandas/precuentas a cajero/mozo que ya tienen POS y envío de pedidos, y tickets al cajero que conserva cobros. No sincroniza ni reinicia los demás permisos. Los nuevos roles reciben esos mismos valores; copias quedan inicialmente para el administrador. No añade dependencias. También conserva la asignación de caja al editar conexión/ancho de una impresora ya asignada.

Pruebas de impresión cubren vistas previas, bytes contra receptor TCP, Cocina/Barra, combos/notas, saldo y descuentos pendientes, pagos/vuelto, permisos, copias, errores, resultado incierto, ancho y limpieza de caracteres de control. CI añade `tests/Support/printing-concurrency.php` en MySQL: mismo documento con UUID distintas, UUID repetida, copia repetida, impresión frente a configuración y precuenta frente a cobro. El transporte simulado mantiene el envío abierto hasta que compite la otra petición y comprueba que no hay transacción durante la red; usa solo testing y base desechable `sisrest_test`.

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


## Facturación electrónica: boletas y facturas

Se emite desde **POS → detalle del cobro → Emitir boleta / factura**. El historial general está en **Ventas → Comprobantes** (`/comprobantes`). La configuración está en **Config. sistema → Empresa y facturación** (`/configuracion/sistema/facturacion`). No se repite la navegación del sidebar en las cabeceras.

### Puesta en marcha

1. Actualizar el código de `codex/base-restaurante`, ejecutar `composer install`, `php artisan migrate` y `npm ci && npm run build`. PHP necesita `bcmath`, `intl`, `openssl`, `soap`, `dom` y `zip`, además de las extensiones habituales de Laravel. Greenter Lite y Bacon QR Code ya están en Composer; no se requiere wkhtmltopdf.
2. Completar RUC, razón social, dirección fiscal, ubigeo, departamento, provincia y distrito. Los precios siguen siendo finales, en soles y con impuestos incluidos.
3. Elegir **beta**, definir la tasa total de IGV/IPM y su vigencia según el régimen del negocio. No se supone que todos los restaurantes califican para una tasa reducida. Se admiten operaciones gravadas, exoneradas e inafectas; la afectación se confirma por línea al emitir. Las tasas especiales con fecha de término pueden limitarse con “Vigente hasta”.
4. Cargar certificado **PFX/P12** con clave privada y su contraseña, usuario SOL secundario **sin anteponer el RUC** y clave SOL del entorno. Configurar emisión electrónica y certificado en SUNAT según corresponda. El servidor requiere conexión a Internet para enviar/consultar; generar PDF e imprimir en LAN no envían a SUNAT.
5. Activar las series **B001/F001 de beta**, inicialmente inactivas. Las series de producción se crean aparte. Una serie nueva permite indicar el último correlativo ya usado en otro sistema; ese número no se puede reducir ni editar después desde la pantalla.
6. Para producción, volver a cargar certificado y credenciales del entorno, crear/activar sus series y confirmar expresamente la emisión real. No se reutilizan silenciosamente los secretos de beta.

El certificado se valida con OpenSSL, se cifra con la clave de Laravel y se guarda en el disco privado `local`; las credenciales usan casts cifrados. Los secretos guardados nunca se precargan en Livewire ni se incluyen en auditoría. Los campos nuevos en blanco conservan los secretos actuales. Una configuración modificada en otra sesión exige recargarla. No se puede cambiar el RUC después de emitir documentos ni modificar configuración durante un envío/consulta en proceso.

### Emisión

- Exige un cobro confirmado, pagos aplicados que cuadren, permisos y acceso al pedido/turno de origen; no requiere reabrir su caja. No altera cobros, pagos, movimientos de caja ni existencias.
- **Factura:** cliente con RUC válido, razón social y dirección. **Boleta:** admite DNI/RUC o cliente sin documento cuando el total no supera S/ 700. Se controla el umbral en servidor.
- Los productos, cantidades, descuentos y cargo de delivery se toman del cobro. Los componentes de un combo no vuelven a facturarse. Los descuentos se expresan por línea; el impuesto se extrae del precio final mediante BCMath.
- Una línea gratuita requiere escoger una afectación de transferencia gratuita: no se deduce del motivo de cumpleaños o cortesía. Conserva valor referencial y, cuando corresponde, impuesto de la transferencia separado del importe que paga el cliente.
- SweetAlert2 confirma la emisión. La configuración revisada se comprueba otra vez dentro de la transacción. Cada cobro tiene como máximo un comprobante por entorno; UUID, bloqueo de serie e índices únicos evitan duplicados y pérdida de correlativos.
- Firma y XML quedan guardados antes de habilitar el envío. Emisor, receptor, tasa, fecha, serie, totales, líneas y hash se conservan como datos históricos. Cambiar luego el catálogo o la empresa no reescribe el documento.
- Beta y producción tienen series, correlativos, archivos y vínculo al cobro independientes. Un ensayo beta no bloquea la emisión real del mismo cobro; ambos quedan identificados por entorno.

### SUNAT y recuperación

- **Factura:** envío del XML firmado mediante `sendBill`, conservando el CDR. **Boleta:** esta primera versión prepara un resumen diario de **una boleta por resumen**, con secuencia independiente por día/entorno; `sendSummary` devuelve ticket y `getStatus` recupera el CDR. No se simula aceptación por el solo hecho de obtener ticket.
- El resumen completa los campos que no trae la plantilla instalada de Greenter, como la tasa, nombre del receptor e impuesto de transferencias gratuitas. La modificación se hace **antes de firmar**, sin tocar `vendor`.
- El transporte usa endpoints oficiales fijos, verificación TLS, límites de conexión/lectura y trazas desactivadas. La llamada de red ocurre después de confirmar la transacción que registra el intento. No se exponen mensajes internos que pudieran contener credenciales.
- Estados: pendiente, enviado, aceptado, observado (aceptado con observaciones), rechazado y desconocido. Se verifica ZIP, tamaño, emisor y referencia del CDR antes de aceptar. Las constancias se conservan junto al intento.
- Un envío repetido conserva el intento existente y no vuelve a ejecutar SOAP. Si falta una respuesta verificable, queda **desconocido**. Una factura de producción puede consultar su CDR; una boleta con ticket puede volver a consultar su procesamiento. La consulta respeta el mismo ámbito de acceso, incluido el cajero responsable.
- Si un proceso se interrumpe, entrar al comprobante o pulsar **Actualizar** libera su estado en curso después de dos minutos y lo marca sin respuesta confirmada. No reenvía automáticamente. Una boleta sin ticket o una factura beta sin CDR requieren conciliación manual; esta entrega no incluye reenvío forzado, rectificación de XML emitido ni conciliación manual que cambie estados sin constancia.
- Se comprueba el plazo de envío de factura (3 días calendario siguientes) y resumen de boletas (7); si venció, se bloquea el envío y se solicita revisar la regularización.

### Archivos e impresión

- PDF A4, XML firmado, CDR y XML del resumen se descargan por rutas autenticadas y autorización del cobro de origen; no por enlaces públicos al almacenamiento.
- PDF y ESC/POS incluyen identificación, importes, leyenda, hash y QR. Beta lleva marca visible de prueba sin validez tributaria. El PDF se genera localmente con DomPDF, sin cargar recursos remotos.
- La impresión fiscal reutiliza vista previa, confirmación, impresora de caja, 58/80 mm, historial, control de duplicados y copias con motivo. Usa `impresion.tickets` más `comprobantes.ver`; una copia exige además `impresion.reimprimir`.
- Emitir/imprimir no cambia la respuesta de SUNAT ni genera otro cobro. Los tickets internos se conservan como documentos distintos de boletas/facturas.

### Alcance y comprobación

Esta entrega cubre boleta y factura al contado, en PEN, desde cobros ya confirmados. No incluye notas de crédito/débito, bajas, contingencia, anticipos, detracciones, percepciones, retenciones, ISC ni ICBPER. Un cobro con devolución confirmada no puede originar un nuevo comprobante por su importe original; una devolución posterior tampoco modifica un comprobante ya emitido. Su regularización mediante nota de crédito corresponde a la siguiente etapa.

Se probaron firma real con un certificado efímero de prueba, cálculo decimal, idempotencia, seguridad, CDR simulado, PDF, XML, impresión ESC/POS con QR contra receptor TCP, Livewire/navegador y concurrencia MySQL. **No se envió a SUNAT ni se certificó emisión real con credenciales del negocio.** La prueba física de la impresora y la validación con certificado/SOL reales quedan para la instalación.

Referencias: [Greenter: uso y resúmenes](https://greenter.dev/usage/), [boletas y resúmenes SUNAT](https://cpe.sunat.gob.pe/tipos_de_comprobantes/boleta), [guías y reglas SUNAT](https://cpe.sunat.gob.pe/guias-y-manuales), [IGV general](https://emprender.sunat.gob.pe/principales-impuestos/impuesto-general-las-ventas-igv/impuesto-general-las-ventas), [tasa especial para restaurantes](https://orientacion.sunat.gob.pe/reduccion-del-igv-para-restaurantes-hoteles-y-alojamiento-turisticos-1).

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

Greenter Lite evita exigir wkhtmltopdf. Los PDF de reportes se generarán con DomPDF; los tickets internos ya usan ESC/POS; la representación de comprobantes tributarios usa DomPDF y Bacon QR Code; Greenter genera y firma el XML.

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

## Identidad visual y configuración

El sidebar muestra iconos en los módulos desplegables. **Configuración** contiene **Empresa**, **Facturación**, **Tipografía** y **Colores de estado**; la configuración de salones, mesas, destinos e impresoras conserva su grupo.

- Empresa (`/configuracion/sistema/empresa`) permite guardar los datos del local y un logo PNG, JPG o WebP, hasta 2 MB/3000×3000 px. Se convierte a PNG, con un máximo de 768 px por lado, conservando proporciones y transparencia. El logo vive en almacenamiento privado y solo se sirve por una ruta pública limitada a los archivos de marca para poder aparecer en login y favicon. No requiere `storage:link`.
- El logo aparece en login, sidebar, pestañas, nuevos PDF fiscales, precuentas, comandas y tickets. Las instantáneas de emisión e impresión conservan la versión utilizada; no se eliminan logos anteriores que puedan estar vinculados a documentos. El encabezado reutilizable de futuros reportes está en `partials/reportes/encabezado.blade.php`; no se crea un módulo de reportes en esta entrega.
- Tipografía (`/configuracion/sistema/tipografia`) ofrece **Poppins, Inter y Roboto**, con archivos locales de pesos 400/600 y sus licencias en `public/fonts`. No descarga fuentes en el navegador ni durante el build. Permite tamaño de interfaz (12–18 px), PDF (8–14 pt) y tickets gráficos (9–16 pt).
- Los tickets mantienen por defecto texto nativo de la impresora. Activar **Usar esta fuente en impresiones térmicas** genera el texto con GD/FreeType y lo envía como imagen ESC/POS; permite las tres tipografías. El logo se imprime también con texto nativo. Se conservan el QR, las copias, el control de originales y los estados de envío. La compatibilidad de impresión gráfica corresponde al modelo físico.
- Colores de estado (`/configuracion/sistema/colores`) configura texto, fondo y borde por estado, con vista previa y restauración de valores predeterminados. Los módulos usan un componente semántico común; incluye activo/inactivo, estados operativos, SUNAT, mesas libres/ocupadas del POS y productos agotados. No cambia los estados permitidos ni sus reglas.
- Las tres pantallas nuevas requieren `configuracion.sistema`, revalidan el usuario activo y detectan cambios simultáneos. No modifican credenciales SOL desde Empresa o Apariencia. Guardar usa las alertas SweetAlert2 compartidas.

Actualizar con `composer install`, `php artisan migrate`, `npm ci`, `npm run build` y `php artisan optimize:clear`. La migración **000071** añade `empresa.apariencia`, sin reseedear ni sustituir datos existentes. PHP necesita GD con FreeType; el logo y las configuraciones guardadas deben incluirse junto con `storage/app/private` y la base de datos en las copias de seguridad. No se ejecutó la suite de pruebas para esta entrega, por solicitud del usuario.

## POS: plano de mesas y apertura práctica

La portada `/pos` muestra salones, mesas y un panel lateral de atención; la lista de pedidos abiertos pasa a `/pos/atenciones`, con filtros por modalidad y los mismos permisos de consulta. Se mantienen Para llevar y Delivery en el encabezado.

Las tarjetas incluyen icono de mesa, nombre, disponibilidad y, para atenciones accesibles al usuario, comensales, tiempo transcurrido y mozo. El plano se actualiza cada 30 segundos. Los colores utilizan la configuración de mesas libres/ocupadas. Una mesa libre se selecciona sin abrir formularios flotantes; el panel inicia con una persona, botones de cantidad y responsable obtenido del usuario autenticado, también revalidado en servidor. Una sola caja se elige automáticamente y varias permiten seleccionar la caja de operación. Continuar abre el pedido existente o crea la atención con el control transaccional previo. En pantallas pequeñas el panel queda debajo del plano y la selección desplaza la vista hacia él. No se modifica la pantalla de productos en esta entrega.

## POS: toma de productos simplificada

La pantalla del pedido organiza categorías, catálogo fotográfico y un único resumen. Pulsar repetidamente un producto aumenta su cantidad pendiente. La vista agrupa lotes confirmados y borradores del mismo producto, precio y nota, conservando los registros originales para cocina, cobros, entregas, beneficios y anulaciones. Notas o precios diferentes mantienen líneas distintas. Las cantidades pendientes tienen botones +/−, nota en modal y opción de quitar.

**Añadir** guarda el borrador y solicita confirmación para reservar y enviar únicamente las líneas nuevas. Si se cancela esa confirmación, las líneas quedan guardadas en borrador y pueden editarse o añadirse después. Los lotes enviados no se modifican retroactivamente: sus acciones permanecen dentro de **Entrega y opciones**. **Más opciones** conserva caja de operación, guardar sin enviar, descarte, comandas/precuenta y cierre. Los accesos Cobrar/Dividir verifican que no haya cambios sin guardar ni líneas pendientes de confirmar.

Se retiran de esta pantalla los controles de cliente, comensales y observación general, conservando sus datos existentes; la selección del cliente sigue en Cobros. Las notas por producto permanecen disponibles. No cambia el esquema de datos ni exige migración.


## POS: anulación completa de la atención

El icono de anulación junto a **Añadir** solicita motivo y confirmación mediante las alertas compartidas. Requiere `pedidos.anular_enviados` y, si hay mesa, `mesas.liberar`. Anula todos los productos y borradores y libera únicamente la ocupación de ese pedido, en una sola transacción. Conserva el historial y registra usuario, motivo y fecha. Reutiliza la anulación individual para liberar reservas, actualizar Cocina/Barra y registrar merma cuando ya se inició la preparación; en ese caso también exige `inventario.registrar_merma`.

No admite pedidos cerrados ni atenciones con cobros o cantidades entregadas: esos casos mantienen sus operaciones de cobro/devolución. La confirmación revalida el pedido y el avance de Cocina; si cambiaron desde la revisión, exige revisar de nuevo. Los borradores locales sin guardar se descartan al completar la anulación. No se agregan tablas ni migraciones.


## Cobro enfocado del POS

La pantalla de cobro utiliza un layout propio sin sidebar, con Volver al pedido. Conserva la selección de cantidades para dividir cuentas, descuentos autorizados, cargo de delivery de una sola vez, historial y caja autorizada. A la izquierda muestra consumos y resumen; a la derecha documento, búsqueda de cliente, caja y pagos.

El medio único permite seleccionar cualquier medio activo. Pago mixto conserva hasta diez filas, una por medio. Completar saldo calcula el importe restante en el servidor; Efectivo rápido completa lo aplicado y establece lo recibido (Exacto, 10, 20, 50, 100 o 200). Solo el efectivo genera vuelto e ingreso físico. Yape y Plin exigen número de operación, además de cualquier otro medio configurado con referencia obligatoria. Cambiar el medio limpia su referencia y efectivo recibido.

Nota de venta registra el cobro interno. Boleta/Factura requieren permiso de emisión, configuración habilitada y serie activa; Factura exige cliente con RUC y dirección antes de cobrar. Tras registrar el pago se abre la revisión fiscal existente con el tipo elegido: no se emite ni envía a SUNAT automáticamente, no se cobra de nuevo y siguen vigentes las validaciones fiscales. El documento elegido no cambia los importes ni el inventario. No requiere migraciones.


**Pago mixto con medios precargados:** al activarlo se muestran todos los medios activos con nombre fijo y campos de importe, conservando los datos ya ingresados. No hay que agregar ni elegir cada medio. Los importes vacíos o cero sin otros datos se omiten al revisar y confirmar; solo se registran los medios usados. Limpiar conserva el medio y vacía sus datos. Quitar o poner en cero un importe mixto limpia su referencia y efectivo recibido. Se mantiene el máximo de diez medios efectivamente usados por cobro; la lista visible puede incluir más medios configurados.


## Cocina informativa y entrega independiente

Cocina/Barra muestra una tarjeta resumida por comanda y destino, con cantidades vigentes, productos, notas destacadas, anulaciones, mozo y tiempo. El botón **Terminado** marca la comanda completa con fecha y usuario (`cocina.marcar_listo`), sin registrar entregas, cobros ni salidas de stock. Los filtros Pendientes/Terminadas usan esta marca informativa, no el avance de la atención. Las nuevas incorporaciones continúan creando sus propios lotes.

El mozo puede entregar desde POS sin esperar una marca de Cocina. Se mantienen cantidades máximas, reservas, permisos, bloqueo transaccional y controles de combos; cada entrega descuenta stock una sola vez. La entrega acredita técnicamente la cantidad mínima preparada para conservar la integridad histórica de cantidades, pero **no** marca la comanda como terminada por Cocina. El cierre requiere cuenta pagada y entregas confirmadas por el mozo; no exige `terminado_at`. Cocina no modifica la caja ni libera mesas. Los avances registrados siguen disponibles para la lógica histórica de anulaciones y mermas.

Ejecutar `php artisan migrate` para agregar `terminado_at` y `terminado_por` a `envios_cocina`. No se infieren marcas históricas de terminado ni se modifican pagos existentes.
