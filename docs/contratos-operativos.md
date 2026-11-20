# Contratos para implementar los módulos

Los módulos implementados y los futuros servicios de negocio deben cumplir estas reglas. Los apartados de cada entrega distinguen las operaciones disponibles de las pendientes.

## Transacciones e idempotencia

Confirmar compra, preparación, pedido, cobro, devolución y cierre de caja dentro de transacciones. Bloquear las filas necesarias con `lockForUpdate()` y artículos en orden por ID.

Las claves de idempotencia impiden repetir movimientos por doble clic o reintento. El servicio debe reconocer la operación completa ya confirmada, recuperar su resultado y no aplicar los efectos otra vez.

No modificar saldos desde Livewire sin el correspondiente movimiento.

## Inventario

- `articulos` contiene las existencias de insumos, preparaciones y reventa. Compras y Movimientos utilizan una sola clave de inventario.
- Insumos filtra tipo insumo; Productos para la venta utiliza `productos` enlazado a su artículo.
- Cada artículo utiliza una unidad base y presentaciones que convierten cantidades a ella.
- La presentación seleccionada debe pertenecer al artículo de la compra.
- La receta produce un artículo preparado y no consume su propio artículo de salida.
- Confirmar preparación consume ingredientes reales y registra producción una sola vez.
- El combo depende de sus componentes, sin existencia propia. No permitir combos anidados ni ciclos.
- Confirmar pedido reserva disponibilidad, sin consumir materia prima nuevamente.
- La entrega desde POS consume la reserva y registra salida del artículo de venta; Cocina solo avanza contadores. La estructura admite consumo y liberación parciales; la anulación de confirmados libera cantidades no iniciadas y registra merma para las iniciadas/listas, según la revisión y permisos descritos abajo.
- Cobrar no vuelve a descontar existencias.
- Anular libera lo reutilizable o registra merma; no devuelve automáticamente ingredientes consumidos.
- Cambiar recetas, combos o precios no reescribe operaciones históricas.

## Catálogos de inventario implementados

`GestionInsumos`, `GestionProductos` y `Presentaciones` administran metadatos; no generan movimientos, preparaciones, compras ni reservas. Los saldos y costos no se reciben de propiedades Livewire. El catálogo usa transacciones y el orden de bloqueo **áreas por ID → artículos por ID → productos por ID**, compatible con la configuración de destinos. Los servicios de recetas y producción mantienen ese orden. Compras conserva ese orden y luego bloquea proveedores por ID, la compra, el turno de caja si aplica y el medio de pago. Las futuras operaciones de caja deben respetar ese orden cuando combinen recursos.

- Consulta de insumos: `inventario.ver`. Gestión: `insumos.crear` / `insumos.editar`, agregados por migración sin dar gestión a lectores. Productos conserva `productos.ver`, `productos.crear` y `productos.editar`.
- La identidad del insumo conserva código y unidad; la del producto conserva además tipo y origen del artículo. Cada producto no combo obtiene un artículo propio y único. No se crean artículos para combos.
- Presentaciones pertenecen a su artículo. Se validan IDs, nombres y factores decimales positivos; las omitidas se desactivan. Si una compra ya referencia una presentación, su equivalencia no puede cambiar. Las compras conservan también su factor instantáneo.
- Los combos se editan por componentes no combo, sin repetidos ni ciclos. Sus operaciones históricas conservarán las líneas y reservas de cada pedido; editar la configuración no reescribe pedidos.
- Desactivar se bloquea por reservas, recetas activas, combos activos o pedidos en curso según el recurso. Los cambios de precio, visibilidad, nombre y fotografía conservan saldos y costo promedio.
- Disponible se calcula con BCMath desde saldos menos reservas, y en combos toma el mínimo entero de sus componentes. Esta consulta no sustituye la validación y reserva transaccional al confirmar desde POS.
- Las fotografías se validan, guardan con nombres generados en el disco público y se limpian si falla la transacción; el archivo anterior se elimina después de guardar correctamente.

## Recetas y producción implementadas

`GestionRecetas` y `GestionPreparaciones` comparten áreas → artículos → productos, en orden por ID. Después se bloquea el borrador afectado. El cálculo no utiliza floats; BCMath normaliza cantidades a cuatro decimales y costos a seis.

- Receta única por producto preparado, rendimiento positivo, hasta 50 ingredientes únicos y sin consumo de su propia salida. Se recorre el grafo de recetas para impedir ciclos indirectos. Los preparados pueden consumirse como ingredientes usando sus saldos existentes, sin producción recursiva ni nuevo consumo de su materia prima.
- La previsualización escala por rendimiento y redondea hacia arriba en cuatro decimales. Su huella incorpora producto, cantidad y versión de la receta; guardar exige que siga vigente. Los costos no se reciben del cliente.
- UUID único para creación de borradores. El borrador copia producto, unidad, rendimiento y cantidades estándar/reales; no altera ni reserva inventario. Los cambios de consumos requieren permiso independiente y motivo. No se permite agregar ingredientes ajenos al cálculo ni consumos totalmente cero.
- Edición/anulación requiere crear y ser responsable, o crear y poder confirmar. Las revisiones de borrador usan una huella para rechazar sobrescrituras. La confirmación captura otra huella al abrir SweetAlert2 y comprueba que no cambió la revisión.
- Confirmar un borrador vuelve a leer saldos y costos bajo bloqueo, comprueba activos/unidades y compara el consumo contra actual menos reservado. Todas las validaciones preceden a movimientos y saldos. Un error revierte también los costos y estado.
- Por cada consumo positivo se registra un movimiento `consumo_preparacion` con cantidad negativa; por la salida se registra uno `produccion`. Clave única `prep:{id}:{tipo}:{articulo_id}` y estado confirmado bajo bloqueo hacen idempotente la repetición. Un consumo real cero conserva su detalle sin generar un movimiento cero.
- Costos por línea = consumo real × costo promedio vigente al confirmar, redondeados a seis decimales. Total = suma de líneas. La salida utiliza promedio ponderado de valor anterior y costo del lote sobre saldo resultante; su movimiento conserva el costo unitario de ese lote. No se alteran reservas ni el costo promedio de los ingredientes al consumirlos.
- Preparación confirmada es inmutable. Cambiar receta, precios, nombres o costos después no cambia los datos guardados. Una receta modificada pero activa no recalcula borradores ya guardados al confirmarlos; editar exige recalcular. Una receta desactivada impide confirmar sus borradores.
- Anulación solo para borradores con motivo, sin movimientos. Las correcciones de lotes confirmados y mermas siguen pendientes de sus servicios; no se implementa devolución automática de ingredientes utilizados.
- CI añade prueba real en MySQL con procesos simultáneos: distintos borradores no pueden gastar las mismas últimas existencias y la misma preparación solo genera sus movimientos una vez. Las pruebas se restringen a `sisrest_test` en entorno testing y limpian sus propias filas.

