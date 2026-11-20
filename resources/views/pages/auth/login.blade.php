<x-layouts::auth :title="__('Ingresar')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Ingresa a tu cuenta')" :description="__('Ingresa tu usuario y contraseña para continuar')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="username"
                :label="__('Usuario')"
                :value="old('username')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="Usuario"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Contraseña')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Contraseña')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Recordarme')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Ingresar') }}
                </flux:button>
            </div>
        </form>

    </div>
</x-layouts::auth>
