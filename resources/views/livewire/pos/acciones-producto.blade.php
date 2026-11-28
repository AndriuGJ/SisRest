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
                            <button type="button" wire:click="disminuirCantidad" @disabled((int)$cantidad <= 1)
                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-zinc-300 bg-white text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                -
                            </button>

                            <div
                                class="min-w-[72px] rounded-lg border border-zinc-300 bg-zinc-50 px-4 py-2 text-center text-lg font-semibold text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                                {{ $cantidad }}
                            </div>

                            <button type="button" wire:click="aumentarCantidad" @disabled((int)$cantidad >= $cantidadMaxima)
                                class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-zinc-300 bg-white text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                +
                            </button>
                        </div>
                    </div>

                    <x-pos.motivos-anulacion :motivos="$motivosAnulacion" :seleccionado="$motivoAnulacionId" />
                    <p class="text-sm text-zinc-500">
                        No se anulan cantidades cobradas o entregadas.
                        Lo no iniciado libera su reserva.
                        Lo que ya está en preparación puede registrarse
                        como merma, según las reglas y permisos de inventario.
                    </p>
                </div>

            @else
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

                <flux:button variant="primary" wire:click="review" wire:loading.attr="disabled" :disabled="$modo === 'anular' && $cantidadMaxima < 1">
                    Revisar y confirmar
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <x-pos.confirmar-anulacion :resumen="$resumenAnulacion" confirmar="confirmOperation" />
</div>
