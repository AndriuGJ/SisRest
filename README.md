# SisRest

Base de un sistema de restaurante para un local, con dos cajas, atención en mesas, venta para llevar y delivery.

## Estado

Incluye la base de datos, dependencias y los módulos de **Usuarios y permisos** y **Salones y Mesas** implementados con Livewire y Spatie Laravel Permission. Conserva la plantilla Laravel + Livewire + Flux. Los módulos operativos del restaurante se implementarán en las siguientes entregas.

Las tablas soportan preparación real, reservas de existencias, combos por componentes, adicionales, cuentas divididas, pagos combinados, devoluciones y estados tributarios independientes. La presencia de estas tablas no significa que ya existan los servicios de confirmación de compras, producción, cobros o emisión fiscal.

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

No se necesitan migraciones nuevas para esta entrega: utiliza las tablas ya existentes.

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
