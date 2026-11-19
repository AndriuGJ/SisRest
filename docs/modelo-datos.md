# Modelo de datos de SisRest

Las migraciones son la fuente de verdad del esquema. Esta guía explica su organización y las relaciones principales.

## Convenciones

- Tablas de dominio en español y modelo User de Laravel conservado.
- Dinero DECIMAL(14,2); cantidades DECIMAL(16,4); costos unitarios DECIMAL(18,6).
- Estados mediante enums de PHP y columnas enum de base de datos.
- Claves foráneas con eliminación restringida para conservar referencias históricas.
- Productos y configuraciones se desactivan en lugar de borrar su historia.
- Campos de instantánea conservan descripción, precio y datos fiscales históricos.

## Configuración y catálogos

| Tabla | Modelo | Relaciones directas |
| --- | --- | --- |
| tipos_documento_identidad | TipoDocumentoIdentidad | Catálogo o configuración |
| tipos_comprobante | TipoComprobante | Catálogo o configuración |
| medios_pago | MedioPago | Catálogo o configuración |
| unidades_medida | UnidadMedida | Catálogo o configuración |
| categorias_producto | CategoriaProducto | Catálogo o configuración |
| motivos_beneficio | MotivoBeneficio | Catálogo o configuración |
| empresa | Empresa | Catálogo o configuración |
| configuraciones_facturacion | ConfiguracionFacturacion | empresa → Empresa |
| salones | Salon | Catálogo o configuración |
| areas_preparacion | AreaPreparacion | Catálogo o configuración |
| impresoras | Impresora | areaPreparacion → AreaPreparacion |
| cajas | Caja | impresora → Impresora |
| mesas | Mesa | salon → Salon |
| clientes | Cliente | tipoDocumento → TipoDocumentoIdentidad |
| proveedores | Proveedor | tipoDocumento → TipoDocumentoIdentidad |

## Inventario, producción y compras

| Tabla | Modelo | Relaciones directas |
| --- | --- | --- |
| articulos | Articulo | unidadMedida → UnidadMedida, presentaciones → PresentacionArticulo |
| presentaciones_articulo | PresentacionArticulo | articulo → Articulo |
| productos | Producto | articulo → Articulo, categoria → CategoriaProducto, areaPreparacion → AreaPreparacion, receta → Receta, componentes → ComboComponente |
| combo_componentes | ComboComponente | combo → Producto, producto → Producto |
| recetas | Receta | producto → Producto, detalles → RecetaDetalle |
| receta_detalles | RecetaDetalle | receta → Receta, articulo → Articulo |
| preparaciones | Preparacion | producto → Producto, receta → Receta, usuario → User, detalles → PreparacionDetalle |
| preparacion_detalles | PreparacionDetalle | preparacion → Preparacion, articulo → Articulo |
| compras | Compra | proveedor → Proveedor, usuario → User, detalles → CompraDetalle, pagos → PagoCompra |
| compra_detalles | CompraDetalle | compra → Compra, articulo → Articulo, presentacion → PresentacionArticulo |
| turnos_caja | TurnoCaja | caja → Caja, usuario → User, movimientos → MovimientoCaja, arqueos → ArqueoCaja |
| pagos_compra | PagoCompra | compra → Compra, medioPago → MedioPago, turnoCaja → TurnoCaja, usuario → User |

## Pedidos y cocina

| Tabla | Modelo | Relaciones directas |
| --- | --- | --- |
| confirmaciones_pedido | ConfirmacionPedido | detalles → PedidoDetalle; pedido, turno y usuario por claves foráneas |
| anulaciones_pedido | AnulacionPedido | detalles → AnulacionPedidoDetalle; pedido, raíz y usuario por claves foráneas |
| anulacion_pedido_detalles | AnulacionPedidoDetalle | artículo y hoja anulada; cantidades liberada/merma y avance anterior/posterior |
| beneficios_pedido | BeneficioPedido | pedido, raíz y usuario; historial de autorización antes/después |
| pedidos | Pedido | mesa → Mesa, mozo → User, cliente → Cliente, detalles → PedidoDetalle, cobros → Cobro, delivery → EntregaDelivery |
| ocupaciones_mesa | OcupacionMesa | mesa → Mesa, pedido → Pedido |
| entregas_delivery | EntregaDelivery | pedido → Pedido |
| pedido_detalles | PedidoDetalle | pedido → Pedido, producto → Producto, padre → PedidoDetalle, hijos → PedidoDetalle, reservas → ReservaInventario |
| reservas_inventario | ReservaInventario | detallePedido → PedidoDetalle, articulo → Articulo |
| envios_cocina | EnvioCocina | pedido → Pedido, areaPreparacion → AreaPreparacion, detalles → EnvioCocinaDetalle |
| envio_cocina_detalles | EnvioCocinaDetalle | envio → EnvioCocina, detallePedido → PedidoDetalle |

