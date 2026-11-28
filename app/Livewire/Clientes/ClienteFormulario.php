<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use App\Services\Clientes\GestionClientes;
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
#[Title('Cliente')]
class ClienteFormulario extends Component
{
    #[Locked]
    public ?int $clienteId = null;

    #[Locked]
    public string $version = '';

    #[Locked]
    public string $clave = '';

    public string $tipo = 'persona';

    public string $nombre = '';

    public string $tipoId = '';

    public string $numero = '';

    public string $direccion = '';

    public string $telefono = '';

    public string $correo = '';

    public bool $activo = true;

    public function mount(?Cliente $cliente = null): void
    {
        $this->clienteId = $cliente?->exists ? $cliente->id : null;
        $this->autorizar();
        $this->clave = (string) Str::uuid();
        if ($cliente?->exists) {
            $this->clave = $cliente->clave_registro ?? $this->clave;
            $this->version = GestionClientes::huella($cliente);
            $this->tipo = $cliente->tipo->value;
            $this->nombre = $cliente->nombre;
            $this->tipoId = (string) ($cliente->tipo_documento_identidad_id ?? '');
            $this->numero = $cliente->numero_documento ?? '';
            $this->direccion = $cliente->direccion ?? '';
            $this->telefono = $cliente->telefono ?? '';
            $this->correo = $cliente->correo ?? '';
            $this->activo = $cliente->activo;
        }
    }

    private function autorizar(): User
    {
        $usuario = Auth::user();
        abort_unless($usuario instanceof User, 403);
        $actual = User::findOrFail($usuario->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('clientes.ver');
        Gate::forUser($actual)->authorize($this->clienteId ? 'clientes.editar' : 'clientes.crear');

        return $actual;
    }

    public function updatedTipo(): void
    {
        $this->autorizar();
        if ($this->tipo === 'empresa') {
            $this->tipoId = (string) (TipoDocumentoIdentidad::where('codigo', '6')->value('id') ?? '');
        }
        $this->resetValidation();
    }

    public function save(GestionClientes $gestion): void
    {
        $this->resetValidation();
        $gestion->guardar($this->autorizar(), $this->clienteId, $this->clave, ['tipo' => $this->tipo, 'nombre' => $this->nombre,
            'tipoId' => $this->tipoId, 'numero' => $this->numero, 'direccion' => $this->direccion, 'telefono' => $this->telefono,
            'correo' => $this->correo, 'activo' => $this->activo], $this->version);
        Alertas::flash($this->clienteId ? 'Cliente actualizado.' : 'Cliente registrado.');
        $this->redirectRoute('clientes.index', navigate: true);
    }

    public function render(): View
    {
        $this->autorizar();
        $cliente = $this->clienteId ? Cliente::findOrFail($this->clienteId) : null;
        $vinculado = $cliente && GestionClientes::vinculado($cliente);

        return view('livewire.clientes.cliente-formulario', [
            'tipos' => TipoDocumentoIdentidad::orderBy('id')->get(), 'vinculado' => $vinculado,
            'documentoProtegido' => $vinculado && $cliente->numero_documento !== null,
        ]);
    }
}
