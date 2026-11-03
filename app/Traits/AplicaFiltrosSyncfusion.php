<?php

namespace App\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Replica el comportamiento de whereFiltersSyncfusion() del legado:
 * aplica sobre un query de Eloquent el "where" que envían los grids
 * de Syncfusion (DataManager), respetando una lista blanca de campos.
 */
trait AplicaFiltrosSyncfusion
{
    protected function aplicarFiltrosSyncfusion(Builder $query, array $where, array $camposPermitidos): void
    {
        foreach ($where as $grupo) {
            if (!empty($grupo['predicates'])) {
                $query->where(fn ($sub) => $this->aplicarPredicadosSyncfusion($sub, $grupo['predicates'], $camposPermitidos));
            } elseif (!empty($grupo['field'])) {
                $this->aplicarPredicadosSyncfusion($query, [$grupo], $camposPermitidos);
            }
        }
    }

    protected function aplicarPredicadosSyncfusion(Builder $query, array $predicados, array $camposPermitidos): void
    {
        foreach ($predicados as $predicado) {
            if (!empty($predicado['isComplex']) && !empty($predicado['predicates'])) {
                $query->where(fn ($sub) => $this->aplicarPredicadosSyncfusion($sub, $predicado['predicates'], $camposPermitidos));
                continue;
            }

            $campo = $predicado['field'] ?? null;

            if (!in_array($campo, $camposPermitidos, true)) {
                continue;
            }

            $valor = $this->normalizarValorFechaSyncfusion($predicado['value'] ?? null);

            match ($predicado['operator'] ?? null) {
                'startswith' => $query->where($campo, 'like', "{$valor}%"),
                'endswith' => $query->where($campo, 'like', "%{$valor}"),
                'contains' => $query->where($campo, 'like', "%{$valor}%"),
                'equal' => $query->where($campo, '=', $valor),
                'notequal' => $query->where($campo, '<>', $valor),
                'greaterthan' => $query->where($campo, '>', $valor),
                'greaterthanorequal' => $query->where($campo, '>=', $valor),
                'lessthan' => $query->where($campo, '<', $valor),
                'lessthanorequal' => $query->where($campo, '<=', $valor),
                default => null,
            };
        }
    }

    protected function normalizarValorFechaSyncfusion(mixed $valor): mixed
    {
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $valor)) {
            return substr($valor, 0, 10);
        }

        return $valor;
    }
}
