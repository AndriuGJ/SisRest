<?php

namespace App\Services\Configuracion;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Services\Facturacion\ReglasFiscales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GuardarIdentidad
{
    public static function version(Empresa $empresa): string
    {
        $datos = $empresa->getRawOriginal();
        ksort($datos);

        return hash('sha256', serialize($datos));
    }

    /** @param array<string,string> $datos */
    public function empresa(User $actor, string $version, array $datos, ?string $logo, bool $quitarLogo): void
    {
        ConfigurarFacturacion::autorizar($actor);
        $validado = Validator::make($datos, [
            'ruc' => ['nullable', 'digits:11'], 'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:150'], 'direccion' => ['nullable', 'string', 'max:250'],
            'ubigeo' => ['nullable', 'digits:6'], 'departamento' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'], 'distrito' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'], 'correo' => ['nullable', 'email', 'max:150'],
        ])->validate();
        ReglasFiscales::exigir(($datos['ruc'] ?? '') === '' || ReglasFiscales::ruc($datos['ruc']), 'El RUC no es válido.', 'datos.ruc');
        $ruta = $logo !== null ? $this->guardarLogo($logo) : null;
        DB::transaction(function () use ($actor, $version, $validado, $ruta, $quitarLogo): void {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            ConfigurarFacturacion::autorizar($actor);
            ReglasFiscales::exigir(hash_equals(self::version($empresa), $version), 'La empresa cambió en otra sesión. Recarga la pantalla antes de guardar.', 'configuracion');
            ReglasFiscales::exigir(! $empresa->ruc || $empresa->ruc === ($validado['ruc'] ?: null) || ! Comprobante::exists(), 'Ya existen comprobantes: conserva el RUC del emisor.', 'datos.ruc');
            $empresa->fill($validado);
            if ($ruta !== null || $quitarLogo) {
                $empresa->logo_ruta = $ruta;
            }
            $empresa->save();
            activity()->causedBy($actor)->performedOn($empresa)->withProperties(['campos' => array_keys($validado), 'logo_actualizado' => $ruta !== null || $quitarLogo])->log('Empresa e identidad visual actualizadas');
        }, 3);
        app(IdentidadVisual::class)->olvidar();
    }

    private function guardarLogo(string $bytes): string
    {
        $imagen = @imagecreatefromstring($bytes);
        ReglasFiscales::exigir($imagen !== false, 'No se pudo procesar el logo.', 'logo');
        try {
            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);
            $factor = min(1, 768 / max($ancho, $alto));
            $salida = imagecreatetruecolor(max(1, (int) round($ancho * $factor)), max(1, (int) round($alto * $factor)));
            imagealphablending($salida, false);
            imagesavealpha($salida, true);
            $transparente = imagecolorallocatealpha($salida, 255, 255, 255, 127);
            ReglasFiscales::exigir($transparente !== false, 'No se pudo preparar el logo.', 'logo');
            imagefill($salida, 0, 0, $transparente);
            imagecopyresampled($salida, $imagen, 0, 0, 0, 0, imagesx($salida), imagesy($salida), $ancho, $alto);
            ob_start();
            try {
                imagepng($salida);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($salida);
            }
            ReglasFiscales::exigir(is_string($png), 'No se pudo convertir el logo.', 'logo');
            $ruta = 'marca/'.Str::uuid().'.png';
            ReglasFiscales::exigir(Storage::disk('local')->put($ruta, $png) === true, 'No se pudo guardar el logo.', 'logo');

            return $ruta;
        } finally {
            imagedestroy($imagen);
        }
    }

    /** @param array<string,mixed> $opciones */
    public function apariencia(User $actor, string $version, string $seccion, array $opciones): void
    {
        ConfigurarFacturacion::autorizar($actor);
        abort_unless(in_array($seccion, ['tipografia', 'colores'], true), 404);
        if ($seccion === 'tipografia') {
            $reglas = ['fuente' => ['required', 'in:poppins,inter,roboto'], 'tamano' => ['required', 'integer', 'between:12,18'],
                'documentos' => ['required', 'integer', 'between:8,14'], 'tickets' => ['required', 'integer', 'between:9,16'], 'grafica' => ['required', 'boolean']];
        } else {
            $reglas = ['colores' => ['required', 'array:'.implode(',', array_keys(IdentidadVisual::ESTADOS))]];
            foreach (array_keys(IdentidadVisual::ESTADOS) as $estado) {
                foreach (['texto', 'fondo', 'borde'] as $campo) {
                    $reglas['colores.'.$estado.'.'.$campo] = ['required', 'regex:/^#[a-fA-F0-9]{6}$/'];
                }
            }
        }
        $validado = Validator::make($opciones, $reglas)->validate();
        DB::transaction(function () use ($actor, $version, $validado, $seccion): void {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            ConfigurarFacturacion::autorizar($actor);
            ReglasFiscales::exigir(hash_equals(self::version($empresa), $version), 'La configuración cambió en otra sesión. Recarga antes de guardar.', 'configuracion');
            $empresa->update(['apariencia' => [...($empresa->apariencia ?? []), ...$validado]]);
            activity()->causedBy($actor)->performedOn($empresa)->withProperties(['seccion' => $seccion])->log('Apariencia del sistema actualizada');
        }, 3);
        app(IdentidadVisual::class)->olvidar();
    }
}
