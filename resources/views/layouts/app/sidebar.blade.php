<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 {{ request()->routeIs('pos.*') ? 'pos-palette' : '' }}">
        <flux:sidebar sticky collapsible persist="false" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="hidden justify-end lg:flex" x-data="sidebarControl">
                <button type="button" class="sidebar-compact-toggle flex size-8 items-center justify-center border border-zinc-200 text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    x-on:click="$dispatch('flux-sidebar-toggle')"
                    x-bind:aria-expanded="!collapsed"
                    x-bind:aria-label="collapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'"
                    x-bind:title="collapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'">
                    <flux:icon.chevron-right class="size-4" x-bind:class="collapsed ? '' : 'rotate-180'" />
                </button>
            </div>

            <flux:sidebar.nav>
                <div class="grid"><p class="sidebar-section-label px-3 py-2 text-sm font-medium text-zinc-400">Restaurante</p>
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('pos.ver')
                        <flux:sidebar.group heading="POS" icon="squares-2x2" expandable :expanded="request()->routeIs('pos.*')">
                            <flux:sidebar.item icon="squares-2x2" :href="route('pos.index')" :current="request()->routeIs('pos.*') && !request()->routeIs('pos.atenciones')" wire:navigate>Mesas</flux:sidebar.item>
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('pos.atenciones')" :current="request()->routeIs('pos.atenciones')" wire:navigate>Pedidos en atención</flux:sidebar.item>
                        </flux:sidebar.group>
                    @endcan
                    @can('cocina.ver')
                        <flux:sidebar.item icon="fire" :href="route('cocina.index')" :current="request()->routeIs('cocina.*')" wire:navigate>Cocina</flux:sidebar.item>
                    @endcan
                    @can('clientes.ver')
                        <flux:sidebar.item icon="user-group" :href="route('clientes.index')" :current="request()->routeIs('clientes.*')" wire:navigate>Clientes</flux:sidebar.item>
                    @endcan
                </div>
                @can('comprobantes.ver')
                    <flux:sidebar.group heading="Ventas" icon="receipt-percent" expandable :expanded="request()->routeIs('comprobantes.*')" class="mt-1"><flux:sidebar.item icon="document-text" :href="route('comprobantes.index')" :current="request()->routeIs('comprobantes.*')" wire:navigate>Comprobantes</flux:sidebar.item></flux:sidebar.group>
                @endcan
                @canany(['productos.ver', 'inventario.ver', 'preparaciones.ver'])
                    <flux:sidebar.group heading="Inventario" icon="archive-box" expandable :expanded="request()->routeIs('productos.*', 'insumos.*', 'preparaciones.*')" class="mt-1">
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
                    <flux:sidebar.group heading="Compras" icon="shopping-cart" expandable :expanded="request()->routeIs('compras.*', 'proveedores.*')" class="mt-1">
                        @can('compras.ver')
                            <flux:sidebar.item icon="shopping-cart" :href="route('compras.index')" :current="request()->routeIs('compras.*')" wire:navigate>Compras</flux:sidebar.item>
                        @endcan
                        @can('proveedores.gestionar')
                            <flux:sidebar.item icon="truck" :href="route('proveedores.index')" :current="request()->routeIs('proveedores.*')" wire:navigate>Proveedores</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
                @canany(['cajas.ver_propias', 'cajas.ver_todas'])
                    <flux:sidebar.group heading="Caja" icon="banknotes" expandable :expanded="request()->routeIs('cajas.*')" class="mt-1">
                        <flux:sidebar.item icon="banknotes" :href="route('cajas.index')" :current="request()->routeIs('cajas.*')" wire:navigate>Cajas y arqueos</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcanany
                @canany(['usuarios.gestionar', 'roles.gestionar'])
                    <flux:sidebar.group heading="Usuarios y permisos" icon="users" expandable :expanded="request()->routeIs('usuarios.*', 'roles.*')" class="mt-1">
                        @can('usuarios.gestionar')
                            <flux:sidebar.item icon="users" :href="route('usuarios.index')" :current="request()->routeIs('usuarios.*')" wire:navigate>Usuarios</flux:sidebar.item>
                        @endcan
                        @can('roles.gestionar')
                            <flux:sidebar.item icon="shield-check" :href="route('roles.index')" :current="request()->routeIs('roles.*')" wire:navigate>Roles y permisos</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
                @can('configuracion.sistema')
                    <flux:sidebar.group heading="Configuración" icon="cog-6-tooth" expandable :expanded="request()->routeIs('configuracion.facturacion', 'configuracion.empresa', 'configuracion.tipografia', 'configuracion.colores')" class="mt-1"><flux:sidebar.item icon="building-office" :href="route('configuracion.empresa')" :current="request()->routeIs('configuracion.empresa')" wire:navigate>Empresa</flux:sidebar.item>
                        <flux:sidebar.item icon="document-text" :href="route('configuracion.facturacion')" :current="request()->routeIs('configuracion.facturacion')" wire:navigate>Facturación</flux:sidebar.item>
                        <flux:sidebar.item icon="language" :href="route('configuracion.tipografia')" :current="request()->routeIs('configuracion.tipografia')" wire:navigate>Tipografía</flux:sidebar.item>
                        <flux:sidebar.item icon="swatch" :href="route('configuracion.colores')" :current="request()->routeIs('configuracion.colores')" wire:navigate>Colores de estado</flux:sidebar.item></flux:sidebar.group>
                @endcan
                @canany(['configuracion.restaurante', 'impresoras.gestionar'])
                    <flux:sidebar.group heading="Config. restaurante" icon="building-storefront" expandable :expanded="request()->routeIs('configuracion.salones', 'configuracion.mesas', 'configuracion.destinos', 'configuracion.impresoras')" class="mt-1">
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
