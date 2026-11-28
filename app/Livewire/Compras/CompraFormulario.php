<?php

namespace App\Livewire\Compras;

use App\Models\Articulo;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\User;
use App\Services\Compras\GestionCompras;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Registrar compra')]
class CompraFormulario extends Component
{
    #[Locked]
    public ?int $compraId = null;

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $version = '';

    public string $proveedorId = '';

    public string $tipoId = '';

    public string $serie = '';

    public string $numero = '';

    public string $fecha = '';

    public string $vencimiento = '';

    public string $condicion = 'contado';

    public string $observaciones = '';

    /** @var array<string,array{articuloId:string,presentacionId:string,cantidad:string,costo:string,descuento:string,impuestos:string}> */
    public array $filas = [];

    public function mount(?Compra $compra = null): void
    {
        $this->autorizar();
        $this->clave = (string) Str::uuid();
        $this->fecha = now()->format('Y-m-d');
        $this->tipoId = (string) (TipoComprobante::where('codigo', '00')->value('id') ?? '');
        if ($compra?->exists) {
            abort_unless($compra->estado->value === 'borrador', 403);
            abort_unless($compra->usuario_id === Auth::id() || Gate::allows('compras.confirmar'), 403);
            $this->compraId = $compra->id;
            $this->clave = $compra->clave_registro ?? $this->clave;
            $this->version = GestionCompras::huella($compra);
            $this->proveedorId = (string) $compra->proveedor_id;
            $this->tipoId = (string) $compra->tipo_comprobante_id;
            $this->serie = $compra->serie ?? '';
            $this->numero = $compra->numero ?? '';
            $this->fecha = $compra->fecha->format('Y-m-d');
            $this->vencimiento = $compra->fecha_vencimiento?->format('Y-m-d') ?? '';
            $this->condicion = $compra->condicion_pago->value;
            $this->observaciones = $compra->observaciones ?? '';
            foreach ($compra->detalles as $detalle) {
                $this->filas['d-'.$detalle->id] = ['articuloId' => (string) $detalle->articulo_id, 'presentacionId' => (string) ($detalle->presentacion_articulo_id ?? ''),
                    'cantidad' => $detalle->cantidad, 'costo' => $detalle->costo_unitario, 'descuento' => $detalle->descuento, 'impuestos' => $detalle->impuestos];
            }
        } else {
            $this->addRow();
        }
    }

    private function autorizar(): void
    {
        Gate::authorize('compras.ver');
        Gate::authorize('compras.crear');
    }

    public function addRow(): void
    {
        $this->autorizar();
        if (count($this->filas) < 50) {
            $this->filas['n-'.Str::uuid()] = ['articuloId' => '', 'presentacionId' => '', 'cantidad' => '1', 'costo' => '', 'descuento' => '0', 'impuestos' => '0'];
        }
    }

    public function removeRow(string $clave): void
    {
        $this->autorizar();
        unset($this->filas[$clave]);
    }

    public function updated(string $campo): void
    {
        if (preg_match('/^filas\.([^.]+)\.articuloId$/', $campo, $partes) && isset($this->filas[$partes[1]])) {
            $this->filas[$partes[1]]['presentacionId'] = '';
        }
    }

    public function save(GestionCompras $gestion): void
    {
        $this->autorizar();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $compra = $gestion->guardar($actor, $this->compraId, $this->clave, ['proveedorId' => $this->proveedorId, 'tipoId' => $this->tipoId,
            'serie' => $this->serie, 'numero' => $this->numero, 'fecha' => $this->fecha, 'vencimiento' => $this->vencimiento,
            'condicion' => $this->condicion, 'observaciones' => $this->observaciones], $this->filas, $this->version);
        Alertas::flash('Borrador guardado. Confirma la compra para ingresar las existencias.');
        $this->redirectRoute('compras.ver', $compra, navigate: true);
    }

    public function render(): View
    {
        $this->autorizar();

        return view('livewire.compras.compra-formulario', [
            'proveedores' => Proveedor::orderBy('nombre')->get(),
            'tipos' => TipoComprobante::whereIn('codigo', ['00', '01', '03'])->where('activo', true)->get(),
            'articulos' => Articulo::with(['unidadMedida', 'presentaciones'])->whereIn('tipo', ['insumo', 'reventa'])->orderBy('nombre')->get(),
        ]);
    }
}
