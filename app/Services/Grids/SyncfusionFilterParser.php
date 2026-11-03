<?php

namespace App\Services\Grids;

/**
 * Convierte el árbol de filtros que envía un DataManager de Syncfusion
 * (FilterBar) a un fragmento SQL parametrizado. Reutilizable por cualquier
 * grid que use el mismo formato de "where" (predicados planos o agrupados
 * con isComplex/predicates).
 */
class SyncfusionFilterParser
{
    /**
     * @param  array<int, array<string, mixed>>  $where  Árbol de filtros del DataManager.
     * @param  array<string, string>  $fieldMap  Campo del grid (field) => columna SQL real.
     * @return array{0: string, 1: array<int, mixed>} Fragmento SQL (" AND (...)" por cada
     *         grupo, vacío si no hay filtros) y sus bindings en el mismo orden que los "?".
     */
    public function parse(array $where, array $fieldMap): array
    {
        $bindings = [];
        $sql = '';

        foreach ($where as $item) {
            if (!empty($item['predicates'])) {
                $parsed = $this->parsePredicates($item['predicates'], $fieldMap, $bindings);
            } elseif (!empty($item['field'])) {
                $parsed = $this->parsePredicates([$item], $fieldMap, $bindings);
            } else {
                continue;
            }

            if ($parsed !== '') {
                $sql .= " AND ($parsed)";
            }
        }

        return [$sql, $bindings];
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function parsePredicates(array $predicates, array $fieldMap, array &$bindings): string
    {
        $conditions = [];

        foreach ($predicates as $pred) {
            if (!empty($pred['isComplex']) && !empty($pred['predicates'])) {
                $sub = $this->parsePredicates($pred['predicates'], $fieldMap, $bindings);
                if ($sub !== '') {
                    $conditions[] = "($sub)";
                }
                continue;
            }

            if (!isset($pred['field'], $pred['operator'])) {
                continue;
            }

            $field = $fieldMap[$pred['field']] ?? null;
            if ($field === null) {
                // Campo desconocido: se ignora en vez de dejarlo pasar a SQL crudo.
                continue;
            }

            $value = $this->normalizeDate($pred['value'] ?? null);

            $condition = match ($pred['operator']) {
                'startswith' => $this->like($field, $value, $bindings, '%s%%'),
                'endswith' => $this->like($field, $value, $bindings, '%%%s'),
                'contains' => $this->like($field, $value, $bindings, '%%%s%%'),
                'equal' => $this->compare($field, '=', $value, $bindings),
                'notequal' => $this->compare($field, '<>', $value, $bindings),
                'greaterthan' => $this->compare($field, '>', $value, $bindings),
                'greaterthanorequal' => $this->compare($field, '>=', $value, $bindings),
                'lessthan' => $this->compare($field, '<', $value, $bindings),
                'lessthanorequal' => $this->compare($field, '<=', $value, $bindings),
                'in' => $this->in($field, (array) $value, $bindings),
                default => null,
            };

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function like(string $field, mixed $value, array &$bindings, string $pattern): string
    {
        $bindings[] = sprintf($pattern, (string) $value);

        return "$field LIKE ?";
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function compare(string $field, string $operator, mixed $value, array &$bindings): string
    {
        $bindings[] = $value;

        return "$field $operator ?";
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, mixed>  $bindings
     */
    private function in(string $field, array $values, array &$bindings): ?string
    {
        if (empty($values)) {
            return null;
        }

        array_push($bindings, ...$values);
        $placeholders = implode(',', array_fill(0, count($values), '?'));

        return "$field IN ($placeholders)";
    }

    private function normalizeDate(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return substr($value, 0, 10);
        }

        return $value;
    }
}
