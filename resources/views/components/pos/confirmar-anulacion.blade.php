@props(['resumen', 'confirmar', 'cancelar' => 'cancelarConfirmacionAnulacion'])
<flux:modal wire:model.self="showConfirmCancel" :dismissible="false" :closable="false"
    style="width:min(420px,calc(100vw - 32px));max-width:420px;padding:32px 28px 22px;border:0;border-radius:4px;background:#fff;color:#59616a">
    <div class="text-center" x-data>
        <div aria-hidden="true"
            style="display:flex;align-items:center;justify-content:center;width:72px;height:72px;margin:0 auto 24px;border:3px solid #ffce9f;border-radius:50%;color:#ffbc80;font-size:54px;font-weight:300;line-height:1">!</div>

        <h2 style="margin:0 0 10px;color:#59616a;font-size:20px;font-weight:700;line-height:1.5">
            Necesitamos de tu<br>Confirmación
        </h2>
        <p style="margin:0;color:#59616a;font-size:15px;line-height:1.6">{{ $resumen }}</p>

        @if($errors->any())
            <p role="alert" style="margin:12px 0 0;color:#dc2626;font-size:13px;font-weight:500">{{ $errors->first() }}</p>
        @endif

        <div style="margin:20px auto 0;text-align:center">
            <label for="clave-{{ $confirmar }}" style="display:block;margin-bottom:2px;font-size:15px;color:#59616a">
                Ingrese código de seguridad
            </label>
            <input id="clave-{{ $confirmar }}" type="password" x-ref="clave" inputmode="numeric" maxlength="12" autocomplete="off"
                style="display:block;width:166px;height:38px;margin:0 auto;padding:6px 10px;border:1px solid #cbd3dd;border-radius:3px;background:#fff;color:#59616a;text-align:center;font-size:18px" />
        </div>

        <p style="margin:22px 0 0;color:#00dc55;font-size:18px;font-weight:400;line-height:1.5">¿Está Usted de Acuerdo?</p>

        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:20px">
            <button type="button" x-on:click="const clave = $refs.clave.value; $refs.clave.value = ''; $wire.{{ $confirmar }}(clave)"
                wire:target="{{ $confirmar }}" wire:loading.attr="disabled" class="cursor-pointer disabled:cursor-wait disabled:opacity-60"
                style="min-width:142px;height:38px;padding:0 20px;border:0;border-radius:3px;background:#2dcc67;color:#fff;font-size:14px;font-weight:600">
                Sí, Adelante!
            </button>
            <button type="button" wire:click="{{ $cancelar }}" x-on:click="$refs.clave.value = ''" wire:loading.attr="disabled"
                class="cursor-pointer disabled:cursor-wait disabled:opacity-60"
                style="min-width:79px;height:38px;padding:0 18px;border:0;border-radius:3px;background:#aaa;color:#fff;font-size:14px;font-weight:600">
                No!
            </button>
        </div>
    </div>
</flux:modal>
