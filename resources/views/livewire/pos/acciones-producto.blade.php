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

                <flux:input wire:model="cantidad" type="number" min="1" max="9999" step="1"
                    label="Cantidad a anular" required />

                <flux:select wire:model="motivoAnulacionId" label="Motivo de la anulación" required>
                    <option value="">Seleccionar motivo</option>

                    @foreach ($motivosAnulacion as $motivoOpcion)
                        <option value="{{ $motivoOpcion->id }}">
                            {{ $motivoOpcion->nombre }}
                        </option>
                    @endforeach
                </flux:select>

                <p class="text-sm text-zinc-500">
                    No se anulan cantidades cobradas o entregadas.
                    Lo no iniciado libera su reserva.
                    Lo que ya está en preparación puede registrarse
                    como merma, según las reglas y permisos de inventario.
                </p>
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

                <flux:button variant="primary" wire:click="review" wire:loading.attr="disabled">
                    Revisar y confirmar
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Segunda ventana: confirmación con clave --}}
    <flux:modal wire:model.self="showConfirmCancel" class="w-full md:max-w-lg" :dismissible="false">
        <div class="space-y-4">
            <flux:heading size="lg">
                Autorizar anulación
            </flux:heading>

            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                {{ $resumenAnulacion }}
            </p>

            @if ($errors->any())
                <p role="alert" class="text-sm text-red-700">
                    {{ $errors->first() }}
                </p>
            @endif

            <flux:input type="password" wire:model="claveAutorizacion" label="Clave de autorización"
                placeholder="Ingresa la clave configurada" autocomplete="off" maxlength="12" required />

            <p class="text-xs text-zinc-500">
                La operación quedará registrada con el usuario,
                motivo, cantidad y fecha.
            </p>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelarConfirmacionAnulacion">
                    Cancelar
                </flux:button>

                <flux:button variant="danger" wire:click="confirmOperation" wire:loading.attr="disabled"
                    wire:target="confirmOperation">
                    Autorizar y anular
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
