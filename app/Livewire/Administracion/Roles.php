<?php

namespace App\Livewire\Administracion;

use App\Services\Administracion\GestionAccesos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('layouts.app')]
#[Title('Roles y permisos')]
class Roles extends Component
{
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('roles.gestionar');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('roles.gestionar');

        return view('livewire.administracion.roles', [
            'roles' => Role::query()->where('guard_name', 'web')
                ->where('name', 'like', '%'.trim(mb_substr($this->search, 0, 100)).'%')
                ->withCount(['permissions', 'users'])->orderBy('name')->paginate(15),
            'rolesSistema' => GestionAccesos::ROLES_SISTEMA,
        ]);
    }
}
