@props(['active'])

<nav aria-label="Usuarios y permisos" class="flex gap-6 border-b border-zinc-200 dark:border-zinc-700">
    @can('usuarios.gestionar')
        <a href="{{ route('usuarios.index') }}" wire:navigate @class([
            'border-b-2 px-1 pb-3 text-sm font-medium transition-colors',
            'border-sky-700 text-sky-800 dark:border-sky-400 dark:text-sky-300' => $active === 'usuarios',
            'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200' => $active !== 'usuarios',
        ]) @if($active === 'usuarios') aria-current="page" @endif>Usuarios</a>
    @endcan
    @can('roles.gestionar')
        <a href="{{ route('roles.index') }}" wire:navigate @class([
            'border-b-2 px-1 pb-3 text-sm font-medium transition-colors',
            'border-sky-700 text-sky-800 dark:border-sky-400 dark:text-sky-300' => $active === 'roles',
            'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200' => $active !== 'roles',
        ]) @if($active === 'roles') aria-current="page" @endif>Roles y permisos</a>
    @endcan
</nav>
