<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Restaurante" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('pos.ver')
                        <flux:sidebar.item icon="squares-2x2" :href="route('pos.index')" :current="request()->routeIs('pos.*')" wire:navigate>POS</flux:sidebar.item>
                    @endcan
                    @can('clientes.ver')
                        <flux:sidebar.item icon="user-group" :href="route('clientes.index')" :current="request()->routeIs('clientes.*')" wire:navigate>Clientes</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
                @canany(['productos.ver', 'inventario.ver', 'preparaciones.ver'])
                    <flux:sidebar.group heading="Inventario" class="mt-5 grid">
                        @can('productos.ver')
                            <flux:sidebar.item icon="cube" :href="route('productos.index')" :current="request()->routeIs('productos.*')" wire:navigate>Productos para la venta</flux:sidebar.item>
                        @endcan
                        @can('inventario.ver')
                            <flux:sidebar.item icon="archive-box" :href="route('insumos.index')" :current="request()->routeIs('insumos.*')" wire:navigate>Insumos</flux:sidebar.item>
                        @endcan
                        @can('preparaciones.ver')
                            <flux:sidebar.item icon="beaker" :href="route('preparaciones.index')" :current="request()->routeIs('preparaciones.*')" wire:navigate>Preparación diaria</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
                @canany(['compras.ver', 'proveedores.gestionar'])
                    <flux:sidebar.group heading="Compras" class="mt-5 grid">
                        @can('compras.ver')
                            <flux:sidebar.item icon="shopping-cart" :href="route('compras.index')" :current="request()->routeIs('compras.*')" wire:navigate>Compras</flux:sidebar.item>
                        @endcan
                        @can('proveedores.gestionar')
                            <flux:sidebar.item icon="truck" :href="route('proveedores.index')" :current="request()->routeIs('proveedores.*')" wire:navigate>Proveedores</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
                @canany(['cajas.ver_propias', 'cajas.ver_todas'])
                    <flux:sidebar.group heading="Caja" class="mt-5 grid">
                        <flux:sidebar.item icon="banknotes" :href="route('cajas.index')" :current="request()->routeIs('cajas.*')" wire:navigate>Cajas y arqueos</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcanany
                @canany(['usuarios.gestionar', 'roles.gestionar'])
                    <flux:sidebar.group heading="Administración" class="mt-5 grid">
                        @can('usuarios.gestionar')
                            <flux:sidebar.item icon="users" :href="route('usuarios.index')" :current="request()->routeIs('usuarios.*')" wire:navigate>Usuarios</flux:sidebar.item>
                        @endcan
                        @can('roles.gestionar')
                            <flux:sidebar.item icon="shield-check" :href="route('roles.index')" :current="request()->routeIs('roles.*')" wire:navigate>Roles y permisos</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
                @canany(['configuracion.restaurante', 'impresoras.gestionar'])
                    <flux:sidebar.group heading="Config. restaurante" class="mt-5 grid">
                        @can('configuracion.restaurante')
                            <flux:sidebar.item icon="building-storefront" :href="route('configuracion.salones')" :current="request()->routeIs('configuracion.salones')" wire:navigate>Salones</flux:sidebar.item>
                            <flux:sidebar.item icon="squares-2x2" :href="route('configuracion.mesas')" :current="request()->routeIs('configuracion.mesas')" wire:navigate>Mesas</flux:sidebar.item>
                            <flux:sidebar.item icon="fire" :href="route('configuracion.destinos')" :current="request()->routeIs('configuracion.destinos')" wire:navigate>Destinos de preparación</flux:sidebar.item>
                        @endcan
                        @can('impresoras.gestionar')
                            <flux:sidebar.item icon="printer" :href="route('configuracion.impresoras')" :current="request()->routeIs('configuracion.impresoras')" wire:navigate>Impresoras</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->username }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Mi cuenta
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Cerrar sesión
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-alertas />

        @fluxScripts
    </body>
</html>