## Cobros, caja y devoluciones

| Tabla | Modelo | Relaciones directas |
| --- | --- | --- |
| cobros | Cobro | pedido → Pedido, cliente → Cliente, turnoCaja → TurnoCaja, detalles → CobroDetalle, pagos → Pago, comprobante → Comprobante |
| cobro_detalles | CobroDetalle | cobro → Cobro, detallePedido → PedidoDetalle |
| pagos | Pago | cobro → Cobro, medioPago → MedioPago |
| devoluciones | Devolucion | cobro → Cobro, turnoCaja → TurnoCaja, detalles → DevolucionDetalle, pagos → DevolucionPago |
| devolucion_detalles | DevolucionDetalle | devolucion → Devolucion, detalleCobro → CobroDetalle, resoluciones → ResolucionDevolucion |
| resoluciones_devolucion | ResolucionDevolucion | detalleDevolucion → DevolucionDetalle, detalles → ResolucionDevolucionDetalle |
| resolucion_devolucion_detalles | ResolucionDevolucionDetalle | FKs: resolución, línea del pedido, artículo, salida de entrega (opcional) |
| devolucion_pagos | DevolucionPago | devolucion → Devolucion, pagoOriginal → Pago, medioPago → MedioPago |
| movimientos_caja | MovimientoCaja | turnoCaja → TurnoCaja, usuario → User |
| arqueos_caja | ArqueoCaja | turnoCaja → TurnoCaja, usuario → User |
| movimientos_inventario | MovimientoInventario | articulo → Articulo, usuario → User |

## Comprobantes e impresión

| Tabla | Modelo | Relaciones directas |
| --- | --- | --- |
| series_comprobante | SerieComprobante | tipoComprobante → TipoComprobante |
| comprobantes | Comprobante | serie → SerieComprobante, cobro → Cobro, referencia → Comprobante, detalles → ComprobanteDetalle, envios → ComprobanteEnvio |
| comprobante_detalles | ComprobanteDetalle | comprobante → Comprobante, detalleCobro → CobroDetalle, resoluciones → ResolucionDevolucion |
| comprobante_envios | ComprobanteEnvio | comprobante → Comprobante |
| trabajos_impresion | TrabajoImpresion | impresora → Impresora, reimpresionDe → TrabajoImpresion |
| pruebas_impresion | PruebaImpresion | impresora → Impresora; usuario_id identifica al responsable |

## Restricciones principales

- Solo un turno abierto por caja mediante columna generada e índice único; los turnos cerrados no chocan entre sí.
- Solo una ocupación activa por mesa y un pedido por ocupación.
- Serie y correlativo únicos por comprobante.
- Claves de idempotencia únicas en pagos, devoluciones, movimientos y trabajos.
- En MySQL: reservas entre cero y stock físico; cantidades y costos válidos; contadores de preparación coherentes; descuentos sin acumulación.
- Las restricciones entre varias tablas o sumas de detalles requieren los servicios transaccionales descritos en contratos-operativos.md.

## Inventario y producción

Un artículo es una identidad de inventario: insumo, preparado o reventa. Un producto es una oferta de venta. El formulario crea su artículo de inventario automáticamente para preparados y reventa; los insumos se administran por separado. Los catálogos no modifican saldos ni generan movimientos. Cada producto que no es combo enlaza su artículo. El combo enlaza productos y utiliza sus existencias.