## Configuración de salones y mesas

Las pantallas de Salones y Mesas usan `configuracion.restaurante` y el servicio `GestionRestaurante`. No eliminan registros; los cambios conservan responsable y valores en auditoría.

- Una mesa se puede ofrecer únicamente si ella y su salón están activos. Desactivar un salón conserva las activaciones individuales de sus mesas.
- El número/nombre de mesa es único dentro de cada salón; el código de salón es globalmente único.
- Una ocupación en `ocupaciones_mesa` bloquea cambios a la mesa aunque su pedido ya esté cerrado. También bloquea cualquier pedido no cerrado ni anulado, aunque falte la ocupación.
- Antes de editar, mover o cambiar el estado de una mesa, se comprueba que esté libre. Antes de desactivar un salón se comprueba lo mismo para todas sus mesas. Guardar un formulario no permite saltarse estas comprobaciones.
- La configuración bloquea los salones en orden por ID y después la mesa afectada. **POS toma primero los salones y luego las mesas**, comprueba ambos estados y crea la ocupación dentro de la misma transacción. Vuelve a leer el salón bajo bloqueo actual para no utilizar una instantánea anterior de MySQL después de esperar.
- Las operaciones que afecten varias mesas/salones deben mantener ese mismo orden de bloqueo. Las pruebas comprueban los cambios de ocupación durante la edición de configuración; CI incluye dos procesos compitiendo por abrir la misma mesa desde POS.

## Pedidos y cocina

- Debe existir caja abierta para operar ventas.
- Una mesa tiene una ocupación activa; el pedido conserva su mesa histórica.
- Adicionales y componentes de combo son líneas hijas del mismo pedido.
- Los componentes no se cobran otra vez como productos independientes.
- Cada envío registra cantidades nuevas y su destino.
- Contadores acumulativos: iniciada >= lista >= entregada; cantidades anuladas no son pendientes.
- Al anular productos ya iniciados, ajustar contadores coherentemente dentro de la transacción.
- Pago y preparación son independientes.
- Liberación manual de mesa, sin saldos ni entregas pendientes.

## Cobros y caja

- `cobros` liquida consumos seleccionados de un pedido, con caja, cliente y descuentos.
- Bloquear líneas para impedir cobrar cantidades ya asignadas a otro cobro.
- Validar que los detalles pertenezcan al pedido.
- Suma de pagos aplicados igual al total confirmado.
- Dinero con decimales exactos, sin float.
- `pagos.monto` es el importe aplicado; monto entregado y vuelto documentan la entrega y el cambio, sin duplicar efectivo.
- Solo los medios que afectan efectivo generan movimientos físicos.
- Fondo inicial en el turno; no registrarlo también como ingreso.
- Monto positivo, tipo de movimiento determina signo.
- Turnos cerrados no reciben movimientos. Devolución posterior utiliza turno abierto que entrega dinero.
- Cierre conserva efectivo esperado, contado y diferencia.
- `TurnoCaja::efectivoEsperado()` calcula fondo y movimientos físicos, sin sumar pagos digitales.
- Compra al crédito no genera egreso hasta pagarse. El pago de caja genera un único egreso.
- Validar origen de fondos, medio de pago y turno.

## Beneficios y devoluciones

- Sin acumulación de descuento general y por producto sobre los mismos consumos.
- Validar permiso, motivo, autorización y límites en el servidor.
- Distribuir descuentos y redondeos entre cantidades al dividir cuenta.
- Cortesía conserva cantidad y precio de referencia; definir afectación tributaria antes de emitir.
- Devolver dinero no devuelve stock ni modifica por sí mismo un comprobante.
- No superar lo cobrado, considerando devoluciones previas.

## Configuración de destinos e impresoras

`GestionDestinos`, `GestionImpresoras` y `ProbarImpresora` comparten el orden de bloqueo: áreas de preparación por ID, impresoras por ID, cajas por ID. Se comprueba el usuario activo y el permiso correspondiente dentro de la transacción. Las futuras operaciones que asignen productos, creen envíos o seleccionen impresora deben mantener ese orden antes de modificar filas relacionadas.

