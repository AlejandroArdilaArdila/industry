<?php

namespace App\Http\Controllers\Panel\Productos;

use App\Http\Controllers\Controller;
use App\Services\Grids\ExtrafieldResolver;
use App\Services\Grids\SyncfusionFilterParser;
use App\Services\Productos\ProductosEstadisticasService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve tanto el grid de Productos como el de Servicios (Dolibarr guarda
 * ambos en la misma tabla `product`, diferenciados por fk_product_type):
 * el front-end manda `type` (0=producto, 1=servicio) igual que el legado
 * PHP (list_productos.php + api_moduloproductos.class.php).
 */
class ProductosGridController extends Controller
{
    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        'p_rowid' => 'p.rowid',
        'p_ref' => 'p.ref',
        'p_label' => 'p.label',
        'p_price' => 'p.price',
        'p_price_ttc' => 'p.price_ttc',
        'p_tva_tx' => 'p.tva_tx',
        'p_price_min' => 'p.price_min',
        'p_datec' => 'p.datec',
        'p_tms' => 'p.tms',
        'p_tosell' => 'p.tosell',
        'p_tobuy' => 'p.tobuy',
        'pc_ref' => 'pc.ref',
        'uautor_name' => "CONCAT_WS(' ', uautor.firstname, uautor.lastname)",
        'ueditor_name' => "CONCAT_WS(' ', ueditor.firstname, ueditor.lastname)",
    ];

    public function __construct(
        private SyncfusionFilterParser $filterParser,
        private ExtrafieldResolver $extrafieldResolver,
        private ProductosEstadisticasService $estadisticas,
    ) {
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->all();
        $skip = (int) ($data['skip'] ?? 0);
        $take = (int) ($data['take'] ?? 100);

        $result = $this->buildRows($data, false, $skip, $take);

        return response()->json([
            'rowData' => $result['rowData'],
            'count' => $result['count'],
            'summary' => ['valor' => 0, 'total' => 0, 'cmv' => 0, 'margen' => 0],
            'extrafieldsList' => $result['extrafieldsList'],
        ]);
    }

    public function exportXlsx(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->all();
        $columnas = $data['columnas'] ?? [];
        $result = $this->buildRows($data, true);
        $titulo = ((int) ($data['type'] ?? 0)) === 1 ? 'servicios' : 'productos';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listado de '.$titulo);

        foreach (array_values($columnas) as $i => $columna) {
            $sheet->setCellValue([$i + 1, 1], $columna['label'] ?? $columna['dataField']);
        }
        $sheet->getStyle([1, 1, count($columnas), 1])->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($result['rowData'] as $fila) {
            foreach (array_values($columnas) as $i => $columna) {
                $sheet->setCellValue([$i + 1, $rowIndex], (string) ($fila[$columna['dataField']] ?? ''));
            }
            $rowIndex++;
        }

        foreach (range(1, count($columnas)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $fileName = ucfirst($titulo).'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, $fileName, [
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
        $titulo = ((int) ($data['type'] ?? 0)) === 1 ? 'Servicios' : 'Productos';

        $fileName = $titulo.'_'.now()->format('Ymd_His').'.csv';

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
     * Mismo query que el legado getListProductosSF: product + joins de
     * plantilla contable/autor/editor/códigos de barras, filtro de
     * categoría, filtros de columna (Syncfusion) y orden.
     *
     * @return array{rowData: array<int, array<string, mixed>>, count: int, extrafieldsList: array<int, array<string, mixed>>}
     */
    private function buildRows(array $data, bool $all, int $skip = 0, int $take = 100): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sort = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];
        $type = (int) ($data['type'] ?? 0);
        $startDate = (string) ($data['startDate'] ?? '');
        $endDate = (string) ($data['endDate'] ?? '');

        $bindings = [$type];

        $sqlDate = '';
        if ($startDate !== '' && $endDate !== '') {
            $sqlDate = ' AND p.datec BETWEEN ? AND ?';
            $bindings[] = $startDate;
            $bindings[] = $endDate;
        }

        $extraSql = '';

        $idsCatProd = $this->extrafieldResolver->decodeIdList($data['currentIdsCatProd'] ?? null);
        if (!empty($idsCatProd)) {
            $placeholders = implode(',', array_fill(0, count($idsCatProd), '?'));
            $extraSql .= " AND EXISTS (SELECT 1 FROM {$prefix}categorie_product cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ($placeholders))";
            array_push($bindings, ...$idsCatProd);
        }

        // Productos con actividad comercial (tarjetas del listado): en solicitudes, cotizaciones, órdenes o seguimientos pendientes
        $actividad = (string) ($data['actividad'] ?? '');
        if ($actividad !== '') {
            [$sqlActividad, $bindingsActividad] = $this->estadisticas->condicion($actividad, Auth::guard('web')->user());
            $extraSql .= " AND {$sqlActividad}";
            array_push($bindings, ...$bindingsActividad);
        }

        [$filterSql, $filterBindings] = $this->filterParser->parse($where, self::CAMPO_MAP);
        array_push($bindings, ...$filterBindings);

        $orderSql = ' ORDER BY p.tms DESC, p.datec DESC ';
        if (!empty($sort)) {
            $sortColumn = self::CAMPO_MAP[$sort[0]['name'] ?? ''] ?? null;
            if ($sortColumn !== null) {
                $dir = (($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC';
                $orderSql = " ORDER BY $sortColumn $dir";
            }
        }

        $extrafieldDefs = $db->table('extrafields')
            ->where('elementtype', 'product')
            ->orderBy('pos')
            ->get(['name', 'label', 'type', 'enabled', 'param']);

        $extraSelects = $extrafieldDefs
            ->map(fn ($d) => "ef.`{$d->name}` AS ef_{$d->name}")
            ->implode(', ');

        $joins = "
            FROM {$prefix}product p
            LEFT JOIN {$prefix}product_extrafields ef ON p.rowid = ef.fk_object
            LEFT JOIN {$prefix}integros_plantillas_contables pc ON pc.rowid = ef.fk_plantilla_contable
            LEFT JOIN {$prefix}user uautor ON p.fk_user_author = uautor.rowid
            LEFT JOIN {$prefix}user ueditor ON p.fk_user_modif = ueditor.rowid
            LEFT JOIN (
                SELECT fk_product, GROUP_CONCAT(barcode SEPARATOR ', ') AS barcodes
                FROM {$prefix}product_barcode
                GROUP BY fk_product
            ) pb ON p.rowid = pb.fk_product
        ";

        // Orden importa: debe calzar con el orden en que se hizo array_push($bindings, ...)
        // arriba (type, sqlDate, luego extraSql -categoría-, luego filterBindings), si no
        // los "?" quedan atados a los valores equivocados.
        $whereSql = "WHERE p.fk_product_type = ? $sqlDate $extraSql $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(DISTINCT p.rowid) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT p.rowid AS idProducto, p.ref, p.label,
                p.price, p.price_ttc, p.tva_tx, p.price_min,
                p.datec, p.tms, p.tosell, p.tobuy,
                pc.ref AS refPlantilla,
                CONCAT_WS(' ', uautor.firstname, uautor.lastname) AS nombreAutor,
                CONCAT_WS(' ', ueditor.firstname, ueditor.lastname) AS nombreEditor,
                p.fk_user_author, p.fk_user_modif,
                pb.barcodes".
                ($extraSelects !== '' ? ", $extraSelects" : '')."
            $joins $whereSql $orderSql";

        $dataBindings = $bindings;
        if (!$all) {
            $sql .= ' LIMIT ? OFFSET ?';
            $dataBindings[] = $take;
            $dataBindings[] = $skip;
        }

        $rows = $db->select($sql, $dataBindings);

        $optionCache = [];
        $rowData = [];

        foreach ($rows as $obj) {
            $extras = [];
            $flatExtras = [];
            foreach ($extrafieldDefs as $def) {
                // El código de barras real vive en product_barcode (permite
                // varios por producto); el valor crudo del extrafield es
                // vestigial y no se usa, igual que en el legado.
                if ($def->name === 'codigo_barras') {
                    $value = $obj->barcodes ?? '';
                } else {
                    $rawValue = $obj->{'ef_'.$def->name} ?? null;
                    $value = $this->extrafieldResolver->resolveExtrafieldValue($def, $rawValue, $optionCache);
                }

                $extras[$def->name] = [
                    'label' => $def->label,
                    'name' => $def->name,
                    'type' => $def->type,
                    'enabled' => $def->enabled,
                    'value' => $value,
                ];
                $flatExtras['ef_'.$def->name] = $value;
            }

            $rowData[] = $flatExtras + [
                'p_rowid' => $obj->idProducto,
                'p_ref' => $obj->ref,
                'p_label' => $obj->label,
                'p_price' => is_numeric($obj->price) ? (float) $obj->price : 0,
                'p_price_ttc' => is_numeric($obj->price_ttc) ? (float) $obj->price_ttc : 0,
                'p_tva_tx' => is_numeric($obj->tva_tx) ? (float) $obj->tva_tx : 0,
                'p_price_min' => is_numeric($obj->price_min) ? (float) $obj->price_min : 0,
                'p_datec' => $obj->datec,
                'p_tms' => $obj->tms,
                'p_tosell' => $obj->tosell,
                'p_tobuy' => $obj->tobuy,
                'pc_ref' => $obj->refPlantilla,
                'uautor_name' => $obj->nombreAutor,
                'ueditor_name' => $obj->nombreEditor,
                'p_fk_user_author' => $obj->fk_user_author,
                'p_fk_user_modif' => $obj->fk_user_modif,
                'extrafields' => $extras,
            ];
        }

        return [
            'rowData' => $rowData,
            'count' => $total,
            'extrafieldsList' => $extrafieldDefs->map(fn ($d) => [
                'key' => $d->name,
                'label' => $d->label,
                'type' => $d->type,
            ])->values()->all(),
        ];
    }
}
