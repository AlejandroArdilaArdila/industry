<?php

namespace App\Services\TicketTemplate;

use App\Models\PlantillaTicket;
use App\Models\PlantillaTicketSeccion;
use App\Models\PlantillaTicketVariable;
use Illuminate\Http\Request;

class TicketTemplateService
{
    /**
     * Plantilla de tirilla por rowid o por nombre. Si $id es 0/null/"null",
     * devuelve todas las plantillas.
     */
    public function plantillaTicketPorIdONombre(?string $id): array
    {
        if ((string) $id === '0' || strtolower((string) $id) === 'null' || $id === null) {
            return PlantillaTicket::query()
                ->orderBy('rowid')
                ->get(['rowid', 'nombre', 'label', 'config', 'activo'])
                ->map(fn ($p) => [
                    'rowid' => (int) $p->rowid,
                    'nombre' => $p->nombre,
                    'label' => $p->label,
                    'config' => json_decode((string) $p->config, true) ?? [],
                    'activo' => (int) $p->activo,
                ])
                ->values()
                ->all();
        }

        $plantilla = ctype_digit((string) $id)
            ? PlantillaTicket::query()->where('rowid', (int) $id)
            : PlantillaTicket::query()->where('nombre', trim($id));

        $plantilla = $plantilla->first(['rowid', 'nombre', 'label', 'config', 'activo']);

        if (!$plantilla) {
            abort(401, 'Plantilla no encontrada');
        }

        return [
            'rowid' => (int) $plantilla->rowid,
            'nombre' => $plantilla->nombre,
            'label' => $plantilla->label,
            'config' => json_decode((string) $plantilla->config, true) ?? [],
            'activo' => (int) $plantilla->activo,
        ];
    }

    /**
     * Lista todos los tipos de ticket disponibles.
     */
    public function tiposTicket(): array
    {
        $tipos = PlantillaTicket::query()
            ->orderBy('rowid')
            ->get(['rowid', 'nombre', 'label', 'activo'])
            ->map(fn ($p) => [
                'rowid' => (int) $p->rowid,
                'nombre' => $p->nombre,
                'label' => $p->label,
                'activo' => (int) $p->activo,
            ])
            ->values()
            ->all();

        return ['tipos' => $tipos];
    }

    /**
     * Configuracion completa de un tipo de ticket, buscado por nombre.
     */
    public function configuracionTicket(Request $request): array
    {
        $nombre = trim((string) $request->input('nombre', ''));

        if ($nombre === '') {
            abort(401, 'nombre requerido');
        }

        $plantilla = PlantillaTicket::query()
            ->where('nombre', $nombre)
            ->first(['rowid', 'nombre', 'label', 'config', 'dato_preview', 'columnas', 'variables']);

        if (!$plantilla) {
            abort(401, 'Tipo no encontrado');
        }

        return [
            'rowid' => (int) $plantilla->rowid,
            'nombre' => $plantilla->nombre,
            'label' => $plantilla->label,
            'config' => json_decode((string) $plantilla->config, true) ?? [],
            'dato_preview' => $plantilla->dato_preview ? json_decode($plantilla->dato_preview, true) : (object) [],
            'columnas' => (int) ($plantilla->columnas ?: 80),
            'variables' => $plantilla->variables ? json_decode($plantilla->variables, true) : [],
        ];
    }

    /**
     * Lista todas las variables globales de campo para tirillas.
     */
    public function variablesTicket(): array
    {
        $variables = PlantillaTicketVariable::query()
            ->orderBy('rowid')
            ->get(['rowid', 'nombre', 'descripcion', 'tipo'])
            ->map(fn ($v) => [
                'rowid' => (int) $v->rowid,
                'nombre' => $v->nombre,
                'descripcion' => $v->descripcion,
                'tipo' => $v->tipo ?: 'string',
            ])
            ->values()
            ->all();

        return ['variables' => $variables];
    }

    /**
     * Lista todas las secciones reutilizables de tirillas.
     */
    public function seccionesTicket(): array
    {
        $secciones = PlantillaTicketSeccion::query()
            ->orderBy('rowid')
            ->get(['rowid', 'nombre', 'descripcion', 'elementos'])
            ->map(function ($seccion) {
                $bruto = json_decode((string) $seccion->elementos, true) ?? [];

                // Formato nuevo: objeto con "items"; formato viejo: array plano.
                if (isset($bruto['items'])) {
                    $elementos = $bruto['items'] ?? [];
                    $condicional = $bruto['condicional'] ?? null;
                    $repeat = $bruto['repeat'] ?? null;
                } else {
                    $elementos = $bruto;
                    $condicional = null;
                    $repeat = null;
                }

                return [
                    'rowid' => (int) $seccion->rowid,
                    'nombre' => $seccion->nombre,
                    'descripcion' => $seccion->descripcion,
                    'elementos' => $elementos,
                    'condicional' => $condicional,
                    'repeat' => $repeat,
                ];
            })
            ->values()
            ->all();

        return ['secciones' => $secciones];
    }
}
