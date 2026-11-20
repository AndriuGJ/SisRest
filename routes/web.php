<?php

use App\Http\Controllers\Configuracion\LogoEmpresaController;
use App\Http\Controllers\Facturacion\ArchivoComprobanteController;
use App\Http\Controllers\Inventario\ImagenProductoController;
use App\Livewire\Administracion\Roles;
use App\Livewire\Administracion\RolFormulario;
use App\Livewire\Administracion\Usuarios;
use App\Livewire\Cajas\Cajas;
use App\Livewire\Cajas\TurnoDetalle;
use App\Livewire\Clientes\ClienteFormulario;
use App\Livewire\Clientes\Clientes;
use App\Livewire\Cobros\CobroDetallePantalla;
use App\Livewire\Cobros\CobroFormulario;
use App\Livewire\Cobros\HistorialCobros;
use App\Livewire\Cocina\Cocina;
use App\Livewire\Compras\CompraDetalle;
use App\Livewire\Compras\CompraFormulario;
use App\Livewire\Compras\Compras;
use App\Livewire\Compras\Proveedores;
use App\Livewire\Compras\ProveedorFormulario;
use App\Livewire\Configuracion\AparienciaPantalla;
use App\Livewire\Configuracion\Destinos;
use App\Livewire\Configuracion\EmpresaPantalla;
use App\Livewire\Configuracion\Impresoras;
use App\Livewire\Configuracion\Mesas;
use App\Livewire\Configuracion\Salones;
use App\Livewire\Devoluciones\DevolucionFormulario;
use App\Livewire\Devoluciones\DevolucionPantalla;
use App\Livewire\Facturacion\ComprobantePantalla;
use App\Livewire\Facturacion\Comprobantes;
use App\Livewire\Facturacion\ConfiguracionFiscal;
use App\Livewire\Facturacion\EmisionCobro;
use App\Livewire\Impresion\DocumentosImpresion;
use App\Livewire\Inventario\InsumoFormulario;
use App\Livewire\Inventario\Insumos;
use App\Livewire\Inventario\PreparacionDetalle;
use App\Livewire\Inventario\Preparaciones;
use App\Livewire\Inventario\PreparacionFormulario;
use App\Livewire\Inventario\ProductoFormulario;
use App\Livewire\Inventario\Productos;
use App\Livewire\Inventario\RecetaFormulario;
use App\Livewire\Pos\PedidoPantalla;
use App\Livewire\Pos\PedidosAtencion;
use App\Livewire\Pos\Pos;
use Illuminate\Support\Facades\Route;