- Configurar destinos requiere `configuracion.restaurante`; configurar/asignar/probar impresoras requiere `impresoras.gestionar`. Ambos se autorizan en rutas, componentes y servicios.
- Los códigos se mantienen estables después de crear, incluidos Cocina, Barra y las impresoras iniciales, para conservar referencias y seeders repetibles.
- No se desactiva un destino con productos activos ni cantidades enviadas que aún no se entregaron/anularon. Desactivarlo conserva el estado individual de sus impresoras; la impresión efectiva requiere ambos estados activos.
- Una impresora tiene uso general, caja o preparación. Cambiar el uso elimina su asignación anterior. Sustituir la impresora de una caja es explícito en el selector y queda auditado. Se admite una impresora activa por destino y no se duplican conexiones activas IP/puerto.
- Las pruebas se almacenan en `pruebas_impresion`, sin crear pedidos, comprobantes ni trabajos de tipo comanda. UUID único y configuración confirmada evitan reenvíos por repetición de peticiones o cambios concurrentes.
- Se registra `procesando` antes de abrir el socket, y la comunicación se realiza fuera de esa transacción. `enviado` significa bytes enviados, no salida de papel. La desconexión tras iniciar escritura es incierta. No hay reintentos automáticos; las pruebas interrumpidas de más de un minuto pasan a resultado incierto sin envío nuevo.
- Los trabajos operativos pendientes/procesando y las pruebas en curso bloquean cambios de configuración. Al implementar el consumidor operativo, deberá tomar el mismo bloqueo y conservar la instantánea antes de comunicarse con el equipo.
- El transporte actual es TCP directo desde PHP a una IPv4 y puerto. La impresión desde hosting hacia una LAN requiere un puente local futuro. Se validó con receptor TCP simulado, no con una POS80 física.

## Facturación e impresión

- Correlativos con bloqueo de serie compartida; no usar MAX()+1 sin bloqueo.
- Conservar instantáneas del emisor, receptor y configuración tributaria.
- Validar series, afectación, gratuidad, notas y referencias antes de habilitar emisión.
- Notas vinculadas al original; series de notas para boletas y facturas según corresponda.
- Estado de envío separado del cobro.
- Error de conexión distinto de rechazo. Resolver resultado incierto antes de una nueva emisión.
- Trabajos de envío e impresión después de confirmar la transacción.
- Enviado a impresora no garantiza papel impreso; contemplar resultados inciertos.
- Reimpresión referencia al trabajo original, sin duplicar pedido o cobro.

## Compras y pagos implementados

- `GestionCompras` y `GestionProveedores` reautorizan al usuario activo dentro de la transacción. Orden compartido: áreas → artículos → productos → proveedores (todos por ID), después compra → turno de caja → medio de pago. Caja usa el prefijo áreas/artículos/productos y después cajas por ID → turno. Nunca tomar cajas después de bloquear un turno. Todos los escritores financieros deben bloquear el turno antes de su historial; no introducir bloqueos inversos.
- Solo insumos/reventa activos, unidades activas y presentaciones propias. El factor y la cantidad base se conservan; se rechaza pérdida de precisión en la conversión. Sin duplicados por artículo, máximo 50 líneas.
- Precio final por presentación con seis decimales, total de línea redondeado a dos menos descuento. El impuesto incluido se desglosa, no se suma otra vez ni se deduce del valor operativo ingresado. No se supone una tasa ni un tratamiento de crédito fiscal.
- Borrador sin movimientos. UUID para alta idempotente; versión de cabecera/detalles para edición/confirmación. Documento único por proveedor/tipo/serie/número.
- Confirmación atómica de ingreso y costo promedio ponderado, con reservas intactas y una clave de movimiento por compra/artículo. Recepción al contado incluye pago completo dentro de la misma transacción; crédito permite pagos posteriores.
- Pago con permiso específico, saldo comprobado bajo bloqueo de compra y UUID único. Cambiar el importe editable después de revisar no altera el pago confirmado. No se paga un borrador ni se excede la deuda; no se vuelve a recibir inventario al pagar.
- Caja: turno abierto autorizado, efectivo y fondo suficiente; egreso único enlazado al pago. Digital de empresa y dinero externo del dueño no afectan efectivo físico ni se vinculan a un turno. No se inventa una apertura si no existen turnos.
- Proveedor inactivo bloquea nuevas compras/confirmaciones pero permite cancelar deuda. Solo se anulan borradores con motivo; recepción confirmada/pagos no se editan ni se revierten automáticamente.
- Instantáneas de proveedor/documento, descripción/unidad/presentación/factor/precio y nombre del medio de pago. Las compras tienen su propio permiso de consulta de importes; no exponen el costo promedio de inventario en propiedades públicas Livewire.
- CI MySQL añade recepciones simultáneas con valorización, repetición de recepción, pagos compitiendo por la deuda y pago repetido concurrente. Entorno/base desechable obligatorios y limpieza limitada a sus fixtures.

## Cajas y arqueos implementados

