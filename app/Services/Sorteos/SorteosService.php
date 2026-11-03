<?php

namespace App\Services\Sorteos;

use App\Models\Sorteo;
use App\Models\SorteoParticipacion;
use App\Traits\AplicaFiltrosSyncfusion;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SorteosService
{
    use AplicaFiltrosSyncfusion;

    private const CAMPOS_PERMITIDOS = [
        'rowid', 'descripcion', 'premio', 'fecha_inicio', 'fecha_final',
        'compra_minimo', 'valor_participacion', 'tipo_cliente', 'tipo_calculo',
        'estado', 'permitir_entregar_sin_ctrl', 'observacion',
    ];

    /**
     * Listado paginado de sorteos (payload estilo Syncfusion DataManager:
     * where, sorted, skip, take, + filtros de fecha/estado).
     */
    public function listarSorteos(Request $request): array
    {
        $data = $request->all();

        $skip = (int) ($data['skip'] ?? 0);
        $take = (int) ($data['take'] ?? 50);
        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sorted = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        $fechaDesde = trim((string) ($data['fecha_desde'] ?? ''));
        $fechaHasta = trim((string) ($data['fecha_hasta'] ?? ''));
        $fechaFinalDesde = trim((string) ($data['fecha_final_desde'] ?? ''));
        $fechaFinalHasta = trim((string) ($data['fecha_final_hasta'] ?? ''));
        $estadoFiltro = trim((string) ($data['estado_filtro'] ?? ''));

        $aplicarFiltros = function (Builder $query) use ($where, $fechaDesde, $fechaHasta, $fechaFinalDesde, $fechaFinalHasta, $estadoFiltro) {
            $this->aplicarFiltrosSyncfusion($query, $where, self::CAMPOS_PERMITIDOS);

            $query
                ->when($fechaDesde !== '', fn ($q) => $q->where('fecha_inicio', '>=', $fechaDesde))
                ->when($fechaHasta !== '', fn ($q) => $q->where('fecha_inicio', '<=', $fechaHasta))
                ->when($fechaFinalDesde !== '', fn ($q) => $q->where('fecha_final', '>=', $fechaFinalDesde))
                ->when($fechaFinalHasta !== '', fn ($q) => $q->where('fecha_final', '<=', $fechaFinalHasta))
                ->when($estadoFiltro !== '', fn ($q) => $q->where('estado', (int) $estadoFiltro));
        };

        $total = Sorteo::query()->tap($aplicarFiltros)->count();

        $ordenCampo = 'rowid';
        $ordenDireccion = 'desc';

        if (!empty($sorted[0]['name']) && in_array($sorted[0]['name'], self::CAMPOS_PERMITIDOS, true)) {
            $ordenCampo = $sorted[0]['name'];
            $ordenDireccion = ($sorted[0]['direction'] ?? '') === 'descending' ? 'desc' : 'asc';
        }

        $sorteos = Sorteo::query()
            ->tap($aplicarFiltros)
            ->orderBy($ordenCampo, $ordenDireccion)
            ->skip($skip)
            ->take($take)
            ->get([
                'rowid', 'descripcion', 'premio', 'fecha_inicio', 'fecha_final',
                'compra_minimo', 'valor_participacion', 'tipo_cliente', 'tipo_calculo',
                'estado', 'permitir_entregar_sin_ctrl', 'observacion',
                'fk_clientes', 'fk_productos', 'fk_ganador',
            ]);

        $rowData = $sorteos->map(fn (Sorteo $sorteo) => $this->formatearFila($sorteo))->values()->all();

        return ['rowData' => $rowData, 'count' => $total];
    }

    private function formatearFila(Sorteo $sorteo): array
    {
        return [
            'rowid' => $sorteo->rowid,
            'descripcion' => $sorteo->descripcion,
            'premio' => $sorteo->premio,
            'fecha_inicio' => $sorteo->fecha_inicio ? date('d/m/Y', strtotime($sorteo->fecha_inicio)) : null,
            'fecha_final' => $sorteo->fecha_final ? date('d/m/Y', strtotime($sorteo->fecha_final)) : null,
            'fecha_inicio_raw' => $sorteo->fecha_inicio ? date('Y-m-d', strtotime($sorteo->fecha_inicio)) : null,
            'fecha_final_raw' => $sorteo->fecha_final ? date('Y-m-d', strtotime($sorteo->fecha_final)) : null,
            'compra_minimo' => $sorteo->compra_minimo,
            'valor_participacion' => $sorteo->valor_participacion,
            'tipo_cliente' => $sorteo->tipo_cliente,
            'tipo_calculo' => $sorteo->tipo_calculo,
            'estado' => $sorteo->estado,
            'permitir_entregar_sin_ctrl' => $sorteo->permitir_entregar_sin_ctrl,
            'observacion' => $sorteo->observacion,
            'fk_clientes' => $sorteo->fk_clientes,
            'fk_productos' => $sorteo->fk_productos,
            'fk_ganador' => $sorteo->fk_ganador,
        ];
    }

    /**
     * Inserta boletos de participación de sorteo directo (sin pasar por la venta POS),
     * validando duplicados dentro del payload y contra la base de datos.
     */
    public function insertarBoletosDirectoPos(Request $request): JsonResponse
    {
        $boletos = $request->input('boletos', []);

        if (empty($boletos) || !is_array($boletos)) {
            return response()->json([
                'status'  => 1,
                'message' => 'El array boletos es obligatorio y no puede estar vacío',
            ]);
        }

        $codigos = [];
        foreach ($boletos as $indice => $boleto) {
            $numero = $indice + 1;

            if (empty($boleto['fk_sorteo'])) {
                return response()->json([
                    'status'  => 1,
                    'message' => "Boleto #{$numero}: fk_sorteo es obligatorio",
                ]);
            }

            if (empty($boleto['codigo'])) {
                return response()->json([
                    'status'  => 1,
                    'message' => "Boleto #{$numero}: codigo es obligatorio",
                ]);
            }

            $codigos[] = $boleto['codigo'];
        }

        // Duplicados dentro del payload
        $conteos = array_count_values($codigos);
        $duplicadosPayload = array_keys(array_filter($conteos, fn ($veces) => $veces > 1));

        if (!empty($duplicadosPayload)) {
            return response()->json([
                'error' => 'Códigos duplicados dentro del payload: '.implode(', ', $duplicadosPayload),
            ], 409);
        }

        // Duplicados contra la BD
        $yaExisten = SorteoParticipacion::query()
            ->whereIn('codigo', $codigos)
            ->pluck('codigo')
            ->all();

        if (!empty($yaExisten)) {
            return response()->json([
                'error' => 'Los siguientes códigos ya existen en la base de datos: '.implode(', ', $yaExisten),
            ], 409);
        }

        // Insertar en transacción; la constraint UNIQUE de "codigo" en la BD
        // garantiza atomicidad ante race conditions.
        $boletoActual = null;

        try {
            $insertados = DB::transaction(function () use ($boletos, &$boletoActual) {
                $insertados = [];

                foreach ($boletos as $boleto) {
                    $boletoActual = $boleto;

                    $estado = in_array((int) ($boleto['estado'] ?? 0), [0, 1, 2, 3], true)
                        ? (int) $boleto['estado']
                        : 0;

                    $participacion = new SorteoParticipacion();
                    $participacion->fk_sorteo       = (int) $boleto['fk_sorteo'];
                    $participacion->fk_cliente      = !empty($boleto['fk_cliente']) ? (int) $boleto['fk_cliente'] : null;
                    $participacion->fk_factura      = !empty($boleto['fk_factura']) ? (int) $boleto['fk_factura'] : null;
                    $participacion->ref_factura     = $boleto['ref_factura'] ?? '';
                    $participacion->fk_vendedor     = !empty($boleto['fk_vendedor']) ? (int) $boleto['fk_vendedor'] : null;
                    $participacion->valor_compra    = (int) ($boleto['valor_compra'] ?? 0);
                    $participacion->numero_boleto   = (int) ($boleto['numero_boleto'] ?? 1);
                    $participacion->codigo          = $boleto['codigo'];
                    $participacion->codigo_seguridad = $boleto['codigo_seguridad'] ?? null;
                    $participacion->estado          = $estado;
                    $participacion->fecha_registro  = !empty($boleto['fecha_registro']) ? $boleto['fecha_registro'] : now()->format('Y-m-d');
                    $participacion->save();

                    $insertados[] = [
                        'rowid'         => (int) $participacion->rowid,
                        'fk_sorteo'     => $participacion->fk_sorteo,
                        'numero_boleto' => $participacion->numero_boleto,
                        'codigo'        => $boleto['codigo'],
                        'estado'        => $estado,
                    ];
                }

                return $insertados;
            });
        } catch (QueryException $e) {
            $esDuplicado = (int) $e->getCode() === 23000
                || str_contains($e->getMessage(), '1062')
                || str_contains($e->getMessage(), 'Duplicate');

            $codigoActual = $boletoActual['codigo'] ?? '';

            if ($esDuplicado) {
                return response()->json([
                    'error' => "Código duplicado detectado en la inserción: \"{$codigoActual}\"",
                ], 409);
            }

            return response()->json([
                'status'  => 1,
                'message' => "Error al insertar boleto con código \"{$codigoActual}\": ".$e->getMessage(),
            ]);
        }

        return response()->json([
            'status'  => 0,
            'message' => 'Se insertaron '.count($insertados).' boleto(s) correctamente',
            'boletos' => $insertados,
        ], 200);
    }
}
