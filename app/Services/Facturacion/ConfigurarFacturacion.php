<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ComprobanteEnvio;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\ResumenDiario;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConfigurarFacturacion
{
    public static function autorizar(User $actor): void
    {
        $actual = User::findOrFail($actor->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('configuracion.sistema');
    }

    public static function version(Empresa $empresa, ConfiguracionFacturacion $config): string
    {
        $e = $empresa->getRawOriginal();
        $c = $config->getRawOriginal();
        ksort($e);
        ksort($c);

        return hash('sha256', serialize([$e, $c]));
    }

    /**
     * @param  array<string,string>  $empresaDatos
     * @param  array<string,string|bool>  $facturacion
     */
    public function guardar(User $actor, string $version, array $empresaDatos, array $facturacion, ?string $pfx): void
    {
        self::autorizar($actor);
        DB::transaction(function () use ($actor, $version, $empresaDatos, $facturacion, $pfx): void {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $empresa->id)->lockForUpdate()->firstOrFail();
            self::autorizar($actor);
            ReglasFiscales::exigir(hash_equals(self::version($empresa, $config), $version), 'La configuración cambió en otra sesión. Recarga la página antes de guardar.');
            ReglasFiscales::exigir(! ComprobanteEnvio::where(fn ($q) => $q->where('estado', 'en_proceso')->orWhere('consultando', true))->exists() && ! ResumenDiario::where('estado', 'en_proceso')->orWhere('consultando', true)->exists(), 'Hay un envío o consulta en proceso. Si se interrumpió, actualiza su estado desde el comprobante antes de cambiar la configuración.');
            $e = Validator::make($empresaDatos, [
                'ruc' => ['required', 'digits:11'], 'razon_social' => ['required', 'string', 'max:200'], 'nombre_comercial' => ['nullable', 'string', 'max:150'],
                'direccion' => ['required', 'string', 'max:250'], 'ubigeo' => ['required', 'digits:6'],
                'departamento' => ['required', 'string', 'max:100'], 'provincia' => ['required', 'string', 'max:100'], 'distrito' => ['required', 'string', 'max:100'],
                'telefono' => ['nullable', 'string', 'max:30'], 'correo' => ['nullable', 'email', 'max:150'],
            ])->validate();
            ReglasFiscales::exigir(ReglasFiscales::ruc($empresaDatos['ruc']), 'El RUC de la empresa no es válido.', 'empresaDatos.ruc');
            ReglasFiscales::exigir($empresa->ruc === null || $empresa->ruc === $e['ruc'] || ! Comprobante::exists(), 'Ya existen comprobantes: no se puede cambiar el RUC emisor.');
            $f = Validator::make($facturacion, [
                'modalidad_boletas_beta' => ['sometimes', 'required', 'in:individual,resumen_diario'],
                'modalidad_boletas_produccion' => ['sometimes', 'required', 'in:individual,resumen_diario'],
                'individual_produccion_confirmada' => ['sometimes', 'required', 'boolean'],
                'habilitada' => ['required', 'boolean'], 'entorno' => ['required', 'in:beta,produccion'],
                'tasa_impuesto' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
                'vigente_desde' => ['nullable', 'date_format:Y-m-d'], 'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
                'usuario_sol' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9]+$/'], 'clave_sol' => ['nullable', 'string', 'max:200'],
                'clave_certificado' => ['nullable', 'string', 'max:200'], 'confirmarProduccion' => ['required', 'boolean'],
            ])->validate();
            $beta = $f['modalidad_boletas_beta'] ?? $config->modalidad_boletas_beta;
            $produccion = $f['modalidad_boletas_produccion'] ?? $config->modalidad_boletas_produccion;
            $autorizada = $f['individual_produccion_confirmada'] ?? $config->individual_produccion_confirmada;
            ReglasFiscales::exigir($produccion !== 'individual' || $autorizada === true, 'Confirma que tu sistema de emisión autorizado permite boletas individuales en producción.');
            ReglasFiscales::exigir(($f['usuario_sol'] ?? '') === '' || ! str_starts_with($f['usuario_sol'], $e['ruc']), 'Ingresa el usuario SOL sin anteponer el RUC.', 'facturacion.usuario_sol');
            $config->fill(['modalidad_boletas_beta' => $beta, 'modalidad_boletas_produccion' => $produccion, 'individual_produccion_confirmada' => $autorizada]);
            if ($f['entorno'] === 'produccion' && ($config->entorno->value !== 'produccion' || (! $config->habilitada && $f['habilitada']))) {
                ReglasFiscales::exigir($f['confirmarProduccion'] === true, 'Confirma que habilitas la emisión real en producción.');
            }
            $cambioEntorno = $f['entorno'] !== $config->entorno->value;
            if ($cambioEntorno) {
                ReglasFiscales::exigir($pfx !== null && $f['usuario_sol'] !== '' && $f['clave_sol'] !== '', 'Al cambiar de entorno carga el certificado y las credenciales correspondientes.');
                $config->fill(['usuario_sol' => null, 'clave_sol' => null, 'clave_certificado' => null, 'certificado_ruta' => null]);
            }
            $empresa->fill($e)->save();
            $config->fill(['habilitada' => $f['habilitada'], 'entorno' => $f['entorno'], 'tasa_impuesto' => $f['tasa_impuesto'] ?: null, 'vigente_desde' => $f['vigente_desde'] ?: null, 'vigente_hasta' => $f['vigente_hasta'] ?: null]);
            foreach (['usuario_sol', 'clave_sol', 'clave_certificado'] as $secreto) {
                if ($f[$secreto] !== '') {
                    $config->$secreto = $f[$secreto];
                }
            }
            if ($pfx !== null) {
                app(CertificadoFiscal::class)->pem($pfx, (string) $f['clave_certificado']);
                $ruta = 'certificados/'.Str::uuid().'.enc';
                ReglasFiscales::exigir(Storage::disk('local')->put($ruta, Crypt::encryptString($pfx)) === true, 'No se pudo guardar el certificado.');
                $config->certificado_ruta = $ruta;
                $config->clave_certificado = (string) $f['clave_certificado'];
            }
            if ($config->habilitada) {
                EmitirComprobante::configurada($empresa, $config, CarbonImmutable::now('America/Lima'));
                app(CertificadoFiscal::class)->cargar($config);
            }
            $config->save();
            activity()->causedBy($actor)->performedOn($empresa)->withProperties(['entorno' => $config->entorno->value])->log('Configuración fiscal actualizada');
        }, 3);
    }

    public function crearSerie(User $actor, string $entorno, string $tipo, string $serie, string $ultimo): void
    {
        self::autorizar($actor);
        Validator::make(compact('entorno', 'tipo', 'serie', 'ultimo'), ['entorno' => ['required', 'in:beta,produccion'], 'tipo' => ['required', 'in:01,03'], 'serie' => ['required', 'regex:'.($tipo === '01' ? '/^F[A-Z0-9]{3}$/' : '/^B[A-Z0-9]{3}$/')], 'ultimo' => ['required', 'integer', 'min:0', 'max:99999998']])->validate();
        DB::transaction(function () use ($actor, $entorno, $tipo, $serie, $ultimo): void {
            Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            self::autorizar($actor);
            $tipoId = TipoComprobante::where('codigo', $tipo)->firstOrFail()->id;
            Validator::make(['serie' => $serie], ['serie' => [Rule::unique('series_comprobante')->where('tipo_comprobante_id', $tipoId)->where('entorno', $entorno)]])->validate();
            $registro = SerieComprobante::create(['tipo_comprobante_id' => $tipoId, 'entorno' => $entorno, 'serie' => $serie, 'ultimo_correlativo' => (int) $ultimo, 'activo' => false]);
            activity()->causedBy($actor)->performedOn($registro)->log('Serie fiscal creada inactiva');
        }, 3);
    }

    public function estadoSerie(User $actor, int $id, bool $activo): void
    {
        self::autorizar($actor);
        DB::transaction(function () use ($actor, $id, $activo): void {
            Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $serie = SerieComprobante::lockForUpdate()->findOrFail($id);
            self::autorizar($actor);
            ReglasFiscales::exigir(in_array($serie->tipoComprobante->codigo, ['01', '03'], true), 'Esta pantalla administra series de boletas y facturas.');
            $serie->update(['activo' => $activo]);
            activity()->causedBy($actor)->performedOn($serie)->withProperties(['activo' => $activo])->log('Estado de serie fiscal actualizado');
        }, 3);
    }
}
