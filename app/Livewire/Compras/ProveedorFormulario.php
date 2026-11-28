<?php

namespace App\Livewire\Compras;

use App\Models\Proveedor;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use App\Services\Compras\GestionProveedores;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Proveedor')]
class ProveedorFormulario extends Component
{
    #[Locked]
    public ?int $proveedorId = null;

    #[Locked]
    public string $version = '';

    public string $nombre = '';

    public string $tipoId = '';

    public string $numero = '';

    public string $direccion = '';

    public string $telefono = '';

    public string $correo = '';

    public bool $activo = true;

    public function mount(?Proveedor $proveedor = null): void
    {
        Gate::authorize('proveedores.gestionar');
        if ($proveedor?->exists) {
            $this->proveedorId = $proveedor->id;
            $this->version = GestionProveedores::huella($proveedor);
            $this->nombre = $proveedor->nombre;
            $this->tipoId = (string) ($proveedor->tipo_documento_identidad_id ?? '');
            $this->numero = $proveedor->numero_documento ?? '';
            $this->direccion = $proveedor->direccion ?? '';
            $this->telefono = $proveedor->telefono ?? '';
            $this->correo = $proveedor->correo ?? '';
            $this->activo = $proveedor->activo;
        }
    }

    public function save(GestionProveedores $gestion): void
    {
        Gate::authorize('proveedores.gestionar');
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $gestion->guardar($actor, $this->proveedorId, ['nombre' => $this->nombre, 'tipoId' => $this->tipoId, 'numero' => $this->numero,
            'direccion' => $this->direccion, 'telefono' => $this->telefono, 'correo' => $this->correo, 'activo' => $this->activo], $this->version);
        Alertas::flash('Proveedor guardado.');
        $this->redirectRoute('proveedores.index', navigate: true);
    }

    public function render(): View
    {
        Gate::authorize('proveedores.gestionar');

        return view('livewire.compras.proveedor-formulario', ['tipos' => TipoDocumentoIdentidad::orderBy('id')->get()]);
    }
}
