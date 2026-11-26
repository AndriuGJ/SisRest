<?php

namespace App\Livewire\Facturacion;

use App\Models\Comprobante;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Pos\AccesoPos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Comprobantes electrónicos')]
class Comprobantes extends Component
{
    use WithPagination;

    public string $buscar = '';

    public string $estado = '';

    public string $entorno = '';

    public function updated(string $campo): void
    {
        if (in_array($campo, ['buscar', 'estado', 'entorno'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actual = AccesoFacturacion::usuario($actor);
        $comprobantes = Comprobante::whereHas('cobro', fn ($q) => $q->whereIn('pedido_id', AccesoPos::consulta($actual)->select('id'))->whereIn('turno_caja_id', AccesoCajas::consulta($actual)->select('id')))
            ->when($this->buscar !== '', fn ($q) => $q->where(function ($q) {
                $busqueda = trim(mb_substr($this->buscar, 0, 100));
                $q->where('receptor->nombre', 'like', '%'.$busqueda.'%')->orWhere('receptor->numero', 'like', '%'.$busqueda.'%')->orWhere('configuracion_tributaria->serie', 'like', '%'.$busqueda.'%');
                if (preg_match('/^([FB][A-Z0-9]{3})-(\d{1,8})$/iD', $busqueda, $m)) {
                    $q->orWhere(fn ($q) => $q->where('configuracion_tributaria->serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]));
                } elseif (ctype_digit($busqueda)) {
                    $q->orWhere('correlativo', (int) $busqueda);
                }
            }))
            ->when($this->estado !== '', fn ($q) => $q->where('estado_sunat', $this->estado))->when($this->entorno !== '', fn ($q) => $q->where('configuracion_tributaria->entorno', $this->entorno))
            ->latest('id')->paginate(15);

        return view('livewire.facturacion.index', compact('comprobantes'));
    }
}
