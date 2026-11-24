<div>
    <flux:modal wire:model="showModal" class="md:w-lg">
        <div class="space-y-5">

            <div>
                <flux:heading level="2" size="lg">
                    {{ $modo === 'anular' ? 'Anular producto confirmado' : 'Beneficio del producto' }}
                </flux:heading>

                <flux:text class="mt-1">
                    {{ $detalle?->descripcion }}
                </flux:text>
            </div>

            @if ($errors->any())
                <p role="alert" class="text-sm text-red-700">
                    {{ $errors->first() }}
                </p>
            @endif

            @if ($modo === 'anular')

                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                            Cantidad a anular
                        </p>

                        <div class="mt-2 flex items-center justify-center gap-3">
                            <button type="button" wire:click="disminuirCantidad"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-zinc-300 bg-white text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                -
                            </button>

                            <div
                                class="min-w-[72px] rounded-lg border border-zinc-300 bg-zinc-50 px-4 py-2 text-center text-lg font-semibold text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                                {{ $cantidad }}
                            </div>

                            <button type="button" wire:click="aumentarCantidad"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-zinc-300 bg-white text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                +
                            </button>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                            Motivo de la anulación
                        </p>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($motivosAnulacion as $motivoOpcion)
                                <button type="button" wire:click="$set('motivoAnulacionId', '{{ $motivoOpcion->id }}')"
                                    class="rounded-xl border px-4 py-3 text-left text-sm font-medium transition
                            {{ (string) $motivoAnulacionId === (string) $motivoOpcion->id
                                ? 'border-red-500 bg-red-50 text-red-700 shadow-sm dark:border-red-400 dark:bg-red-500/10 dark:text-red-300'
                                : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700/60' }}">
                                    {{ $motivoOpcion->nombre }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <p class="text-sm text-zinc-500">
                        No se anulan cantidades cobradas o entregadas.
                        Lo no iniciado libera su reserva.
                        Lo que ya está en preparación puede registrarse
                        como merma, según las reglas y permisos de inventario.
                    </p>
                </div>

            @endif
            <flux:select wire:model.live="accion" label="Acción del beneficio">
                @can('descuentos.aplicar')
                    <option value="descuento">Aplicar descuento</option>
                @endcan

                @can('cortesias.aplicar')
                    <option value="cortesia">Autorizar cortesía</option>
                @endcan

                <option value="quitar">Retirar beneficio anterior</option>
            </flux:select>

            @if ($accion === 'descuento')
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:select wire:model="tipo" label="Tipo de descuento">
                        <option value="porcentaje">Porcentaje</option>
                        <option value="soles">Soles</option>
                    </flux:select>

                    <flux:input wire:model="valor" inputmode="decimal" label="Valor del descuento" />
                </div>
                <p class="text-xs text-zinc-500">
                    En soles se indica el descuento total de esta
                    línea. Cubrir el importe completo exige permiso
                    de cortesía.
                </p>
            @endif

            @if ($accion !== 'quitar')
                <flux:select wire:model="motivoId" label="Motivo del beneficio">
                    <option value="">Seleccionar motivo</option>

                    @foreach ($motivos as $motivoOpcion)
                        <option value="{{ $motivoOpcion->id }}">
                            {{ $motivoOpcion->nombre }}
                        </option>
                    @endforeach
                </flux:select>
            @endif

            <p class="text-sm text-zinc-500">
                Los beneficios se aplican a las unidades vigentes
                antes del cobro. No modifican existencias.
            </p>

            <flux:textarea wire:model="motivo" label="Detalle de la autorización" rows="3" maxlength="500" />

            @endif

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showModal', false)">
                    Cancelar
                </flux:button>

                <flux:button variant="primary" wire:click="review" wire:loading.attr="disabled">
                    Revisar y confirmar
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Segunda ventana: confirmación con clave --}}
    <flux:modal wire:model.self="showConfirmCancel" class="w-full md:max-w-lg" :dismissible="false">
        <div class="space-y-5 px-2 py-1 text-center">

            <div
                class="mx-auto flex h-24 w-24 items-center justify-center rounded-full border-4 border-orange-200 text-5xl font-bold text-orange-300">
                !
            </div>

            <div class="space-y-2">
                <h2 class="text-3xl font-bold text-zinc-700 dark:text-zinc-100">
                    Necesitamos de tu Confirmación
                </h2>

                <p class="text-base text-zinc-500 dark:text-zinc-300">
                    {{ $resumenAnulacion }}
                </p>
            </div>

            @if ($errors->any())
                <p role="alert" class="text-sm font-medium text-red-600">
                    {{ $errors->first() }}
                </p>
            @endif

            <div class="mx-auto max-w-xs text-left">
                <label class="mb-2 block text-center text-lg font-medium text-zinc-700 dark:text-zinc-200">
                    Ingrese código de seguridad
                </label>

                <input type="password" wire:model="claveAutorizacion" maxlength="12" autocomplete="off"
                    class="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-center text-lg outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <p class="text-3xl font-medium text-lime-500">
                ¿Está Usted de Acuerdo?
            </p>

            <div class="flex justify-center gap-3 pt-2">
                <button type="button" wire:click="confirmOperation" wire:loading.attr="disabled"
                    wire:target="confirmOperation"
                    class="rounded-md bg-green-500 px-7 py-3 text-lg font-semibold text-white transition hover:bg-green-600">
                    Sí, Adelante!
                </button>

                <button type="button" wire:click="cancelarConfirmacionAnulacion"
                    class="rounded-md bg-zinc-400 px-7 py-3 text-lg font-semibold text-white transition hover:bg-zinc-500">
                    No!
                </button>
            </div>
        </div>
    </flux:modal>
</div>
