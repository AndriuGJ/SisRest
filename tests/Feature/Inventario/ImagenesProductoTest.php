<?php

use App\Livewire\Inventario\ProductoFormulario;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    $this->producto = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    Storage::fake('public');
});

function webpProducto(): UploadedFile
{
    $imagen = imagecreatetruecolor(8, 8);
    ob_start();
    imagewebp($imagen);
    $bytes = ob_get_clean();

    return UploadedFile::fake()->createWithContent('chaufa.webp', $bytes);
}

test('actual webp uploads display in index and edit and serve with the correct content type using same origin', function () {
    config(['filesystems.disks.public.url' => 'http://localhost:9999/storage']);
    Livewire::test(ProductoFormulario::class, ['producto' => $this->producto])->set('foto', webpProducto())->call('save')->assertHasNoErrors();
    $producto = $this->producto->fresh();
    expect($producto->imagen_ruta)->toEndWith('.webp')->and($producto->imagen_url)->toStartWith('/inventario/productos/');
    $url = $producto->imagen_url;
    $this->get(route('productos.index'))->assertOk()->assertSee($url, false)->assertDontSee('localhost:9999');
    $this->get(route('productos.editar', $producto))->assertOk()->assertSee($url, false);
    $respuesta = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(file_get_contents($respuesta->baseResponse->getFile()->getPathname()))->toBe(Storage::disk('public')->get($producto->imagen_ruta));
});

test('image replacement changes url and removed missing or unsafe paths return not found', function () {
    Livewire::test(ProductoFormulario::class, ['producto' => $this->producto])->set('foto', webpProducto())->call('save')->assertHasNoErrors();
    $primera = $this->producto->fresh()->imagen_url;
    Livewire::test(ProductoFormulario::class, ['producto' => $this->producto->fresh()])->set('foto', webpProducto())->call('save')->assertHasNoErrors();
    expect($this->producto->fresh()->imagen_url)->not->toBe($primera);
    $this->producto->update(['imagen_ruta' => '../private/archivo.webp']);
    $this->get(route('productos.imagen', $this->producto))->assertNotFound();
    $this->producto->update(['imagen_ruta' => 'productos/ausente.webp']);
    $this->get(route('productos.imagen', $this->producto))->assertNotFound();
    Storage::disk('public')->put('productos/invalida.webp', '<script>alert(1)</script>');
    $this->producto->update(['imagen_ruta' => 'productos/invalida.webp']);
    $this->get(route('productos.imagen', $this->producto))->assertNotFound();
    $this->producto->update(['imagen_ruta' => null]);
    $this->get(route('productos.imagen', $this->producto))->assertNotFound();
});

test('product images require authenticated product consultation permission', function () {
    $this->producto->update(['imagen_ruta' => 'productos/test.webp']);
    auth()->logout();
    $this->get(route('productos.imagen', $this->producto))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('productos.imagen', $this->producto))->assertForbidden();
});
