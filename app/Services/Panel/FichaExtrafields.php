<?php

namespace App\Services\Panel;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extrafields de Dolibarr para los modales de ficha del panel (terceros,
 * productos…): definiciones visibles, opciones de select/sellist, valores
 * actuales, reglas de obligatorios y guardado por grupo.
 *
 * Sigue la semántica de "visibilidad" (columna list) de Dolibarr: 0 = nunca,
 * 2 = solo listado (no van al formulario), 5 = solo lectura; los negativos
 * solo cambian la visibilidad por defecto en listados.
 */
class FichaExtrafields
{
    /** @var array<string, Collection> */
    private array $cache = [];

    public function visibles(string $elementtype): Collection
    {
        return $this->cache[$elementtype] ??= DB::connection('mysql')->table('extrafields')
            ->where('elementtype', $elementtype)
            ->where('enabled', '<>', '0')
            ->orderBy('pos')
            ->orderBy('rowid')
            ->get(['name', 'label', 'type', 'param', 'list', 'fieldrequired', 'help', 'size'])
            ->filter(fn ($def) => !in_array(abs((int) $def->list), [0, 2], true))
            ->values();
    }

    /**
     * Definiciones visibles con su valor actual (->valor) y opciones (->opciones).
     */
    public function conValores(string $elementtype, string $tabla, ?int $fkObject): Collection
    {
        $valores = $fkObject
            ? (array) (DB::connection('mysql')->table($tabla)->where('fk_object', $fkObject)->first() ?? [])
            : [];

        return $this->visibles($elementtype)->map(function ($def) use ($valores) {
            $def = clone $def;
            $def->valor = $valores[$def->name] ?? null;
            $def->opciones = in_array($def->type, ['select', 'sellist'], true) ? $this->opciones($def) : [];

            return $def;
        });
    }

    /**
     * Reglas "required" de los extrafields obligatorios que pasan el filtro.
     *
     * @param  callable(object): bool  $incluir
     * @return array<string, array<int, string>>
     */
    public function reglas(string $elementtype, callable $incluir): array
    {
        $reglas = [];
        foreach ($this->visibles($elementtype) as $def) {
            if ((int) $def->fieldrequired === 1 && $def->type !== 'boolean' && (int) $def->list !== 5 && $incluir($def)) {
                $reglas['ef.'.$def->name] = ['required'];
            }
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function atributos(string $elementtype): array
    {
        return $this->visibles($elementtype)
            ->mapWithKeys(fn ($def) => ['ef.'.$def->name => mb_strtolower($def->label)])
            ->all();
    }

    /**
     * Guarda los extrafields enviados que pasan el filtro (los demás pasos no
     * se tocan: clave para no poner en 0 booleanos de otro paso).
     *
     * @param  array<string, mixed>  $valores
     * @param  callable(object): bool  $incluir
     * @param  array<string, mixed>  $forzados  columnas que se escriben siempre (p. ej. calculadas)
     */
    public function guardar(string $elementtype, string $tabla, int $fkObject, array $valores, callable $incluir, array $forzados = []): void
    {
        $datos = [];

        foreach ($this->visibles($elementtype) as $def) {
            if ((int) $def->list === 5 || !$incluir($def)) {
                continue; // solo lectura, o pertenece a otro paso
            }

            if (!array_key_exists($def->name, $valores)) {
                if ($def->type === 'boolean') {
                    $datos[$def->name] = 0;
                }
                continue;
            }

            $valor = $valores[$def->name];
            $vacio = $valor === '' || $valor === null;
            $datos[$def->name] = match ($def->type) {
                'boolean' => $valor ? 1 : 0,
                'int' => $vacio ? null : (int) $valor,
                'double', 'price', 'pricecy' => $vacio ? null : (float) $valor,
                default => $vacio ? null : $valor,
            };
        }

        $datos = $forzados + $datos;
        if (empty($datos)) {
            return;
        }

        DB::connection('mysql')->table($tabla)->updateOrInsert(['fk_object' => $fkObject], $datos);
    }

    /**
     * @return array<int|string, string>
     */
    public function opciones(object $def): array
    {
        $param = @unserialize((string) $def->param);
        $options = is_array($param) ? ($param['options'] ?? []) : [];

        if (empty($options)) {
            return [];
        }

        if ($def->type === 'select') {
            return $options;
        }

        // sellist: "tabla:campo_label:campo_key[::filtro]". Solo el filtro (col:=:'valor').
        $partes = explode('::', (string) array_key_first($options), 2);
        [$tabla, $labelField, $keyField] = array_pad(explode(':', $partes[0]), 3, null);
        if (!$tabla || !$labelField || !$keyField) {
            return [];
        }

        $query = DB::connection('mysql')->table($tabla);
        if (isset($partes[1]) && preg_match("/\(([a-zA-Z0-9_]+):=:'([^']*)'\)/", $partes[1], $m)) {
            $query->where($m[1], $m[2]);
        }
        if (Schema::connection('mysql')->hasColumn($tabla, 'active')) {
            $query->where('active', 1);
        }

        return $query->orderBy($labelField)->pluck($labelField, $keyField)->all();
    }
}
