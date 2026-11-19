<?php

namespace App\Services\Configuracion;

use App\Models\Empresa;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class IdentidadVisual
{
    public const FUENTES = ['poppins' => 'Poppins', 'inter' => 'Inter', 'roboto' => 'Roboto'];

    public const ESTADOS = [
        'activo' => ['Activo', '#166534', '#dcfce7', '#86efac'],
        'inactivo' => ['Inactivo', '#52525b', '#f4f4f5', '#d4d4d8'],
        'borrador' => ['Borrador', '#854d0e', '#fef9c3', '#fde047'],
        'abierto' => ['Abierto', '#075985', '#e0f2fe', '#7dd3fc'],
        'pendiente' => ['Pendiente', '#92400e', '#fef3c7', '#fcd34d'],
        'confirmado' => ['Confirmado', '#166534', '#dcfce7', '#86efac'],
        'en_preparacion' => ['En preparación', '#9a3412', '#ffedd5', '#fdba74'],
        'listo' => ['Listo', '#065f46', '#d1fae5', '#6ee7b7'],
        'en_camino' => ['En camino', '#1e40af', '#dbeafe', '#93c5fd'],
        'cancelado' => ['Cancelado', '#991b1b', '#fee2e2', '#fca5a5'],
        'entregado' => ['Entregado', '#155e75', '#cffafe', '#67e8f9'],
        'cerrado' => ['Cerrado', '#3730a3', '#e0e7ff', '#a5b4fc'],
        'anulado' => ['Anulado', '#991b1b', '#fee2e2', '#fca5a5'],
        'aceptado' => ['Aceptado', '#166534', '#dcfce7', '#86efac'],
        'observado' => ['Observado', '#92400e', '#fef3c7', '#fcd34d'],
        'rechazado' => ['Rechazado', '#991b1b', '#fee2e2', '#fca5a5'],
        'enviado' => ['Enviado', '#1e40af', '#dbeafe', '#93c5fd'],
        'desconocido' => ['Resultado incierto', '#92400e', '#fef3c7', '#fcd34d'],
        'parcial' => ['Parcial', '#854d0e', '#fef9c3', '#fde047'],
        'agotado' => ['Agotado', '#991b1b', '#fee2e2', '#fca5a5'],
        'mesa_libre' => ['Mesa libre', '#166534', '#f0fdf4', '#86efac'],
        'mesa_ocupada' => ['Mesa ocupada', '#92400e', '#fffbeb', '#fcd34d'],
    ];

    private bool $cargada = false;

    private ?Empresa $registro = null;

    public function empresa(): ?Empresa
    {
        if (! $this->cargada) {
            $this->registro = Schema::hasTable('empresa') ? Empresa::where('singleton', 1)->first() : null;
            $this->cargada = true;
        }

        return $this->registro;
    }

    public function olvidar(): void
    {
        $this->cargada = false;
        $this->registro = null;
    }

    public function nombre(): string
    {
        return $this->empresa()?->nombre_comercial ?: ($this->empresa()?->razon_social ?: config('app.name', 'SisRest'));
    }

    /** @return array{fuente:string,tamano:int,documentos:int,tickets:int,grafica:bool} */
    public function tipografia(): array
    {
        $a = $this->empresa()->apariencia ?? [];
        $fuente = $a['fuente'] ?? 'poppins';

        return ['fuente' => is_string($fuente) && isset(self::FUENTES[$fuente]) ? $fuente : 'poppins',
            'tamano' => $this->entero($a['tamano'] ?? 14, 12, 18), 'documentos' => $this->entero($a['documentos'] ?? 10, 8, 14),
            'tickets' => $this->entero($a['tickets'] ?? 12, 9, 16), 'grafica' => ($a['grafica'] ?? false) === true];
    }

    private function entero(mixed $valor, int $min, int $max): int
    {
        return is_numeric($valor) ? max($min, min($max, (int) $valor)) : $min;
    }

    /** @return array<string,array{texto:string,fondo:string,borde:string}> */
    public function colores(): array
    {
        $guardados = $this->empresa()?->apariencia['colores'] ?? [];
        $colores = [];
        foreach (self::ESTADOS as $clave => $valores) {
            foreach (['texto' => 1, 'fondo' => 2, 'borde' => 3] as $campo => $indice) {
                $valor = is_array($guardados) ? ($guardados[$clave][$campo] ?? null) : null;
                $colores[$clave][$campo] = is_string($valor) && preg_match('/^#[a-fA-F0-9]{6}$/', $valor) ? $valor : $valores[$indice];
            }
        }

        return $colores;
    }

    public function estiloEstado(string $estado): string
    {
        $estado = match ($estado) {
            'activa', 'abierta' => $estado === 'activa' ? 'activo' : 'abierto',
            'inactiva' => 'inactivo', 'confirmada' => 'confirmado', 'anulada' => 'anulado',
            'cerrada' => 'cerrado', 'preparando' => 'en_preparacion', 'resultado_incierto' => 'desconocido',
            'procesando', 'en_proceso' => 'en_preparacion', 'error' => 'rechazado', default => $estado,
        };
        $c = $this->colores()[$estado] ?? $this->colores()['inactivo'];

        return 'color:'.$c['texto'].';background-color:'.$c['fondo'].';border-color:'.$c['borde'].';';
    }

    public function logoUrl(): ?string
    {
        $ruta = $this->empresa()?->logo_ruta;

        return $ruta && Storage::disk('local')->exists($ruta) ? route('marca.logo', ['archivo' => basename($ruta)]) : null;
    }

    public function logoDatos(?string $ruta): ?string
    {
        return $ruta && str_starts_with($ruta, 'marca/') && Storage::disk('local')->exists($ruta)
            ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($ruta)) : null;
    }

    public function fuenteArchivo(string $fuente, int $peso = 400): string
    {
        return public_path('fonts/'.(isset(self::FUENTES[$fuente]) ? $fuente : 'poppins').'-'.($peso === 600 ? 600 : 400).'.ttf');
    }
}