- Acceso propio o general con usuario activo, comprobado en consulta y escritura. Ver todas no concede acciones. La ocupación de cajas ajenas puede consultarse sin exponer responsable, turno ni importes.
- Apertura con UUID y una caja física activa/libre bajo bloqueo de caja. Fondo en el turno, sin movimiento duplicado. Responsable propio o asignación explícita con alcance general; nombre de caja/responsable e iniciador conservados.
- Prefijo transaccional compatible con inventario (áreas/artículos/productos), luego cajas por ID → turno. Compras mantiene proveedor → compra → turno → medio después del prefijo. No leer/bloquear compras o cajas desde un turno ya bloqueado; la relación de caja en pagos es lectura normal, no bloqueo.
- Al escribir, usar lecturas actuales con bloqueo para el turno, UUIDs, movimientos, cobros y pagos del resumen. Una lectura normal de MySQL REPEATABLE READ iniciada antes de esperar otro escritor no basta para validar el efectivo. `efectivoEsperado(bloquear: true)` se utiliza al pagar desde caja.
- Movimientos manuales positivos, concepto/motivo, permiso por tipo, versión e idempotencia. No editar/borrar filas del historial. Rechazar saldo negativo o desborde; fondo + ingresos − egresos − retiros es la única fuente del efectivo esperado.
- Cobros por medio suman importes aplicados de ventas confirmadas antes de devoluciones; no incluyen fondo ni ingresos manuales. Se muestran separados del efectivo; no se suman de nuevo al saldo.
- Arqueo parcial sin ajuste de dinero. Cierre atómico y único, guardando esperado/contado/diferencia, resumen, autor y fecha. Diferencia exige explicación; los valores revisados son inmutables durante la confirmación.
- Si cambia el historial durante la revisión, rechazar y actualizar. Tras cierre no hay nuevos movimientos/pagos ni arqueos, ni reapertura del mismo turno. Nuevo turno con fondo explícito, sin arrastre automático. Cerrar no altera pedidos/mesas pendientes.
- Resumen final y nombres de apertura se conservan aunque se renombren medios, caja o usuarios. Registros previos sin instantánea usan datos existentes como respaldo; no se inventan valores históricos.
- Pruebas reales de procesos simultáneos para aperturas, egreso/cierre, pago a proveedor/cierre e idempotencia de cierre/movimiento. Entorno y base de pruebas obligatorios; solo se limpian filas del escenario.

## Clientes implementados

- Consultar, crear y editar son permisos distintos; todo acceso exige usuario activo y `clientes.ver`. La activación/desactivación requiere editar. Cambiar el rol después de abrir un formulario o alerta se comprueba de nuevo al escribir.
- Persona con documento opcional; empresa con RUC/razón social/dirección. RUC también puede pertenecer a persona natural. Validación local de formato/longitud, sin consulta de identidad ni condición fiscal externa.
- Documento único por tipo/número incluso inactivo; conservar ceros iniciales y normalizar a mayúsculas. Sin documento se permiten personas diferentes con el mismo nombre, pero repetir el UUID de una misma alta no crea otro cliente.
- Transacción: tipos de documento por ID → cliente → comprobación de vínculos de pedidos/cobros. Lecturas actuales bajo bloqueo al escribir. Registrar/editar al cliente fuera de la transacción de atención; POS bloquea el cliente antes del pedido al asignarlo para comprobar estado. No llamar este servicio desde un pedido/cobro ya bloqueado.
- UUID y creador para altas idempotentes; versión de campos guardados para edición/estado. Un UUID ajeno no puede usarse para consultar o actualizar otra alta. Registros antiguos mantienen metadatos nulos.
- Si hay pedidos o cobros, no cambiar tipo ni un documento ya registrado. Se puede completar un documento pendiente y actualizar nombre/contacto. Los comprobantes deben conservar su propia instantánea del receptor cuando se implemente emisión; editar el catálogo no reescribe otros modelos.
- Desactivar no elimina información ni altera vínculos. POS omite inactivos en nuevas selecciones y conserva la referencia ya asignada. No hay borrado físico desde estas pantallas.
- Auditoría explícita de creación/edición/estado, con actor y campos antes/después. Alertas globales para confirmar estado y notificar guardado. Sin enlaces de módulo repetidos en cabeceras.

## Fotografías de productos

La foto guardada se sirve por una ruta autenticada con `productos.ver` o `pos.ver`, relativa al origen actual y sin depender de `APP_URL` ni del enlace `public/storage`. Consultar imágenes desde POS no habilita administrar productos. El controlador solo acepta nombres simples bajo `productos/`, extensiones JPG/PNG/WebP y MIME real de imagen; rutas ajenas, archivos ausentes o contenido inválido devuelven 404. La URL versiona el nombre del archivo para actualizar la caché al reemplazarlo. No es un servidor genérico de archivos ni recibe rutas del navegador.

## POS: borradores implementados

- `GestionPedidos` reautoriza usuario activo, `pos.ver`, alcance y permiso de acción dentro de la transacción. Propio significa responsable o creador; alcance general permite asignar otro responsable habilitado. No se expone información del pedido ajeno en la tarjeta de mesa.
- Orden: áreas → artículos → productos → salones → mesas → cajas, cada grupo por ID; luego turno y, al guardar, tipos de documento → cliente → pedido → detalles. La relectura de caja tras el turno se hace sobre una fila ya bloqueada, sin invertir la adquisición de recursos. Clientes no debe adquirir recursos de inventario/caja. Mantener estos contratos con Configuración, Inventario, Clientes y Cajas.
- Abrir y guardar exigen turno abierto y caja activa. El turno de apertura es histórico; operar más tarde con otra caja abierta no lo cambia. No se dan permisos financieros al mozo por seleccionar un turno.
- Apertura idempotente por UUID y creador; ocupación y comprobación de pedidos activos dentro de la misma transacción. Las mesas/salones inactivos o las ocupaciones previas impiden abrir. Repetir una apertura devuelve su pedido original, sin crear otro.
- Cantidades de productos raíz enteras positivas hasta 9999; máximo 50 líneas de borrador. Precios, nombres y destinos proceden del servidor, no de propiedades editables. Las líneas ya guardadas conservan sus instantáneas. La versión incluye pedido y todos sus detalles; otra edición o confirmación invalida el formulario.
- El combo conserva componentes hijos, cantidades, factor y destino; su precio se cobra en la raíz y los hijos tienen precio cero. Al editar cantidad se usa el factor guardado, sin sustituirlo por la composición vigente. La confirmación reserva esas instantáneas.
- Validar activo/visible/categoría de la raíz; un componente no necesita ser visible individualmente, pero sí estar activo y tener artículo/unidad y destino habilitados cuando corresponda. Disponibilidad del carrito agregada por artículo, incluyendo combos e individuales. **El borrador no reserva ni mueve inventario**: validar al guardar no garantiza disponibilidad futura.
- Solo se editan/eliminan líneas en borrador sin cantidades cobradas, entregadas, anuladas ni referencias a reservas, envíos o cobros. Las confirmadas permanecen intactas al guardar nuevos borradores.
- Cambiar cliente requiere `clientes.ver` antes de consultar el ID; se autoriza también el pedido antes de esa consulta. Una nueva asignación requiere cliente activo; se conserva el cliente histórico inactivo. Alta/edición de cliente se hace desde su módulo, fuera de la transacción de atención.
- Descartar exige motivo, versión y edición; en mesa también `mesas.liberar`. Solo pedidos sin confirmados/cobros y sin referencias operativas. Guarda usuario/fecha/motivo y cantidades anuladas, conserva detalles y libera únicamente su ocupación. No necesita caja abierta ni mueve stock/dinero. Repetir no duplica efectos.
- Delivery guarda contacto y costo de envío manuales al abrir; no hay despacho ni asignación de repartidor/taxi en esta etapa. El total mostrado es referencial, sin cobro, impuesto desglosado ni comprobante.
- CI MySQL prueba aperturas concurrentes de mesa, UUID repetido, descarte repetido y rechazo de una edición con versión anterior. Confirmación/reservas, Cocina y entregas se describen abajo; cobros y cierre se describen más adelante; beneficios/anulaciones e impresión operativa se describen más adelante; la emisión tributaria se integrará después.

