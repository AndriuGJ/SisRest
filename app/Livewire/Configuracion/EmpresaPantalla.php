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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Empresa')]
class EmpresaPantalla extends Component
{
    use ConAlertas;
    use WithFileUploads;

    /** @var array<string,string> */
    public array $datos = [];

    public ?TemporaryUploadedFile $logo = null;

    public bool $quitarLogo = false;

    #[Locked]
    public string $version = '';

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        ConfigurarFacturacion::autorizar($actor);

        return $actor;
    }

    public function mount(): void
    {
        $this->actor();
        $empresa = Empresa::where('singleton', 1)->firstOrFail();
        foreach (['ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'telefono', 'correo'] as $campo) {
            $this->datos[$campo] = (string) ($empresa->$campo ?? '');
        }
        $this->version = GuardarIdentidad::version($empresa);
    }

    public function guardar(): void
    {
        $actor = $this->actor();
        $this->validate(['logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000']]);
        $bytes = $this->logo?->get();
        app(GuardarIdentidad::class)->empresa($actor, $this->version, $this->datos, is_string($bytes) ? $bytes : null, $this->quitarLogo);
        $this->logo = null;
        $this->quitarLogo = false;
        Alertas::flash('Empresa y logo actualizados.');
        $this->redirectRoute('configuracion.empresa', navigate: true);
    }

    public function render(): View
    {
        $this->actor();

        return view('livewire.configuracion.empresa', ['logoActual' => app(IdentidadVisual::class)->logoUrl()]);
    }
}
