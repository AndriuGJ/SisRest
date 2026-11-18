<?php

namespace App\Livewire\Administracion;

use App\Models\User;
use App\Services\Administracion\GestionAccesos;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('layouts.app')]
#[Title('Usuarios')]
class Usuarios extends Component
{
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'rol', except: '')]
    public string $roleFilter = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Locked]
    public ?int $userId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $roleId = '';

    public bool $activo = true;

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public ?int $passwordUserId = null;

    public bool $showPassword = false;

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    #[Locked]
    public ?int $stateUserId = null;

    #[Locked]
    public bool $nextState = false;

    #[Locked]
    public string $targetName = '';

    public bool $showState = false;

    public function mount(): void
    {
        Gate::authorize('usuarios.gestionar');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'roleFilter', 'stateFilter');
        $this->resetPage();
    }

    public function newUser(): void
    {
        Gate::authorize('usuarios.gestionar');
        $this->resetValidation();
        $this->reset('userId', 'name', 'username', 'email', 'roleId', 'activo', 'password', 'password_confirmation');
        $this->showForm = true;
    }

    public function editUser(int $id): void
    {
        Gate::authorize('usuarios.gestionar');
        $usuario = User::query()->with('roles')->findOrFail($id);
        $this->resetValidation();
        $this->userId = $usuario->id;
        $this->name = $usuario->name;
        $this->username = $usuario->username;
        $this->email = $usuario->email ?? '';
        $this->roleId = (string) ($usuario->roles()->value('roles.id') ?? '');
        $this->activo = $usuario->activo;
        $this->reset('password', 'password_confirmation');
        $this->showForm = true;
    }

    public function save(GestionAccesos $gestion): void
    {
        $gestion->guardarUsuario($this->actor(), $this->userId, [
            'name' => $this->name, 'username' => $this->username, 'email' => $this->email,
            'roleId' => $this->roleId, 'activo' => $this->activo,
            'password' => $this->password, 'password_confirmation' => $this->password_confirmation,
        ]);
        $this->reset('password', 'password_confirmation');
        $this->showForm = false;
        Flux::toast(text: $this->userId ? 'Usuario actualizado.' : 'Usuario registrado.', variant: 'success');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('usuarios.gestionar');
        $usuario = User::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateUserId = $usuario->id;
        $this->nextState = ! $usuario->activo;
        $this->targetName = $usuario->name;
        $this->showState = true;
    }

    public function confirmState(GestionAccesos $gestion): void
    {
        abort_if($this->stateUserId === null, 404);
        $gestion->cambiarEstado($this->actor(), $this->stateUserId, $this->nextState);
        $this->showState = false;
        Flux::toast(text: $this->nextState ? 'Usuario activado.' : 'Usuario desactivado.', variant: 'success');
    }

    public function requestPasswordReset(int $id): void
    {
        Gate::authorize('usuarios.gestionar');
        $usuario = User::query()->findOrFail($id);
        $this->resetValidation();
        $this->passwordUserId = $usuario->id;
        $this->targetName = $usuario->name;
        $this->reset('newPassword', 'newPassword_confirmation');
        $this->showPassword = true;
    }

    public function resetPassword(GestionAccesos $gestion): void
    {
        abort_if($this->passwordUserId === null, 404);
        $actor = $this->actor();
        $gestion->restablecerPassword($actor, $this->passwordUserId, $this->newPassword, $this->newPassword_confirmation);
        $this->reset('newPassword', 'newPassword_confirmation');
        $this->showPassword = false;

        if ($this->passwordUserId === $actor->id) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->redirectRoute('login');

            return;
        }
        Flux::toast(text: 'Contraseña actualizada. Las sesiones anteriores se cerraron.', variant: 'success');
    }

    public function updatedShowForm(bool $abierto): void
    {
        if (! $abierto) {
            $this->reset('password', 'password_confirmation');
        }
    }

    public function updatedShowPassword(bool $abierto): void
    {
        if (! $abierto) {
            $this->reset('newPassword', 'newPassword_confirmation');
        }
    }

    private function actor(): User
    {
        $usuario = Auth::user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }

    public function render(): View
    {
        Gate::authorize('usuarios.gestionar');
        $consulta = User::query()->with('roles')->orderBy('name')->orderBy('id');
        $busqueda = trim(mb_substr($this->search, 0, 100));
        if ($busqueda !== '') {
            $consulta->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$busqueda.'%')
                ->orWhere('username', 'like', '%'.$busqueda.'%')
                ->orWhere('email', 'like', '%'.$busqueda.'%'));
        }
        if (ctype_digit($this->roleFilter)) {
            $consulta->whereHas('roles', fn (Builder $query) => $query
                ->where('roles.id', $this->roleFilter)->where('guard_name', 'web'));
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.administracion.usuarios', [
            'usuarios' => $consulta->paginate(15),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->get(),
            'totalUsuarios' => User::query()->count(),
            'activos' => User::query()->where('activo', true)->count(),
        ]);
    }
}