Una receta indica consumos estándar. Una preparación conserva cantidades estándar y reales utilizadas, nombres/unidades, rendimiento y huella de la receta, responsables y costos de esa operación. Su UUID evita duplicar altas; confirmar consume ingredientes e ingresa producción con movimientos únicos y actualiza el costo promedio ponderado de la salida. Los movimientos registran entradas y salidas; las reservas registran compromisos del pedido sin descontar otra vez ingredientes.

## Pedido, cobro y documento

Un pedido contiene consumos. Cada cobro asigna productos y cantidades de ese pedido a un grupo pagador y a una caja. Los pagos desglosan los medios utilizados. El comprobante conserva su contenido y estado tributario independientemente del pago.

Los adicionales y componentes de combos se guardan como líneas hijas del pedido. Los componentes no facturan un segundo precio. Los envíos a cocina conservan el destino y las cantidades de cada lote.

## Tablas de infraestructura

La plantilla mantiene users, sesiones, recuperación, cache, colas, passkeys y doble factor. Spatie aporta roles, permissions, sus pivotes y activity_log. Las migraciones publicadas están incluidas y se pueden revertir.

## Límites de esta entrega

Usuarios y permisos, Salones y Mesas, Destinos de preparación, Impresoras, Insumos, Productos para la venta, Recetas, Preparación diaria, Compras, Proveedores, Cajas, Arqueos, Clientes, POS, Cocina/Barra, Cobros, Anulaciones/Beneficios y Devoluciones disponen de pantallas Livewire y servicios. Las pruebas ESC/POS se registran por UUID con una instantánea de conexión y estados que distinguen envío, error y resultado incierto. Los ajustes ajenos a anulaciones/devoluciones, los comprobantes tributarios y otros módulos siguen pendientes. La impresión operativa ya usa las fuentes de pedidos y cobros. El esquema no sustituye la autorización en servidor, el bloqueo de filas, la validación de totales, el control de operaciones ya confirmadas ni los procedimientos tributarios.

Las compras conservan UUID de registro, datos de proveedor/documento, responsables de confirmación/anulación y motivo del borrador anulado. El detalle conserva unidad y nombre de presentación además de su factor. Los pagos conservan el nombre del medio, origen y turno solo cuando el dinero sale físicamente de caja. No se borran recepciones ni pagos para corregir saldos.

Los turnos conservan UUID de apertura, caja/responsable de ese momento y usuario que abrió. El fondo no genera otro ingreso. Los arqueos incorporan UUID y resumen de importes por tipo/medio: un cierre conserva la instantánea y hay un único arqueo final por turno. La diferencia registra lo contado, sin ajustar movimientos. El bloqueo del turno coordina los egresos de proveedores con el cierre.

Clientes conserva un UUID de alta y el usuario creador desde la migración 000063, sin rellenar autorías antiguas. El documento es único por tipo/número y los vínculos de pedidos/cobros se conservan al desactivar. La actualización de identidad utilizada se protege en el servicio; los documentos fiscales deberán conservar además su instantánea del receptor.

Desde la migración 000064, pedidos conserva UUID de apertura, turno de origen, nombres de salón/mesa/mozo y motivo/autor de anulación. Los registros anteriores mantienen esos campos opcionales. Una ocupación se crea al abrir mesa; descartar conserva el pedido anulado y libera su ocupación. Los detalles en borrador conservan precio, nombre y destino; los componentes de combo guardan además su factor para recalcular cantidad sin cambiar la receta histórica. Guardar no genera reservas, envíos, cobros ni movimientos; las líneas confirmadas son de solo lectura. El total de pantalla incluye envío en delivery y todavía es referencial.

Desde la migración 000065, `confirmaciones_pedido` conserva UUID, pedido, turno, usuario, número de lote y fecha. Detalles y envíos se vinculan opcionalmente al lote; los envíos guardan además el nombre del destino. Los históricos no reciben lotes ni reservas ficticias. Cada hoja confirmada reserva su artículo y un combo usa sus componentes históricos. Cocina conserva acumulados iniciada/lista/entregada por línea del envío; productos directos no generan envío. La entrega consume la reserva y registra salida, sin cobro. El padre combo refleja unidades completas entregadas y no duplica inventario. Estado de atención, pago y reparto son independientes.