## Confirmación, Cocina y entrega implementadas

- `TransaccionPos` comparte áreas → artículos → productos → salones → mesas → cajas por ID. Confirmar bloquea después turno → pedido; Cocina y entrega bloquean pedido → líneas/envíos/reservas. Releer un artículo al entregar es seguro porque su bloqueo ya se adquirió en el prefijo. No introducir escritores que adquieran inventario después de un pedido sin ese prefijo.
- `ConfirmarPedido` exige `pedidos.enviar`, usuario activo y alcance POS. Turno abierto/caja activa para una nueva confirmación; un reintento del UUID original reconoce su resultado sin depender de que continúe abierto. No reutilizar UUID de otro usuario/pedido.
- Versión del pedido y detalles verificada bajo bloqueo. Los borradores con referencias, confirmación previa o contadores operativos no pueden confirmarse nuevamente. Validar estructura del combo guardado, factores/cantidades, producto/unidad activos y destino histórico habilitado. No reemplazar precio ni composición por el catálogo actual.
- Agregar necesidades por artículo de todas las hojas nuevas; comparar contra actual menos reservado antes de escribir. Lote/reservas/envíos/estado/auditoría atómicos; un faltante revierte todo. UUID único para confirmación y clave única de reserva por lote/detalle. Una raíz combo no genera reserva ni envío.
- Lotes numerados por pedido bajo su bloqueo, considerando envíos anteriores para no repetir números. Envíos separados por destino, con nombre/nota/cantidad y nombre histórico del área. Entrega directa conserva reserva pero no crea envío de cocina. No imprime ni cobra.
- `GestionCocina` exige consulta y permiso específico para iniciar/listo. Versión de la línea rechaza acciones duplicadas/concurrentes. Acumulados: iniciada + anulada ≤ cantidad; lista ≤ iniciada; entregada ≤ lista. Cantidad positiva exacta con cuatro decimales. Pedido terminal, detalle no confirmado o destino inactivo impiden avanzar. No depende de caja abierta ni cambia stock.
- `GestionEntregas` exige `entregas.confirmar`, POS y alcance del pedido. Cantidad ≤ pendiente y, si tiene destino, ≤ lista sin entregar. Un detalle solo admite su reserva histórica y debe cubrir el consumo. Un borrador, combo raíz o confirmado antiguo sin reserva no se convierte automáticamente en operación válida.
- Entrega atómica: salida negativa tipo entrega, saldo anterior/posterior y costo promedio vigente conservados; bajar actual y reservado por la misma cantidad; aumentar consumida en reserva y entregada en detalle/envío. La disponibilidad libre no cambia por consumir una reserva propia. Clave del movimiento por detalle/cantidad acumulada y versión del pedido impiden doble descuento. Los errores revierten también contadores.
- La raíz combo calcula unidades completas entregadas como mínimo de cantidades entregadas/factor de sus componentes, sin una segunda salida. Se conserva precisión de cantidades fraccionarias; la raíz usa unidades enteras.
- Recalcular estado desde todos los confirmados: listo si todas sus hojas están listas (directos se consideran listos), entregado si todas están entregadas; en otro caso en preparación. Nuevos lotes no reinician avances anteriores. La mesa sigue ocupada hasta completar la liquidación y cerrar la atención manualmente.
- Livewire conserva UUID/versión/turno revisados al confirmar y detalle/cantidad/versión al entregar como campos bloqueados. Exige guardar cambios del borrador antes de revisar. La actualización visual no convierte una edición anterior en una escritura válida. Cocina/confirmados consultan cada cinco segundos sin recarga completa.
- Persistencia nueva en migración 000065, con vínculos opcionales para datos anteriores. No se infieren reservas, destinos ni confirmaciones para históricos. CI con procesos MySQL verifica competencia por stock, UUID repetido, avance de cocina simultáneo y entrega repetida.

## Cobros y cierre implementados

