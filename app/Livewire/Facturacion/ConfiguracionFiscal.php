<?php

namespace App\Livewire\Facturacion;

use App\Concerns\ConAlertas;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\SerieComprobante;
use App\Models\User;
use App\Services\Facturacion\ConfigurarFacturacion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Facturación')]
class ConfiguracionFiscal extends Component
{
    use ConAlertas;
    use WithFileUploads;

    /** @var array<string,string> */
    public array $empresaDatos = [];

    /** @var array<string,string|bool> */
    public array $facturacion = [];

    #[Locked]
    public string $version = '';

    public ?TemporaryUploadedFile $certificado = null;

    public string $serieEntorno = 'beta';

    public string $serieTipo = '03';

    public string $serieNombre = '';

    public string $serieUltimo = '0';

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        ConfigurarFacturacion::autorizar($actor);

        return $actor;
    }

    public function mount(): void
    {
        $this->cargar();
    }

    private function cargar(): void
    {
        $this->actor();
        $e = Empresa::where('singleton', 1)->firstOrFail();
        $c = ConfiguracionFacturacion::where('empresa_id', $e->id)->firstOrFail();
        foreach (['ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'telefono', 'correo'] as $campo) {
            $this->empresaDatos[$campo] = (string) ($e->$campo ?? '');
        }
        $this->facturacion = ['habilitada' => $c->habilitada, 'entorno' => $c->entorno->value, 'tasa_impuesto' => $c->tasa_impuesto ?? '', 'vigente_desde' => $c->vigente_desde?->toDateString() ?? '', 'vigente_hasta' => $c->vigente_hasta?->toDateString() ?? '', 'usuario_sol' => '', 'clave_sol' => '', 'clave_certificado' => '', 'confirmarProduccion' => false];
        $this->version = ConfigurarFacturacion::version($e, $c);
        $this->certificado = null;
    }

    public function guardar(): void
    {
        $actor = $this->actor();
        $this->validate(['certificado' => ['nullable', 'file', 'max:1024']]);
        $pfx = $this->certificado?->get();
        try {
            app(ConfigurarFacturacion::class)->guardar($actor, $this->version, $this->empresaDatos, $this->facturacion, is_string($pfx) ? $pfx : null);
        } finally {
            // Never persist credentials in subsequent Livewire snapshots or error renders.
            foreach (['usuario_sol', 'clave_sol', 'clave_certificado'] as $campo) {
                $this->facturacion[$campo] = '';
            }
        }
        $this->cargar();
        $this->notificar('Configuración guardada.');
    }

    public function crearSerie(): void
    {
        app(ConfigurarFacturacion::class)->crearSerie($this->actor(), $this->serieEntorno, $this->serieTipo, $this->serieNombre, $this->serieUltimo);
        $this->serieNombre = '';
        $this->serieUltimo = '0';
        $this->notificar('Serie creada. Actívala cuando corresponda.');
    }

    public function estadoSerie(int $id, bool $activo): void
    {
        app(ConfigurarFacturacion::class)->estadoSerie($this->actor(), $id, $activo);
        $this->notificar('Estado de la serie actualizado.');
    }

    public function render(): View
    {
        $this->actor();

        return view('livewire.facturacion.configuracion', ['configuracion' => ConfiguracionFacturacion::firstOrFail(), 'series' => SerieComprobante::with('tipoComprobante')->whereHas('tipoComprobante', fn ($q) => $q->whereIn('codigo', ['01', '03']))->orderBy('entorno')->orderBy('serie')->get()]);
    }
}
