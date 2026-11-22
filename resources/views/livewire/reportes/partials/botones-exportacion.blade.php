
@can('reportes.exportar')

    <div class="flex flex-wrap items-center gap-2">

        <flux:button
            variant="primary"
            color="red"
            icon="document-arrow-down"
            wire:click="exportarPdf"
            wire:loading.attr="disabled"
            :disabled="(bool) $errorFiltros"
        >
            <span wire:loading.remove wire:target="exportarPdf">
                Exportar PDF
            </span>

            <span wire:loading wire:target="exportarPdf">
                Generando PDF...
            </span>
        </flux:button>

        <flux:button
            variant="primary"
            color="green"
            icon="table-cells"
            wire:click="exportarExcel"
            wire:loading.attr="disabled"
            :disabled="(bool) $errorFiltros"
        >
            <span wire:loading.remove wire:target="exportarExcel">
                Exportar Excel
            </span>

            <span wire:loading wire:target="exportarExcel">
                Generando Excel...
            </span>
        </flux:button>

    </div>

@endcan