- `GestionCobros` comparte `TransaccionPos`: áreas → artículos → productos → salones → mesas → cajas, por ID; después turno → medios por ID → tipos de documento → cliente → pedido/detalles → cobros/pagos/movimientos. El prefijo es compatible con Cocina, Cajas y Compras. Clientes no debe adquirir recursos de inventario/caja desde un cliente ya bloqueado.
- Exigir usuario activo, `cobros.crear`, acceso POS propio/general y turno de caja autorizado propio/general. Caja activa y turno abierto para un nuevo cobro. El ingreso automático se autoriza por cobro; no concede ni necesita permiso de ingresos manuales. Nunca crear un movimiento sin el turno bloqueado.
- Previsualizar usa la misma validación transaccional del registro. Solo productos raíz confirmados del pedido, cantidades enteras positivas y no mayores al pendiente. Comparar cantidad cobrada con el historial confirmado antes de aumentar. No cobrar hijos de combo, borradores, líneas ajenas, precios del navegador ni beneficios sin autorización registrada.
- Precio/nombre de la instantánea del pedido; cantidad × precio exactos, total limitado a la precisión de persistencia. CobroDetalle solo para las raíces; propagar cantidades cobradas a componentes con sus factores históricos, sin otro cargo. Si un factor es inválido, revertir todo el cobro y contadores.
- Cada grupo puede tener cliente/alias propio sin modificar el cliente del pedido. Autorizar cambio antes de buscar un ID y bloquear cliente antes del pedido. Nueva selección activa; mantener referencia previa inactiva. Guardar nombre/documento/dirección, caja y cajero para el registro histórico.
- Cobrar el saldo completo del envío solo cuando se seleccione; sumar cargos de cobros confirmados bajo bloqueo. Permitir envío solo y bloquear repetición. La huella incluye pedido/detalles/delivery/cobros, por lo que un cobro de envío también invalida revisiones anteriores aunque no cambien cantidades de productos.
- Pagos activos sin medios repetidos, importes positivos y referencia según configuración. Suma de aplicados = total. Efectivo recibido ≥ aplicado, vuelto = recibido − aplicado; digitales sin vuelto. No generar pagos por cero. Un total cero de productos del catálogo o beneficios autorizados puede quedar confirmado sin pagos.
- Confirmar exige huella de revisión que incluye precios/cantidades, cliente y medios. Livewire conserva UUID, turno, selección, datos y pagos revisados en campos bloqueados. Cambiar inputs no cambia el cobro que se aceptó; cambios en datos persistidos exigen otra revisión.
- UUID único, ligado a actor/pedido/turno; reintentar reconoce su cobro aunque la caja ya cerró. Reautorizar alcance antes de devolverlo. No duplicar detalles, pagos ni movimientos. Cobro/cantidades/pagos/efectivo/auditoría en una sola transacción; confirmar no modifica stock ni preparación.
- Ingreso físico solo por importe aplicado de cada pago que afecte efectivo. Guardar nombre y clasificación del medio en Pago; clave de movimiento por pago y origen enlazado. Resumen de caja usa denominación histórica y suma aplicados por medios aparte del efectivo. Validar que fondo/movimientos más el ingreso no excedan el rango permitido.
- Historial/detalle exigen permiso de cobro, alcance del pedido y de su caja. El estado operativo de cuenta pagada puede verse desde POS sin exponer el historial financiero al mozo. Registros confirmados inmutables; devolución/anulación futura debe tener sus propios movimientos y trazabilidad.
- `CerrarAtencion` exige edición y, en mesa, liberar. Versión actual; sin borradores, unidades/envío por cobrar, discrepancia entre historial y contador pagado, entregas pendientes, reservas pendientes ni envíos sin entregar. No exige caja abierta ni modifica dinero. Cierre repetido no libera otra ocupación; solo se elimina la del pedido cerrado.
- Migración 000066 con instantáneas opcionales, sin reconstruir datos de cobros anteriores ni conceder permisos nuevos. Cobro interno sin comprobante ni cálculo tributario supuesto; emisión SUNAT independiente y pendiente.
- CI MySQL comprueba cobros concurrentes de las mismas unidades, UUID repetido, envío cobrado una vez y cobro frente a cierre. Un fallo revierte toda operación; stock y reservas permanecen iguales al cobrar.

## Anulaciones y beneficios implementados

