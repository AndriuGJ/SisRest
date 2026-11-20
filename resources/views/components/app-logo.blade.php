@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="app(\App\Services\Configuracion\IdentidadVisual::class)->nombre()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-white text-zinc-900">
            <x-app-logo-icon class="size-8 fill-current text-zinc-900" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="app(\App\Services\Configuracion\IdentidadVisual::class)->nombre()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-white text-zinc-900">
            <x-app-logo-icon class="size-8 fill-current text-zinc-900" />
        </x-slot>
    </flux:brand>
@endif
