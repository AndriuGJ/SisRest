# Contratos para implementar los módulos

Esta entrega prepara persistencia y acceso. Los futuros servicios de negocio deben cumplir estas reglas.

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
- Se propone consumir la reserva y registrar salida al entregar. La transición definitiva se cerrará con Cocina/POS; la estructura admite consumo y liberación parciales.
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
- Disponible se calcula con BCMath desde saldos menos reservas, y en combos toma el mínimo entero de sus componentes. Esta consulta no sustituye la validación y reserva transaccional del futuro POS.
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
- La configuración bloquea los salones en orden por ID y después la mesa afectada. **El futuro servicio POS debe adquirir primero el bloqueo del salón y luego el de la mesa**, comprobar ambos estados y crear la ocupación dentro de la misma transacción. De esta forma no podrá abrir una atención mientras otra transacción desactiva o mueve la mesa.
- Las operaciones que afecten varias mesas/salones deben mantener ese mismo orden de bloqueo. Las pruebas actuales comprueban que una nueva ocupación/pedido entre abrir y guardar el formulario impide la modificación; las carreras reales con el POS deberán probarse al implementar ese servicio.

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

- `GestionCompras` y `GestionProveedores` reautorizan al usuario activo dentro de la transacción. Orden compartido: áreas → artículos → productos → proveedores (todos por ID), después compra → turno de caja → medio de pago. No introducir bloqueos inversos al conectar cierre de turnos u otros egresos.
- Solo insumos/reventa activos, unidades activas y presentaciones propias. El factor y la cantidad base se conservan; se rechaza pérdida de precisión en la conversión. Sin duplicados por artículo, máximo 50 líneas.
- Precio final por presentación con seis decimales, total de línea redondeado a dos menos descuento. El impuesto incluido se desglosa, no se suma otra vez ni se deduce del valor operativo ingresado. No se supone una tasa ni un tratamiento de crédito fiscal.
- Borrador sin movimientos. UUID para alta idempotente; versión de cabecera/detalles para edición/confirmación. Documento único por proveedor/tipo/serie/número.
- Confirmación atómica de ingreso y costo promedio ponderado, con reservas intactas y una clave de movimiento por compra/artículo. Recepción al contado incluye pago completo dentro de la misma transacción; crédito permite pagos posteriores.
- Pago con permiso específico, saldo comprobado bajo bloqueo de compra y UUID único. Cambiar el importe editable después de revisar no altera el pago confirmado. No se paga un borrador ni se excede la deuda; no se vuelve a recibir inventario al pagar.
- Caja: turno abierto autorizado, efectivo y fondo suficiente; egreso único enlazado al pago. Digital de empresa y dinero externo del dueño no afectan efectivo físico ni se vinculan a un turno. No se inventa una apertura si no existen turnos.
- Proveedor inactivo bloquea nuevas compras/confirmaciones pero permite cancelar deuda. Solo se anulan borradores con motivo; recepción confirmada/pagos no se editan ni se revierten automáticamente.
- Instantáneas de proveedor/documento, descripción/unidad/presentación/factor/precio y nombre del medio de pago. Las compras tienen su propio permiso de consulta de importes; no exponen el costo promedio de inventario en propiedades públicas Livewire.
- CI MySQL añade recepciones simultáneas con valorización, repetición de recepción, pagos compitiendo por la deuda y pago repetido concurrente. Entorno/base desechable obligatorios y limpieza limitada a sus fixtures.

## Fotografías de productos

La foto guardada se sirve por una ruta autenticada con `productos.ver`, relativa al origen actual y sin depender de `APP_URL` ni del enlace `public/storage`. El controlador solo acepta nombres simples bajo `productos/`, extensiones JPG/PNG/WebP y MIME real de imagen; rutas ajenas, archivos ausentes o contenido inválido devuelven 404. La URL versiona el nombre del archivo para actualizar la caché al reemplazarlo. No es un servidor genérico de archivos ni recibe rutas del navegador.

## Siguiente alcance

Usuarios y permisos, Salones y Mesas, Destinos de preparación, Impresoras, Insumos, Productos para la venta, Recetas, Preparación diaria, Compras y Proveedores ya tienen pantallas, servicios transaccionales y autorización de acciones. Siguen pendientes atención POS/Cocina, cajas/cobros, movimientos de ajuste/merma, gráficos, reportes, impresión operativa con POS80 y facturación de pruebas. Esta entrega permite enviar tickets de prueba por TCP. La impresión operativa de comandas, precuentas y comprobantes y el envío a SUNAT siguen pendientes.