Desde la migración 000066, cobros conserva alias del grupo y datos históricos del cliente/caja/cajero; pagos conserva nombre del medio y si afectaba efectivo. La identidad y los nombres actuales no reescriben el registro. Cada CobroDetalle liquida una raíz y cantidad del pedido; la suma confirmada coincide con cantidad_cobrada. Los combos propagan cantidades a sus componentes sin otra línea cobrada. Cargo de envío se liquida una sola vez entre los cobros confirmados. Pago guarda monto aplicado, recibido y vuelto; únicamente el aplicado en efectivo genera MovimientoCaja enlazado. UUID del cobro y claves de pago/movimiento evitan duplicados. Cerrar la atención exige pago y entrega completos y libera OcupacionMesa, sin borrar sus datos históricos. Los comprobantes tributarios siguen siendo una operación independiente.

Desde la migración 000067, anulaciones conserva UUID, raíz, cantidad, responsable/nombre, motivo y fecha; sus detalles guardan artículo, cantidades liberadas/merma y avance de Cocina antes/después. Una anulación parcial conserva la línea original y acumula cantidad_anulada. Merma consume reserva/stock con movimiento; liberación no altera existencia actual. Beneficios conserva autorizaciones antes/después; PedidoDetalle mantiene presupuesto y motivo/autor históricos. CobroDetalle separa descuento_producto y descuento_general, total descuento y cortesía con instantánea de autorización. Los cobros previos permanecen intactos y el beneficio pendiente se reparte entre cantidades aún no liquidadas, ajustándose al anular solo la parte no cobrada.


Desde la migración 000068, Devolucion conserva cargo_envio y nombres históricos de responsable/caja; DevolucionPago conserva nombre del medio y afecta_efectivo. El servicio exige pago_original_id de este cobro y limita el reintegro al aplicado pendiente, conservando el original. Total cero permite resolver cantidades de cortesía sin pago ficticio. DevolucionDetalle acumula cantidad_resuelta; resoluciones_devolucion conserva UUID, cantidad, acción, responsable, motivo y fecha. Sus detalles guardan destinos por hoja/componente, cantidades, costo de entrega y movimiento_entrega_id para asignaciones de retornos. Las cancelaciones pendientes guardan avance de Cocina antes/después. PedidoDetalle agrega cantidad_cancelada_pagada y cantidad_devuelta sin reducir los contadores históricos cobrados/entregados. La cantidad operativa vigente resta la anulación sin cobro y la cancelación pagada; un retorno ya entregado no crea una nueva entrega pendiente.

Solo el reingreso de reventa registra stock positivo, valorado a sus salidas históricas no retornadas. Merma de un producto ya entregado conserva trazabilidad sin segundo descuento. Una cancelación pagada pendiente libera reserva no iniciada o consume reserva/stock como merma si ya estaba iniciado. El reintegro en efectivo genera egreso en el turno actual enlazado a DevolucionPago. ResumenTurno separa bruto, devuelto y neto, con instantáneas inmutables al cierre. Comprobantes y estado SUNAT continúan independientes.


Desde la migración 000069, trabajos_impresion añade pedido_id, solicitud tipada de origen, configuración de conexión, nombre del usuario, motivo de reimpresión y clave_documento única para originales. El enum tipo incorpora ticket, reservado al cobro interno; comprobante se conserva para la futura integración tributaria. El contenido JSON versionado guarda cabecera, líneas, cantidades, importes y avisos del documento, sin tomar contenido del cliente. Documento sigue siendo polimórfico; las fuentes aceptadas son EnvioCocina (comanda), Pedido (precuenta), Cobro (ticket) y anulaciones/resoluciones de cancelación pendiente (aviso por destino). Los históricos anteriores quedan nulos en los nuevos metadatos, sin inventar conexiones o documentos.

Cada comanda/aviso/ticket tiene un original por identidad y destino. Las precuentas agregan hash de contenido para sus versiones. Reimpresiones no tienen clave_documento; enlazan el original mediante reimpresion_de_id y mantienen su contenido, conexión de salida actual, motivo y usuario/fecha propios. Clave_idempotencia limita cada petición. En curso se persiste antes de TCP; intentos queda en uno por trabajo. Fallos no borran documentos ni reintentan automáticamente. Al recuperar un proceso interrumpido se marca resultado_incierto, conservando lo que pudo enviarse. Las pruebas de impresora siguen en su tabla separada.
