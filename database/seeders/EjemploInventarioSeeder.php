<?php

namespace Database\Seeders;

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\CategoriaProducto;
use App\Models\ComboComponente;
use App\Models\PresentacionArticulo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\RecetaDetalle;
use App\Models\UnidadMedida;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class EjemploInventarioSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Los ejemplos de inventario solo se permiten en local o testing.');
        }

        $this->call([CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
        $gramos = UnidadMedida::query()->where('codigo', 'g')->firstOrFail();
        $unidad = UnidadMedida::query()->where('codigo', 'und')->firstOrFail();
        $porcion = UnidadMedida::query()->where('codigo', 'porcion')->firstOrFail();
        $cocina = AreaPreparacion::query()->where('codigo', 'cocina')->firstOrFail();
        $platos = CategoriaProducto::query()->where('nombre', 'Platos')->firstOrFail();
        $bebidas = CategoriaProducto::query()->where('nombre', 'Bebidas')->firstOrFail();
        $combos = CategoriaProducto::query()->where('nombre', 'Combos')->firstOrFail();
        $adicionales = CategoriaProducto::query()->where('nombre', 'Adicionales')->firstOrFail();

        $arroz = Articulo::firstOrCreate(['codigo' => 'EJ-ARROZ'], [
            'nombre' => 'Arroz (ejemplo)', 'tipo' => 'insumo', 'unidad_medida_id' => $gramos->id,
        ]);
        $pollo = Articulo::firstOrCreate(['codigo' => 'EJ-POLLO'], [
            'nombre' => 'Pollo (ejemplo)', 'tipo' => 'insumo', 'unidad_medida_id' => $gramos->id,
        ]);
        $huevo = Articulo::firstOrCreate(['codigo' => 'EJ-HUEVO'], [
            'nombre' => 'Huevo (ejemplo)', 'tipo' => 'insumo', 'unidad_medida_id' => $unidad->id,
        ]);
        PresentacionArticulo::firstOrCreate(['articulo_id' => $arroz->id, 'nombre' => 'Saco de 50 kg'], [
            'factor_conversion' => '50000',
        ]);

        $stockChaufa = Articulo::firstOrCreate(['codigo' => 'EJ-CHAUFA-STOCK'], [
            'nombre' => 'Chaufa preparado (ejemplo)', 'tipo' => 'preparado', 'unidad_medida_id' => $porcion->id,
        ]);
        $chaufa = Producto::firstOrCreate(['codigo' => 'EJ-CHAUFA'], [
            'nombre' => 'Chaufa (ejemplo)', 'tipo' => 'plato', 'articulo_id' => $stockChaufa->id,
            'categoria_producto_id' => $platos->id, 'area_preparacion_id' => $cocina->id, 'precio_venta' => '20.00',
        ]);
        $receta = Receta::firstOrCreate(['producto_id' => $chaufa->id], [
            'rendimiento' => '1', 'observaciones' => 'Cantidades ilustrativas; revisar antes de uso real.',
        ]);
        foreach ([[$arroz, '180'], [$pollo, '120'], [$huevo, '1']] as [$insumo, $cantidad]) {
            RecetaDetalle::firstOrCreate(['receta_id' => $receta->id, 'articulo_id' => $insumo->id], [
                'cantidad' => $cantidad,
            ]);
        }

        $stockGaseosa = Articulo::firstOrCreate(['codigo' => 'EJ-GASEOSA-STOCK'], [
            'nombre' => 'Gaseosa botella (ejemplo)', 'tipo' => 'reventa', 'unidad_medida_id' => $unidad->id,
        ]);
        $gaseosa = Producto::firstOrCreate(['codigo' => 'EJ-GASEOSA'], [
            'nombre' => 'Gaseosa (ejemplo)', 'tipo' => 'reventa', 'articulo_id' => $stockGaseosa->id,
            'categoria_producto_id' => $bebidas->id, 'precio_venta' => '6.00',
        ]);
        PresentacionArticulo::firstOrCreate(['articulo_id' => $stockGaseosa->id, 'nombre' => 'Caja de 12 botellas'], [
            'factor_conversion' => '12',
        ]);
        $combo = Producto::firstOrCreate(['codigo' => 'EJ-COMBO'], [
            'nombre' => 'Chaufa y gaseosa (ejemplo)', 'tipo' => 'combo',
            'categoria_producto_id' => $combos->id, 'precio_venta' => '25.00',
        ]);
        foreach ([$chaufa, $gaseosa] as $producto) {
            ComboComponente::firstOrCreate(['combo_id' => $combo->id, 'producto_id' => $producto->id], [
                'cantidad' => '1',
            ]);
        }

        $stockExtra = Articulo::firstOrCreate(['codigo' => 'EJ-EXTRA-STOCK'], [
            'nombre' => 'Huevo preparado adicional (ejemplo)', 'tipo' => 'preparado', 'unidad_medida_id' => $porcion->id,
        ]);
        Producto::firstOrCreate(['codigo' => 'EJ-EXTRA'], [
            'nombre' => 'Huevo extra (ejemplo)', 'tipo' => 'adicional', 'articulo_id' => $stockExtra->id,
            'categoria_producto_id' => $adicionales->id, 'area_preparacion_id' => $cocina->id, 'precio_venta' => '2.00',
        ]);
    }
}
