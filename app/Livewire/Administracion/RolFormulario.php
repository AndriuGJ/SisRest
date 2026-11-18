<?php

namespace App\Livewire\Administracion;

use App\Models\User;
use App\Services\Administracion\GestionAccesos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Layout('layouts.app')]
#[Title('Configurar rol')]
class RolFormulario extends Component
{
    #[Locked]
    public ?int $roleId = null;

    #[Locked]
    public bool $systemRole = false;

    public string $name = '';

    /** @var array<int, string> */
    public array $permissions = [];

    public string $permissionSearch = '';

    public function mount(?Role $rol = null): void
    {
        Gate::authorize('roles.gestionar');
        if ($rol?->exists) {
            abort_unless($rol->guard_name === 'web', 404);
            abort_unless(is_int($rol->id), 404);
            $this->roleId = $rol->id;
            $this->name = $rol->name;
            $this->systemRole = in_array($rol->name, GestionAccesos::ROLES_SISTEMA, true);
            $this->permissions = $rol->permissions->map(fn (Permission $permiso): string => $permiso->name)->all();
        }
    }

    public function toggleGroup(string $grupo): void
    {
        Gate::authorize('roles.gestionar');
        $permisos = array_values(array_filter(array_keys($this->groups()[$grupo]['acciones'] ?? []),
            fn (string $permiso): bool => $this->puedeSeleccionar($permiso)));
        $todos = count(array_intersect($permisos, $this->permissions)) === count($permisos);
        $this->permissions = $todos
            ? array_values(array_diff($this->permissions, $permisos))
            : array_values(array_unique(array_merge($this->permissions, $permisos)));
    }

    public function selectAll(): void
    {
        Gate::authorize('roles.gestionar');
        $this->permissions = Permission::query()->where('guard_name', 'web')->get()
            ->filter(fn (Permission $permiso): bool => $this->puedeSeleccionar($permiso->name))
            ->map(fn (Permission $permiso): string => $permiso->name)->values()->all();
    }

    public function clearSelection(): void
    {
        Gate::authorize('roles.gestionar');
        $this->permissions = [];
    }

    private function puedeSeleccionar(string $permiso): bool
    {
        $actor = Auth::user();

        return $actor instanceof User
            && (GestionAccesos::tieneGestionCompleta($actor) || $actor->can($permiso));
    }

    public function save(GestionAccesos $gestion): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $gestion->guardarRol($actor, $this->roleId, $this->name, array_values($this->permissions));
        session()->flash('success', $this->roleId ? 'Permisos del rol actualizados.' : 'Rol registrado.');
        $actor->refresh()->unsetRelations();
        $this->redirectRoute($actor->can('roles.gestionar') ? 'roles.index' : 'dashboard', navigate: true);
    }

    /** @return array<string, array{titulo: string, acciones: array<string, string>}> */
    private function groups(): array
    {
        $existentes = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $grupos = config('permisos');
        $cubiertos = [];
        foreach ($grupos as $clave => $grupo) {
            $grupos[$clave]['acciones'] = array_intersect_key($grupo['acciones'], array_flip($existentes));
            $cubiertos = array_merge($cubiertos, array_keys($grupos[$clave]['acciones']));
        }
        $otros = array_diff($existentes, $cubiertos);
        if ($otros !== []) {
            $grupos['otros'] = ['titulo' => 'Otros permisos', 'acciones' => []];
            foreach ($otros as $permiso) {
                $grupos['otros']['acciones'][$permiso] = Str::headline(str_replace('.', ' ', $permiso));
            }
        }

        return $grupos;
    }

    public function render(): View
    {
        Gate::authorize('roles.gestionar');
        $grupos = $this->groups();
        $total = array_sum(array_map(fn (array $grupo): int => count($grupo['acciones']), $grupos));
        $filtro = Str::lower(trim(mb_substr($this->permissionSearch, 0, 100)));
        if ($filtro !== '') {
            foreach ($grupos as $clave => $grupo) {
                if (! Str::contains(Str::lower($grupo['titulo']), $filtro)) {
                    $grupos[$clave]['acciones'] = array_filter($grupo['acciones'],
                        fn (string $nombre): bool => Str::contains(Str::lower($nombre), $filtro));
                }
            }
        }
        $grupos = array_filter($grupos, fn (array $grupo): bool => $grupo['acciones'] !== []);

        return view('livewire.administracion.rol-formulario', [
            'grupos' => $grupos, 'totalPermisos' => $total,
            'gestionCompleta' => Auth::user() instanceof User && GestionAccesos::tieneGestionCompleta(Auth::user()),
        ]);
    }
}
