<?php

namespace App\Http\Controllers\Panel\Categorias;

use App\Http\Controllers\Controller;
use App\Services\Categorias\CategoriasService;
use App\Services\Grids\ExtrafieldResolver;
use App\Services\Grids\SyncfusionFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Grid de etiquetas/categorías de todos los tipos (tabla categorie). El
 * front-end manda `currentTipos` con los tipos a mostrar (vacío = todos).
 */
class CategoriasGridController extends Controller
{
    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        'c_rowid' => 'c.rowid',
        'c_label' => 'c.label',
        'c_padre' => 'rp.ruta',
        'c_description' => 'c.description',
        'c_subcategorias' => 'sub.total',
        'c_datec' => 'c.date_creation',
        'c_tms' => 'c.tms',
    ];

    public function __construct(
        private SyncfusionFilterParser $filterParser,
        private ExtrafieldResolver $extrafieldResolver,
        private CategoriasService $categorias,
    ) {
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->all();
        $result = $this->buildRows($data, false, (int) ($data['skip'] ?? 0), (int) ($data['take'] ?? 50));

        return response()->json($result);
    }

    public function exportXlsx(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->all();
        $columnas = array_values($data['columnas'] ?? []);
        $result = $this->buildRows($data, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Categorías');

        foreach ($columnas as $i => $columna) {
            $sheet->setCellValue([$i + 1, 1], $columna['label'] ?? $columna['dataField']);
        }
        $sheet->getStyle([1, 1, max(count($columnas), 1), 1])->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($result['rowData'] as $fila) {
            foreach ($columnas as $i => $columna) {
                $sheet->setCellValue([$i + 1, $rowIndex], (string) ($fila[$columna['dataField']] ?? ''));
            }
            $rowIndex++;
        }

        foreach (range(1, max(count($columnas), 1)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, 'Categorias_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->all();
        $columnas = array_values($data['columnas'] ?? []);
        $separador = ($data['separador'] ?? ';') === ',' ? ',' : ';';
        $result = $this->buildRows($data, true);

        $fileName = 'Categorias_'.now()->format('Ymd_His').'.csv';

        return new StreamedResponse(function () use ($columnas, $result, $separador) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, para que Excel abra bien los acentos
            fputcsv($out, array_map(fn ($c) => $c['label'] ?? $c['dataField'], $columnas), $separador, '"', '\\');
            foreach ($result['rowData'] as $fila) {
                fputcsv($out, array_map(fn ($c) => (string) ($fila[$c['dataField']] ?? ''), $columnas), $separador, '"', '\\');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /**
     * @return array{rowData: array<int, array<string, mixed>>, count: int}
     */
    private function buildRows(array $data, bool $all, int $skip = 0, int $take = 50): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sort = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        $bindings = [];
        $tipoSql = '';
        $tipos = array_values(array_intersect(
            $this->extrafieldResolver->decodeIdList($data['currentTipos'] ?? null),
            array_keys(CategoriasService::TIPOS)
        ));
        if ($tipos) {
            $tipoSql = ' AND c.type IN ('.implode(',', array_fill(0, count($tipos), '?')).')';
            array_push($bindings, ...$tipos);
        } else {
            // Tipos que Dolibarr tiene pero el panel no administra (p. ej. líneas bancarias) no se listan.
            $tipoSql = ' AND c.type IN ('.implode(',', array_keys(CategoriasService::TIPOS)).')';
        }

        [$filterSql, $filterBindings] = $this->filterParser->parse($where, self::CAMPO_MAP);
        array_push($bindings, ...$filterBindings);

        $orderSql = ' ORDER BY c.type, COALESCE(r.ruta, c.label)';
        if (!empty($sort)) {
            $campo = $sort[0]['name'] ?? '';
            $sortColumn = $campo === 'c_tipo_label' ? 'c.type' : (self::CAMPO_MAP[$campo] ?? null);
            if ($sortColumn !== null) {
                $dir = (($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC';
                $orderSql = " ORDER BY $sortColumn $dir, c.rowid";
            }
        }

        $joins = "
            FROM {$prefix}categorie c
            LEFT JOIN rutas r ON r.rowid = c.rowid
            LEFT JOIN rutas rp ON rp.rowid = c.fk_parent
            LEFT JOIN (
                SELECT fk_parent, COUNT(*) AS total FROM {$prefix}categorie GROUP BY fk_parent
            ) sub ON sub.fk_parent = c.rowid
            LEFT JOIN {$prefix}user uc ON uc.rowid = c.fk_user_creat
        ";
        $whereSql = "WHERE 1 = 1 $tipoSql $filterSql";
        $cte = $this->categorias->sqlRutas($prefix);

        $total = (int) ($db->selectOne("$cte SELECT COUNT(*) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "$cte SELECT c.rowid, c.type, c.label, c.color, c.description, c.fk_parent,
                c.date_creation, c.tms, rp.ruta AS ruta_padre,
                COALESCE(sub.total, 0) AS subcategorias,
                CONCAT_WS(' ', uc.firstname, uc.lastname) AS creador
            $joins $whereSql $orderSql";

        $dataBindings = $bindings;
        if (!$all) {
            $sql .= ' LIMIT ? OFFSET ?';
            $dataBindings[] = $take;
            $dataBindings[] = $skip;
        }

        $rows = $db->select($sql, $dataBindings);

        // Elementos asignados: cada tipo tiene su tabla de enlace; se cuenta solo lo de esta página.
        $elementos = [];
        foreach (collect($rows)->groupBy('type') as $tipo => $grupo) {
            $elementos += $this->categorias->contarElementos((int) $tipo, $grupo->pluck('rowid')->map(fn ($v) => (int) $v)->all());
        }

        $rowData = array_map(fn ($r) => [
            'c_rowid' => (int) $r->rowid,
            'c_tipo' => (int) $r->type,
            'c_tipo_label' => CategoriasService::nombreTipo((int) $r->type),
            'c_label' => $r->label,
            'c_color' => $r->color ? '#'.ltrim($r->color, '#') : null,
            'c_padre' => $r->ruta_padre,
            'c_description' => $r->description !== null ? trim(strip_tags($r->description)) : null,
            'c_subcategorias' => (int) $r->subcategorias,
            'c_elementos' => $elementos[(int) $r->rowid] ?? 0,
            'c_creador' => trim((string) $r->creador) ?: null,
            'c_datec' => $r->date_creation,
            'c_tms' => $r->tms,
        ], $rows);

        return ['rowData' => $rowData, 'count' => $total];
    }
}
