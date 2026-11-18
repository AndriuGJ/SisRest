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

## Siguiente alcance

Usuarios y permisos, Salones y Mesas ya tienen pantallas, servicios transaccionales y autorización de acciones. Para los módulos operativos siguen pendientes servicios, validaciones, pantallas Livewire, gráficos, exportaciones, pruebas de concurrencia, impresión con POS80 y facturación de pruebas. Esta entrega no envía documentos a SUNAT ni trabajos a impresoras.
