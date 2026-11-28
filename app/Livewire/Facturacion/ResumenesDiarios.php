<?php

namespace App\Livewire\Facturacion;

use App\Concerns\ConAlertas;
use App\Models\ConfiguracionFacturacion;
use App\Models\User;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\AccesoResumenes;
use App\Services\Facturacion\GestionResumenes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Resúmenes diarios')]
class ResumenesDiarios extends Component
{
    use ConAlertas, WithPagination;

    public string $desde = '';

    public string $hasta = '';

    public string $entorno = '';

    public string $estado = '';

    public string $fechaBoletas = '';

    public string $entornoBoletas = 'beta';

    /** @var list<string> */
    public array $seleccion = [];

    public bool $formulario = false;

    #[Locked]
    public string $clave;

    /** @var list<int> */
    #[Locked]
    public array $revisadas = [];

    public function mount(): void
    {
        AccesoFacturacion::usuario($this->actor());
        $this->clave = (string) Str::uuid();
        $this->fechaBoletas = now('America/Lima')->toDateString();
        $this->entornoBoletas = ConfiguracionFacturacion::first()?->entorno->value ?? 'beta';
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['desde', 'hasta', 'estado', 'entorno'], true)) {
            $this->resetPage();
        }
        if (in_array($campo, ['fechaBoletas', 'entornoBoletas'], true)) {
            $this->seleccion = [];
            $this->revisadas = [];
        }
    }

    public function revisarGeneracion(): void
    {
        AccesoFacturacion::usuario($this->actor(), 'comprobantes.emitir');
        $this->validate(['fechaBoletas' => 'required|date_format:Y-m-d', 'entornoBoletas' => 'required|in:beta,produccion', 'seleccion' => 'required|array|min:1|max:500', 'seleccion.*' => 'integer|min:1|distinct']);
        $this->revisadas = array_map(intval(...), $this->seleccion);
        $this->confirmar('Generar resumen diario', 'Se reservarán y firmarán las boletas seleccionadas. Esta acción no envía a SUNAT.', 'generar', 'Generar y firmar');
    }

    public function generar(): void
    {
        abort_unless($this->revisadas !== [], 403);
        $r = app(GestionResumenes::class)->generar($this->actor(), $this->revisadas, $this->clave);
        $this->redirectRoute('resumenes.ver', $r, navigate: true);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $base = AccesoResumenes::consulta($actor);
        $indicadores = [];
        foreach (['pendiente', 'enviado', 'aceptado', 'observado', 'resultado_incierto'] as $valor) {
            $indicadores[$valor] = (clone $base)->where('estado', $valor)->count();
        }
        $resumenes = $base->withCount('detalles')->when($this->desde !== '', fn ($q) => $q->whereDate('fecha_emision', '>=', $this->desde))
            ->when($this->hasta !== '', fn ($q) => $q->whereDate('fecha_emision', '<=', $this->hasta))
            ->when($this->entorno !== '', fn ($q) => $q->where('entorno', $this->entorno))->when($this->estado !== '', fn ($q) => $q->where('estado', $this->estado))->latest('id')->paginate(15);
        $boletas = $this->formulario ? app(GestionResumenes::class)->elegibles($actor)->whereDate('fecha_emision', $this->fechaBoletas)->where('entorno', $this->entornoBoletas)->orderBy('id')->limit(500)->get() : collect();

        return view('livewire.facturacion.resumenes', compact('resumenes', 'boletas', 'indicadores'));
    }
}
