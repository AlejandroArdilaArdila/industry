<?php

namespace App\Services\Grids;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve valores de extrafields (select/sellist) para grids Syncfusion, y
 * decodifica los ids de filtro (categoría, grupo, etc.) que el front-end
 * manda como JSON string. Compartido por cualquier grid con extrafields
 * (Terceros, Usuarios, ...), antes vivía duplicado en cada controller.
 */
class ExtrafieldResolver
{
    /**
     * El front-end manda estos ids como JSON string dentro del payload
     * (ej. currentIdsCatClient = "[1,2,3]"), igual que el legado.
     *
     * @return array<int, int>
     */
    public function decodeIdList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Resuelve el valor mostrable de un extrafield select/sellist (misma
     * idea que Dolibarr's showOutputField, pero resuelto server-side una
     * sola vez en vez de duplicar la lógica en JS).
     */
    public function resolveExtrafieldValue(object $def, mixed $value, array &$cache): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $param = $this->decodeParam($def->param);
        $options = $param['options'] ?? [];

        if ($def->type === 'select') {
            return $options[$value] ?? $value;
        }

        if ($def->type === 'sellist') {
            return $this->resolveSellistLabel($options, $value, $cache);
        }

        return $value;
    }

    private function decodeParam(?string $param): array
    {
        if (!$param) {
            return [];
        }

        $decoded = @unserialize($param);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveSellistLabel(array $options, mixed $value, array &$cache): mixed
    {
        $spec = array_key_first($options);
        if (!$spec) {
            return $value;
        }

        [$table, $labelField, $keyField] = array_pad(explode(':', $spec), 3, null);
        if (!$table || !$labelField || !$keyField) {
            return $value;
        }

        $cacheKey = "$table:$labelField:$keyField:$value";
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $label = DB::connection('mysql')->table($table)->where($keyField, $value)->value($labelField);

        return $cache[$cacheKey] = $label ?? $value;
    }
}