- Servicios usan el prefijo de `TransaccionPos`. Anulación bloquea pedido → detalles → reservas/envíos, con artículos ya bloqueados en el prefijo. Beneficios de producto bloquean motivos por ID → pedido/detalle. Cobros agrega motivos después de medios, antes de documentos/clientes/pedido. Conservar el orden compartido y reautorizar usuario activo, acción y alcance al escribir.
- Anular requiere `pedidos.anular_enviados`, cantidad raíz entera positiva y motivo. Solo cantidades no cobradas/no entregadas; comprobar historial confirmado contra contador. En combo validar cada hoja según su factor histórico y sus propios contadores. No anular hijos de forma aislada ni referencias antiguas sin reserva/confirmación válida.
- Consumir primero sin iniciar, después iniciadas no listas y finalmente listas no entregadas. Lo no iniciado aumenta liberada en reserva; lo iniciado/listo exige `inventario.registrar_merma`, aumenta consumida y genera movimiento negativo merma. Bajar reservado por toda la anulación y actual solo por merma. Sin devolución de materia prima ni efectivo.
- Ajustar anulada/iniciada/lista en el envío de una sola vez, conservando entregada y límites de base de datos. Guardar instantánea anterior/posterior, artículo, cantidades liberada/merma, motivo y autor. La raíz combo no genera otro movimiento. Mantener líneas confirmadas con cantidad anulada acumulada, sin eliminar su historia.
- Huella de anulación incluye pedido/cobros, avance de Cocina, reservas y saldos; no basta el estado general del pedido. Si Cocina avanza durante la revisión, exigir revisar otra vez. UUID ligado a actor/pedido/raíz reconoce reintentos sin duplicar merma/reserva. Estado recalculado sobre cantidades vigentes; cierre de atención continúa siendo manual.
- Beneficios por producto requieren permiso específico; sustituir/retirar requiere también permiso del anterior. Motivo activo compatible y texto obligatorio. Raíz confirmada vigente sin ningún cobro; no cambiar autorizaciones después de liquidar cantidades. Precio/cantidad de referencia conservados y presupuesto de descuento separado. Historial inmutable de antes/después, motivo y responsable.
- Porcentaje positivo ≤100 o soles positivos ≤subtotal vigente de la línea; soles significa total para esa línea. Cortesía usa el importe vigente completo, no elimina ni libera inventario. Validar autorización existente al cobrar; un metadato histórico incompleto exige reautorizar, no se presume un beneficio válido.
- Distribuir presupuesto pendiente menos descuento de cobros confirmados según cantidades pendientes. Última cantidad toma el resto exacto; usar BCMath y céntimos. Al anular unidades no pagadas, retirar su proporción del presupuesto pendiente, sin reescribir descuentos ya consumidos por cobros anteriores.
- Si un descuento cubre el importe completo, tanto en producto como general, exigir también `cortesias.aplicar`, incluso por redondeo. Retirar/reemplazar un descuento de producto completo conserva esa exigencia.
- Descuento general solo sobre productos seleccionados de ese cobro, nunca envío. Permiso, motivo y explicación revalidados al confirmar. No combinar con beneficios por producto dentro del mismo cobro. Distribuir entre detalles por sus subtotales con restos en céntimos, conservando subtotal, precio y total neto.
- Cobro guarda descuento_productos o descuento_general; CobroDetalle separa descuento_producto/general, suma en descuento, cortesía y autorización histórica. Pago suma exactamente el neto. Una fila completamente vacía se ignora; no crear pagos por cero. Registrar cortesía no mueve dinero ni inventario, entregar sigue consumiendo normalmente.
- Livewire bloquea objetivos, UUID, huella y valores revisados. Padre exige guardar su borrador antes de abrir acciones de producto. Historial visible en el pedido, sin exponer costos de inventario a quienes no los consultan. Motivos/descuentos de recibos confirmados permanecen congelados aunque cambien nombres del catálogo.
- Migración 000067 no modifica beneficios ni saldos históricos. Nuevas tablas de anulación/detalles e historial de beneficios; instantáneas en pedido y cobro. Sin permisos nuevos ni reinicio de roles. CI de procesos MySQL verifica anulación/Cocina, anulación/cobro, beneficio/cobro e idempotencia con merma.

## Siguiente alcance

Usuarios y permisos, Salones y Mesas, Destinos de preparación, Impresoras, Insumos, Productos para la venta, Recetas, Preparación diaria, Compras, Proveedores, Cajas, Arqueos, Clientes, POS, Cocina/Barra, Cobros, Anulaciones/Beneficios y Devoluciones ya tienen pantallas, servicios transaccionales y autorización de acciones. Siguen ajustes/mermas ajenos a anulaciones/devoluciones, gráficos, reportes y facturación de pruebas. Se imprimen pruebas, comandas, avisos, precuentas y tickets internos por TCP. La emisión/representación de comprobantes tributarios y el envío a SUNAT siguen pendientes.


## Devolución de cobros y resolución de productos

- `GestionDevoluciones` serializa catálogo → salones/mesas/cajas → turno actual → medios → pedido → cobro/saldos. Revalida actor activo, permiso `devoluciones.crear`, alcance de pedido y cajas original/actual. Todo reintegro nuevo, incluso digital/cero, necesita turno abierto. `SaldoDevolucion` reconcilia el historial y calcula cantidades/netos/pagos disponibles; no utiliza precios actuales ni monto entregado/vuelto. Se conservan medio y condición de efectivo originales. No se admiten devoluciones con asignaciones históricas sin conciliar.
- El egreso de efectivo requiere permiso de egreso y saldo disponible; una devolución digital no cambia efectivo pero sí el resumen y la huella de cierre. Devolucion, detalles, pagos, movimiento y auditoría se confirman atómicamente. El UUID queda vinculado a cobro, actor y turno; repetirlo devuelve su registro incluso después del cierre, tras reautorizar el alcance.
- El cobro original no cambia de estado, precio, cantidades cobradas ni pagos. Se devuelve el neto de cada CobroDetalle proporcional a la cantidad restante, agotando exactamente sus céntimos. El envío restante se devuelve completo una sola vez; no se transforma en una deuda nueva. Cantidades de importe cero requieren selección real, sin pago ficticio.
- `ResolverProductosDevueltos` toma el mismo bloqueo inicial; bloquea pedido y devolución y revalida el avance, stock y cantidades sin resolver. Mantener entrega, cancelar pendiente o retornar son decisiones independientes del reintegro. Cantidad resuelta + nueva no supera lo devuelto; cancelación pagada + retorno físico no supera lo cobrado. Los combos verifican factores y cantidades de todas sus hojas.
- Cancelar pendiente requiere permiso de anulación y, si había avance, merma. `CalculoAnulacion` comparte el cálculo de avance con anulaciones sin cobro. Actualiza reserva, existencia, cocina y contador cancelado pagado en una transacción, sin volver a cobrar ni devolver dinero.
- Retorno físico asigna las cantidades a movimientos de entrega originales, por orden de id y descontando asignaciones previas. Una salida sin traza/costo conciliado bloquea reingreso. Cada hoja registra destino y costo. Solo reventa activa/unidad activa con permiso de ajuste recupera stock y costo promedio; preparados no recuperan disponibilidad. Merma de lo entregado requiere su permiso y no vuelve a consumir stock. No se reabren atenciones cerradas ni se modifican ocupaciones actuales.
- Las revisiones Livewire guardan identificadores, UUID, huella y payload bajo `Locked`. Se usan los valores revisados aunque cambien los inputs antes de aceptar SweetAlert2; si cambia la base, hay que revisar otra vez. Costos de salida/lotes no se guardan en propiedades públicas ni se exponen por permisos de devolución.
- La devolución es interna. No ejecuta transferencias externas ni altera comprobantes/SUNAT; la impresión operativa se conecta sin alterar estos registros y boletas/facturas se emiten por separado con Greenter; notas de crédito y bajas siguen pendientes.


