<?php

namespace App\Livewire\Pos;

use App\Models\User;
use App\Services\Pos\AccesoPos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Pedidos en atención')]
class PedidosAtencion extends Component
{
    use WithPagination;

    public string $modalidadFilter = '';

    public function updatedModalidadFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $query = AccesoPos::consulta($actor)->with(['mesa', 'mozo', 'delivery'])->whereNotIn('estado', ['cerrado', 'anulado'])->latest('id');
        if (in_array($this->modalidadFilter, ['mesa', 'rapida', 'delivery'], true)) {
            $query->where('modalidad', $this->modalidadFilter);
        }

        return view('livewire.pos.atenciones', ['pedidos' => $query->paginate(12)]);
    }
}
