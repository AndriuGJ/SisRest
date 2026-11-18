<?php

namespace App\Concerns;

use App\Models\Articulo;
use Illuminate\Support\Str;

trait ConPresentaciones
{
    /** @var array<string, array{id: string, nombre: string, factor: string, activo: bool}> */
    public array $presentaciones = [];

    abstract protected function autorizarFormulario(): void;

    protected function cargarPresentaciones(?Articulo $articulo): void
    {
        $this->presentaciones = [];
        foreach ($articulo->presentaciones ?? [] as $fila) {
            $this->presentaciones['p-'.$fila->id] = ['id' => (string) $fila->id, 'nombre' => $fila->nombre, 'factor' => $fila->factor_conversion, 'activo' => $fila->activo];
        }
    }

    public function addPresentation(): void
    {
        $this->autorizarFormulario();
        if (count($this->presentaciones) < 20) {
            $this->presentaciones['n-'.Str::uuid()] = ['id' => '', 'nombre' => '', 'factor' => '1', 'activo' => true];
        }
    }

    public function removePresentation(string $clave): void
    {
        $this->autorizarFormulario();
        if (isset($this->presentaciones[$clave])) {
            $this->validate(['presentaciones.'.$clave.'.id' => ['present', 'nullable', 'integer']]);
            if ($this->presentaciones[$clave]['id'] !== '') {
                $this->presentaciones[$clave]['activo'] = false;
            } else {
                unset($this->presentaciones[$clave]);
            }
        }
    }
}