## Impresión operativa

- `ImpresionOperativa` usa el bloqueo compartido de configuración: destinos → impresoras → cajas, y después bloquea el pedido fuente. Cocina, POS, cobros y devoluciones mantienen su pedido bloqueado; las vistas previas y confirmaciones obtienen un corte consistente. La configuración no cambia una impresora mientras su trabajo/prueba está en curso.
- La identidad del documento se deriva de fuentes permitidas: EnvioCocina, Pedido, Cobro, AnulacionPedido o ResolucionDevolucion de cancelación pendiente. No se reciben host, puerto, importes ni contenido arbitrario del navegador. Valida usuario activo, permiso específico y alcance de pedido; tickets también exigen cobros y alcance de caja original/salida.
- Comandas se separan por envío/lote/destino y usan las hojas históricas del combo, notas y avance. Los avisos separan cancelaciones por destino sin exponer dinero/costos ni repetir la acción. La precuenta resta cantidades ya cobradas y usa beneficio restante exacto; el envío no se cobra dos veces y los reintegros no recrean deuda. Los tickets usan CobroDetalle y Pago guardados, no precios ni nombres actuales del catálogo de ventas.
- La clave única de documento limita cada comanda/aviso/ticket a un original. Para precuentas se añade un hash de su contenido pendiente. Solicitudes con otra UUID devuelven ese original, sin otro envío. La clave de petición se vincula al usuario, solicitud e identidad de copia; UUID ajenas no se reutilizan.
- Vista previa no envía. Livewire mantiene tipo, ids, texto, huella y valores revisados bajo `Locked`. La confirmación recalcula fuente y conexión y rechaza cambios posteriores. Primero crea trabajo en curso y auditoría en la transacción; solo quien creó esa solicitud ejecuta TCP al salir. Si la transacción se reintenta, debe adquirir de nuevo la propiedad del envío. Nunca se envía desde un callback transaccional que pueda repetirse.
- No hay reintentos automáticos. Error de generación/conexión se distingue de envío parcial o resultado desconocido. Solicitudes en curso de más de un minuto se marcan inciertas en la siguiente operación de impresión/configuración o al actualizar estado, sin reenviar. La finalización solo actualiza un trabajo aún en curso, evitando sobrescribir un resultado ya marcado como incierto.
- Toda nueva copia exige permiso independiente, motivo y revisión. Preserva contenido, se enlaza al original y se identifica en papel. La nueva conexión puede ser la asignación actual corregida; la anterior permanece guardada. El texto no contiene comandos derivados del usuario: se limpia/translitera antes del formateo a 32/48 columnas y se envuelve en ESC/POS generado por la librería. No depende de impresión del navegador ni fuentes externas.
- La impresión es manual y explícita desde pedido/cobro; confirmar pedido o cobrar no dispara envíos físicos. No modifica caja, inventario, entrega, pago o SUNAT. El estado enviado confirma bytes, no salida de papel. El servidor necesita conectividad a las impresoras del local; no se implementa un puente de hosting.

## Emisión y envío tributario

- Emisión: Empresa → Configuración fiscal → Serie → Pedido → Cobro; releer usuario activo, permiso y alcance. Serie/correlativo, cobro/entorno y UUID tienen restricciones únicas. La revisión incluye una versión de Empresa/Configuración, comprobada bajo esos bloqueos. Firmar y escribir el XML privado no llama a servicios externos.
- Envío: Empresa → Configuración → Comprobante. La asignación de secuencia RC se serializa mediante Empresa y la clave fecha/entorno. Registrar intento `en_proceso`, nombre/ruta/hash y responsable; confirmar la transacción; solo después llamar a SUNAT. No usar `send()` para reconstruir el XML de un documento ya emitido.
- Registro de resultado/recuperación: Comprobante → Intento. Verificar constancia antes de aceptar. La consulta nunca cambia el XML o correlativo; un ticket aún no significa aceptación. La red no se ejecuta dentro de un callback de transacción reintentable. No existe reintento automático tras resultados inciertos.
- Configuración no puede cambiar durante envío o consulta en proceso. Un intento interrumpido se recupera por acceso autorizado al comprobante tras dos minutos, conservando incertidumbre y sin nuevo SOAP. Los endpoints no son configurables por el cliente.
- Acceso: `comprobantes.ver` más alcance de pedido y turno de caja para consulta/archivos; `comprobantes.emitir` para generar/enviar; `configuracion.sistema` para datos fiscales y series. Consulta de tickets/CDR no exige reabrir caja ni permite consultar ventas ajenas. Los secretos no forman parte de propiedades públicas Livewire ni de los registros de actividad.
- Impresión fiscal sigue Áreas → Impresoras → Cajas → Pedido del subsistema existente. La fuente Comprobante es histórica, requiere acceso fiscal y usa una clave de original diferente del ticket interno de su Cobro. Su QR se añade a los bytes ESC/POS.
- Las devoluciones financieras no alteran el documento tributario. Se impide emitir por un cobro previamente devuelto; notas de crédito/bajas y rectificación de rechazados siguen siendo una etapa independiente.
