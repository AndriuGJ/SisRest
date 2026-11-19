<?php

namespace App\Livewire\Facturacion;

use App\Concerns\ConAlertas;
use App\Models\Cobro;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\SerieComprobante;
use App\Models\User;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\CalculoTributario;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Services\Facturacion\EmitirComprobante;
use App\Services\Facturacion\ReglasFiscales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Emitir comprobante')]
class EmisionCobro extends Component
{
    use ConAlertas;

    #[Locked]
    public int $cobroId;

    #[Locked]
    public string $clave;

    #[Locked]
    public string $revision = '';

    #[Locked]
    public string $revisionFiscal = '';

    public string $serieId = '';

    public string $tipo = '0';

    public string $numero = '';

    public string $nombre = 'CLIENTE VARIOS';

    public string $direccion = '';

    /** @var array<int|string,string> */
    public array $afectaciones = [];

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function cobro(): Cobro
    {
        $cobro = Cobro::with(['detalles', 'comprobante' => fn ($q) => $q->where('entorno', ConfiguracionFacturacion::firstOrFail()->entorno->value)])->findOrFail($this->cobroId);
        AccesoFacturacion::cobro($this->actor(), $cobro, 'comprobantes.emitir');

        return $cobro;
    }

    public function mount(Cobro $cobro): void
    {
        $this->cobroId = $cobro->id;
        $this->clave = (string) Str::uuid();
        $cobro = $this->cobro();
        if ($cobro->comprobante) {
            $this->redirectRoute('comprobantes.ver', $cobro->comprobante, navigate: true);

            return;
        }
        $this->nombre = $cobro->cliente_nombre ?: 'CLIENTE VARIOS';
        $this->numero = $cobro->cliente_numero_documento ?? '';
        $this->tipo = strlen($this->numero) === 11 ? '6' : (strlen($this->numero) === 8 ? '1' : '0');
        $this->direccion = $cobro->cliente_direccion ?? '';
        $config = ConfiguracionFacturacion::firstOrFail();
        $this->serieId = (string) (SerieComprobante::where('activo', true)->where('entorno', $config->entorno->value)->whereHas('tipoComprobante', fn ($q) => $q->where('codigo', '03')->where('activo', true))->first()->id ?? '');
        foreach ($cobro->detalles as $detalle) {
            if (bccomp($detalle->subtotal, '0', 2) > 0) {
                $this->afectaciones[$detalle->id] = bccomp($detalle->total, '0', 2) > 0 ? '10' : '';
            }
        }
        if (bccomp($cobro->cargo_envio, '0', 2) > 0) {
            $this->afectaciones['envio'] = '10';
        }
    }

    private function huella(): string
    {
        return hash('sha256', serialize([$this->serieId, $this->tipo, $this->numero, $this->nombre, $this->direccion, $this->afectaciones]));
    }

    public function solicitarEmision(): void
    {
        $cobro = $this->cobro();
        $this->validate(['serieId' => ['required', 'integer', 'exists:series_comprobante,id'], 'tipo' => ['required', 'in:0,1,6'], 'nombre' => ['required', 'max:200'], 'numero' => ['nullable', 'max:11'], 'direccion' => ['nullable', 'max:250'], 'afectaciones' => ['required', 'array'], 'afectaciones.*' => ['string']]);
        $config = ConfiguracionFacturacion::firstOrFail();
        ReglasFiscales::exigir($config->tasa_impuesto !== null, 'Configura la tasa tributaria antes de emitir.');
        app(CalculoTributario::class)->calcular($cobro, $config->tasa_impuesto, $this->afectaciones);
        $serie = SerieComprobante::with('tipoComprobante')->findOrFail($this->serieId);
        $this->revisionFiscal = ConfigurarFacturacion::version(Empresa::firstOrFail(), $config);
        $this->revision = $this->huella();
        $this->confirmar('Emitir '.$serie->tipoComprobante->nombre, mb_strtoupper($config->entorno->value).' · '.$serie->serie.' · S/ '.$cobro->total.' · '.$this->nombre.'. Se asignará un número definitivo y se firmará el documento.', 'emitir', 'Emitir comprobante');
    }

    public function emitir(): void
    {
        $this->cobro();
        ReglasFiscales::exigir($this->revision !== '' && hash_equals($this->revision, $this->huella()), 'Revisa y confirma nuevamente los datos antes de emitir.');
        $c = app(EmitirComprobante::class)->emitir($this->actor(), $this->cobroId, $this->clave, ['serieId' => (int) $this->serieId, 'tipo' => $this->tipo, 'numero' => $this->numero, 'nombre' => $this->nombre, 'direccion' => $this->direccion], $this->afectaciones, $this->revisionFiscal);
        $this->redirectRoute('comprobantes.ver', $c, navigate: true);
    }

    public function render(): View
    {
        $config = ConfiguracionFacturacion::firstOrFail();

        return view('livewire.facturacion.emision', ['cobro' => $this->cobro(), 'configuracion' => $config, 'series' => SerieComprobante::with('tipoComprobante')->where('activo', true)->where('entorno', $config->entorno->value)->whereHas('tipoComprobante', fn ($q) => $q->whereIn('codigo', ['01', '03'])->where('activo', true))->orderBy('serie')->get(), 'opciones' => ReglasFiscales::AFECTACIONES]);
    }
}