Route::get('marca/logo/{archivo}', LogoEmpresaController::class)->name('marca.logo');

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('permission:inventario.ver')->prefix('inventario/insumos')->name('insumos.')->group(function () {
        Route::get('/', Insumos::class)->name('index');
        Route::get('nuevo', InsumoFormulario::class)->middleware('permission:insumos.crear')->name('crear');
        Route::get('{insumo}/editar', InsumoFormulario::class)->middleware('permission:insumos.editar')->name('editar');
    });
    Route::get('inventario/productos/{producto}/imagen', ImagenProductoController::class)->middleware('permission:productos.ver|pos.ver')->name('productos.imagen');
    Route::middleware('permission:productos.ver')->prefix('inventario/productos')->name('productos.')->group(function () {
        Route::get('/', Productos::class)->name('index');
        Route::get('nuevo', ProductoFormulario::class)->middleware('permission:productos.crear')->name('crear');
        Route::get('{producto}/receta', RecetaFormulario::class)->middleware('permission:recetas.gestionar')->name('receta');
        Route::get('{producto}/editar', ProductoFormulario::class)->middleware('permission:productos.editar')->name('editar');
    });

    Route::middleware('permission:preparaciones.ver')->prefix('inventario/preparaciones')->name('preparaciones.')->group(function () {
        Route::get('/', Preparaciones::class)->name('index');
        Route::get('nueva', PreparacionFormulario::class)->middleware('permission:preparaciones.crear')->name('crear');
        Route::get('{preparacion}/editar', PreparacionFormulario::class)->middleware('permission:preparaciones.crear')->name('editar');
        Route::get('{preparacion}', PreparacionDetalle::class)->name('ver');
    });

    Route::middleware('permission:proveedores.gestionar')->prefix('compras/proveedores')->name('proveedores.')->group(function () {
        Route::get('/', Proveedores::class)->name('index');
        Route::get('nuevo', ProveedorFormulario::class)->name('crear');
        Route::get('{proveedor}/editar', ProveedorFormulario::class)->name('editar');
    });
    Route::middleware('permission:compras.ver')->prefix('compras')->name('compras.')->group(function () {
        Route::get('/', Compras::class)->name('index');
        Route::get('nueva', CompraFormulario::class)->middleware('permission:compras.crear')->name('crear');
        Route::get('{compra}/editar', CompraFormulario::class)->middleware('permission:compras.crear')->name('editar');
        Route::get('{compra}', CompraDetalle::class)->name('ver');
    });

    Route::middleware('permission:cajas.ver_propias|cajas.ver_todas')->prefix('cajas')->name('cajas.')->group(function () {
        Route::get('/', Cajas::class)->name('index');
        Route::get('turnos/{turno}', TurnoDetalle::class)->name('turno');
    });

    Route::middleware('permission:clientes.ver')->prefix('clientes')->name('clientes.')->group(function () {
        Route::get('/', Clientes::class)->name('index');
        Route::get('nuevo', ClienteFormulario::class)->middleware('permission:clientes.crear')->name('crear');
        Route::get('{cliente}/editar', ClienteFormulario::class)->middleware('permission:clientes.editar')->name('editar');
    });

    Route::get('cocina', Cocina::class)->middleware('permission:cocina.ver')->name('cocina.index');

    Route::middleware('permission:pos.ver')->prefix('ventas')->name('ventas.')->group(function () {
        Route::get('cobros', HistorialCobros::class)->middleware('permission:cobros.crear|devoluciones.crear')->name('cobros');
        Route::get('cobros/{cobro}', CobroDetallePantalla::class)->middleware('permission:cobros.crear')->name('cobro');
    });

    Route::middleware('permission:pos.ver')->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', Pos::class)->name('index');
        Route::get('atenciones', PedidosAtencion::class)->name('atenciones');
        Route::get('pedidos/{pedido}', PedidoPantalla::class)->name('pedido');
        Route::get('pedidos/{pedido}/impresion', DocumentosImpresion::class)->middleware('permission:impresion.comandas|impresion.precuentas')->name('imprimir-pedido');
        Route::get('cobros/{cobro}/impresion', DocumentosImpresion::class)->middleware('permission:impresion.tickets')->name('imprimir-cobro');
        Route::get('pedidos/{pedido}/cobrar', CobroFormulario::class)->middleware('permission:cobros.crear')->name('cobrar');
        Route::get('cobros', HistorialCobros::class)->middleware('permission:cobros.crear|devoluciones.crear')->name('cobros');
        Route::get('cobros/{cobro}/devolver', DevolucionFormulario::class)->middleware('permission:devoluciones.crear')->name('devolver');
        Route::get('devoluciones/{devolucion}', DevolucionPantalla::class)->middleware('permission:devoluciones.crear')->name('devolucion');
        Route::get('cobros/{cobro}', CobroDetallePantalla::class)->middleware('permission:cobros.crear')->name('cobro');
    });

    Route::get('configuracion/sistema/empresa', EmpresaPantalla::class)->middleware('permission:configuracion.sistema')->name('configuracion.empresa');
    Route::get('configuracion/sistema/tipografia', AparienciaPantalla::class)->defaults('seccion', 'tipografia')->middleware('permission:configuracion.sistema')->name('configuracion.tipografia');
    Route::get('configuracion/sistema/colores', AparienciaPantalla::class)->defaults('seccion', 'colores')->middleware('permission:configuracion.sistema')->name('configuracion.colores');
    Route::get('configuracion/sistema/facturacion', ConfiguracionFiscal::class)->middleware('permission:configuracion.sistema')->name('configuracion.facturacion');
    Route::middleware('permission:comprobantes.ver')->prefix('comprobantes')->name('comprobantes.')->group(function () {
        Route::get('/', Comprobantes::class)->name('index');
        Route::get('cobros/{cobro}/emitir', EmisionCobro::class)->middleware('permission:comprobantes.emitir')->name('emitir');
        Route::get('{comprobante}/archivo/{archivo}', ArchivoComprobanteController::class)->whereIn('archivo', ['xml', 'pdf', 'cdr', 'resumen'])->name('archivo');
        Route::get('{comprobante}/impresion', DocumentosImpresion::class)->middleware('permission:impresion.tickets')->name('imprimir');
        Route::get('{comprobante}', ComprobantePantalla::class)->name('ver');
    });

    Route::get('usuarios', Usuarios::class)
        ->middleware('permission:usuarios.gestionar')->name('usuarios.index');
    Route::middleware('permission:configuracion.restaurante')->prefix('configuracion/restaurante')->name('configuracion.')->group(function () {
        Route::get('salones', Salones::class)->name('salones');
        Route::get('mesas', Mesas::class)->name('mesas');
        Route::get('destinos', Destinos::class)->name('destinos');
    });

    Route::get('configuracion/restaurante/impresoras', Impresoras::class)
        ->middleware('permission:impresoras.gestionar')->name('configuracion.impresoras');

    Route::middleware('permission:roles.gestionar')->prefix('roles')->name('roles.')->group(function () {
        Route::get('/', Roles::class)->name('index');
        Route::get('nuevo', RolFormulario::class)->name('crear');
        Route::get('{rol}/editar', RolFormulario::class)->name('editar');
    });
});

require __DIR__.'/settings.php';
