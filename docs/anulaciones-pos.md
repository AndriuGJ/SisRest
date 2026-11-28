# Anulaciones protegidas del POS

Se reutilizan `configuracion_anulacion`, `motivos_anulacion`, sus modelos y la administración existente. Esta entrega no agrega migraciones ni requiere resembrar datos.

## Operaciones y autorización

- **Producto confirmado:** permiso `pedidos.anular_enviados`, alcance del pedido, motivo activo y clave válida. La cantidad máxima visible considera lo cobrado/entregado y los componentes históricos del combo. El servidor vuelve a validar todas las cantidades, reservas, cocina y mermas.
- **Pedido completo:** los mismos controles y `mesas.liberar` si corresponde. Rechaza cobros o entregas previas. Libera reservas no iniciadas, registra las mermas autorizadas y libera únicamente la ocupación de esa atención, en una transacción.
- **Borrador completo:** exige `pedidos.editar`, alcance del pedido, motivo activo, clave válida y `mesas.liberar` cuando corresponda. Rechaza líneas confirmadas y cualquier participación en reservas, cocina o cobros. No registra venta ni movimientos de inventario.
- Retirar una línea todavía no confirmada sigue formando parte de la edición del borrador y no requiere clave. No cierra la atención ni libera la mesa.

El permiso `inventario.registrar_merma` continúa siendo independiente. Conocer la clave no concede permisos ni permite devolver importes cobrados.

## Servicios

`GestionAnulaciones::anular`, `AnularPedido::anular` y `GestionPedidos::descartar` reciben, después de los argumentos previos, `motivoId` y `claveAutorizacion`. Omitirlos deniega la operación, incluso en una repetición. El argumento existente `$clave` de `GestionAnulaciones` sigue siendo un UUID de idempotencia, no la clave del restaurante.

`AnularPedido` delega en el coordinador protegido `GestionAnulaciones`. Este verifica la clave una vez por solicitud completa; las aplicaciones por línea son privadas. No existe un método público para aplicar un plan sin autorización.

Los intentos fallidos se cuentan antes de iniciar la transacción financiera/inventario, para que una reversión no borre el contador. El motivo se relee y bloquea dentro de la transacción. Cada confirmación compara también las huellas revisadas. Una repetición válida no duplica anulaciones, mermas ni movimientos y no libera una ocupación posterior de la misma mesa.

Las ventanas comparten los componentes `pos.motivos-anulacion` y `pos.confirmar-anulacion`. La clave se transmite como argumento sensible de la acción, no como propiedad pública de Livewire: el campo se vacía al confirmar/cancelar y no se incorpora a snapshots, sesiones, auditorías ni eventos. La auditoría conserva únicamente el motivo y los efectos de la operación. Los identificadores y los datos revisados se mantienen bloqueados.

## Comprobación manual

Usar la clave y motivos ya configurados en **Config. restaurante → Seguridad de anulaciones**. Probar en datos de ensayo; las anulaciones confirmadas son operaciones reales.

1. **Individual:** abrir una mesa, añadir y confirmar dos unidades. Elegir anular una, seleccionar motivo y revisar. Comprobar los límites de `−/+`. Introducir primero una clave incorrecta: la ventana debe mostrar error y no cambiar el pedido. Confirmar luego con la correcta: se anula una sola unidad, conservando la otra y la mesa ocupada.
2. **Completa:** preparar un pedido sin cobros ni entregas. Pulsar la anulación general, elegir motivo y revisar el resumen. Cancelar debe dejarlo intacto. Volver a revisar y confirmar con la clave correcta: el pedido queda anulado y la mesa libre. Si se inició preparación, revisar la merma y su permiso independiente. Si tiene pagos/entregas, debe rechazar la anulación completa.
3. **Borrador:** abrir otro pedido y guardar productos sin confirmarlos. En **Más opciones → Descartar borrador**, seleccionar motivo y revisar. Confirmar con clave: conserva el historial como anulado y libera la mesa sin variar stock ni dinero. En otro borrador, retirar solamente una línea debe seguir funcionando sin clave y mantener la atención abierta.

También conviene revisar un cambio de cantidades o avance desde otra sesión entre revisión y confirmación: se debe solicitar una nueva revisión. Cinco claves incorrectas bloquean temporalmente nuevos intentos por usuario durante quince minutos; no hacer esta comprobación con una cuenta necesaria para atender clientes en ese momento.
