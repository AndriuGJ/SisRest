<?php

use App\Livewire\Administracion\Roles;
use App\Livewire\Administracion\RolFormulario;
use App\Livewire\Administracion\Usuarios;
use App\Livewire\Configuracion\Destinos;
use App\Livewire\Configuracion\Impresoras;
use App\Livewire\Configuracion\Mesas;
use App\Livewire\Configuracion\Salones;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

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
