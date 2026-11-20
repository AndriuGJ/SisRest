<?php

namespace App\Livewire\Inventario;

use App\Models\Preparacion;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Preparación diaria')]
class Preparaciones extends Component
{
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('preparaciones.ver');
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'stateFilter', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('preparaciones.ver');
        $consulta = Preparacion::query()->with(['producto', 'usuario'])->latest('fecha')->latest('id');
        $buscar = trim(mb_substr($this->search, 0, 100));
        if ($buscar !== '') {
            $consulta->where(fn (Builder $query) => $query->where('codigo', 'like', '%'.$buscar.'%')->orWhere('producto_nombre', 'like', '%'.$buscar.'%'));
        }
        if (in_array($this->stateFilter, ['borrador', 'confirmada', 'anulada'], true)) {
            $consulta->where('estado', $this->stateFilter);
        }
        foreach (['from' => '>=', 'to' => '<='] as $campo => $operador) {
            if ($this->$campo !== '' && Validator::make(['fecha' => $this->$campo], ['fecha' => ['date_format:Y-m-d']])->passes()) {
                $consulta->whereDate('fecha', $operador, $this->$campo);
            }
        }

        return view('livewire.inventario.preparaciones', ['preparaciones' => $consulta->paginate(15)]);
    }
}
