<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Services\Dashboard\EstadisticasDashboard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class PanelDashboard extends Component
{
    public function render(EstadisticasDashboard $estadisticas): View
    {
        $actor = Auth::user();

        abort_unless(
            $actor instanceof User && $actor->activo,
            403
        );

        return view(
            'livewire.dashboard.panel-dashboard',
            $estadisticas->obtener($actor)
        );
    }
}
