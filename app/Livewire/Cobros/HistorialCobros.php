<?php

namespace App\Livewire\Cobros;

use App\Models\Cobro;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Historial de cobros')]
class HistorialCobros extends Component
{
    use WithPagination;

    public string $search = '';

    public string $devolucionFilter = '';

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'devolucionFilter'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actual = AccesoPos::usuario($actor);
        abort_unless($actual->can('cobros.crear') || $actual->can('devoluciones.crear'), 403);
        $query = Cobro::with('pedido')->where('estado', 'confirmado')->whereIn('pedido_id', AccesoPos::consulta($actual)->select('id'))
            ->whereIn('turno_caja_id', AccesoCajas::consulta($actual)->select('id'))
            ->withSum(['devoluciones as importe_devuelto' => fn ($q) => $q->where('estado', 'confirmada')], 'total')->withCasts(['importe_devuelto' => 'decimal:2']);
        if (trim($this->search) !== '') {
            $buscar = '%'.mb_substr(trim($this->search), 0, 100).'%';
            $query->where(fn ($q) => $q->where('codigo', 'like', $buscar)->orWhere('grupo_pagador', 'like', $buscar)->orWhere('cliente_nombre', 'like', $buscar)->orWhere('cliente_numero_documento', 'like', $buscar)->orWhereHas('pedido', fn ($p) => $p->where('codigo', 'like', $buscar)));
        }
        if ($this->devolucionFilter === 'con') {
            $query->whereHas('devoluciones', fn ($q) => $q->where('estado', 'confirmada'));
        } elseif ($this->devolucionFilter === 'sin') {
            $query->whereDoesntHave('devoluciones', fn ($q) => $q->where('estado', 'confirmada'));
        }

        return view('livewire.cobros.historial', ['cobros' => $query->latest('id')->paginate(15)]);
    }
}
