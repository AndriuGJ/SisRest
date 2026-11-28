<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Configuracion\GuardarIdentidad;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Apariencia')]
class AparienciaPantalla extends Component
{
    use ConAlertas;

    #[Locked]
    public string $seccion = 'tipografia';

    #[Locked]
    public string $version = '';

    /** @var array<string,mixed> */
    public array $opciones = [];

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        ConfigurarFacturacion::autorizar($actor);

        return $actor;
    }

    public function mount(string $seccion = 'tipografia'): void
    {
        $this->actor();
        abort_unless(in_array($seccion, ['tipografia', 'colores'], true), 404);
        $this->seccion = $seccion;
        $visual = app(IdentidadVisual::class);
        $this->opciones = $seccion === 'tipografia' ? $visual->tipografia() : ['colores' => $visual->colores()];
        $this->version = GuardarIdentidad::version(Empresa::where('singleton', 1)->firstOrFail());
    }

    public function restaurar(): void
    {
        $this->actor();
        if ($this->seccion === 'tipografia') {
            $this->opciones = ['fuente' => 'poppins', 'tamano' => 14, 'documentos' => 10, 'tickets' => 12, 'grafica' => false];
        } else {
            foreach (IdentidadVisual::ESTADOS as $clave => $valores) {
                $this->opciones['colores'][$clave] = ['texto' => $valores[1], 'fondo' => $valores[2], 'borde' => $valores[3]];
            }
        }
    }

    public function guardar(): void
    {
        app(GuardarIdentidad::class)->apariencia($this->actor(), $this->version, $this->seccion, $this->opciones);
        Alertas::flash('Configuración visual guardada.');
        $this->redirectRoute('configuracion.'.$this->seccion, navigate: true);
    }

    public function render(): View
    {
        $this->actor();

        return view('livewire.configuracion.apariencia', ['fuentes' => IdentidadVisual::FUENTES, 'estados' => IdentidadVisual::ESTADOS]);
    }
}
