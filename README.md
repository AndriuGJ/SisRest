# SisRest

Base de un sistema de restaurante para un local, con dos cajas, atención en mesas, venta para llevar y delivery.

## Estado

Esta entrega prepara migraciones, modelos, relaciones, enums, seeders, autenticación por usuario y dependencias. Conserva la plantilla Laravel + Livewire + Flux. Los módulos operativos y sus pantallas se implementarán en las siguientes entregas.

Las tablas soportan preparación real, reservas de existencias, combos por componentes, adicionales, cuentas divididas, pagos combinados, devoluciones y estados tributarios independientes. La presencia de estas tablas no significa que ya existan los servicios de confirmación de compras, producción, cobros o emisión fiscal.

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
- Recuperación por correo para cuentas con correo y envío configurado. La gestión de contraseñas desde Usuarios corresponde a la siguiente implementación del módulo.

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
