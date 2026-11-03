<?php

namespace App\Http\Controllers\Panel\Facturas;

use App\Http\Controllers\Controller;
use App\Services\Grids\SyncfusionFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listado de facturas de proveedor. Port de list_facturasProveedoresAg.php +
 * api_factura.class.php::getFacturasProveedorSF, acotado a lo que esa página
 * realmente muestra (sin "cuenta_cobro"/"statusConsultado" como pseudo-filtros
 * de backend: el front-end de esta lista no los usa; sin textEstado/dynamount_payed
 * por la misma razón que en Facturas de cliente).
 *
 * Corrección frente al legado: `f_fk_cond_reglement` ahí se mapea a `cpt.libelle`
 * (texto) pero el dropdown de filtro manda el id numérico de `c_payment_term` —
 * un filtro "equal" contra texto nunca habría hecho match. Aquí se filtra por
 * `f.fk_cond_reglement` (la FK real) y se muestra el label ya resuelto aparte.
 */
class FacturasProveedorGridController extends Controller
{
    private const CAMPO_MAP = [
        'f_rowid' => 'f.rowid',
        'f_ref' => 'f.ref',
        'f_ref_supplier' => 'f.ref_supplier',
        'f_type' => 'f.type',
        'f_datef' => 'f.datef',
        'f_total_ht' => 'f.total_ht',
        'f_total_tva' => 'f.total_tva',
        'f_localtax1' => 'f.localtax1',
        'f_total_ttc' => 'f.total_ttc',
        'ba_label' => 'ba.label',
        'f_fk_cond_reglement' => 'f.fk_cond_reglement',
        'creadoPor' => "CONCAT_WS(' ', u.firstname, u.lastname)",
        'f_multicurrency_code' => 'f.multicurrency_code',
        'f_multicurrency_tx' => 'f.multicurrency_tx',
        'f_datec' => 'f.datec',
        'f_tms' => 'f.tms',
        'f_date_closing' => 'f.date_closing',
        'f_note_public' => 'f.note_public',
        'f_note_private' => 'f.note_private',
        'f_fk_statut' => 'f.fk_statut',
    ];

    private const ESTADOS = [
        '0' => 'Borrador',
        '1' => 'No pagado',
        '2' => 'Pagado',
        '3' => 'Abandonado',
    ];

    public function __construct(private SyncfusionFilterParser $filterParser)
    {
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
        ]);
    }

    public function exportXlsx(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->all();
        $columnas = $data['columnas'] ?? [];
        $result = $this->buildRows($data, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listado de facturas proveedor');

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

        $fileName = 'FacturasProveedor_'.now()->format('Ymd_His').'.xlsx';

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

        $fileName = 'FacturasProveedor_'.now()->format('Ymd_His').'.csv';

        return new StreamedResponse(function () use ($columnas, $result, $separador) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
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
    private function buildRows(array $data, bool $all, int $skip = 0, int $take = 100): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sort = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];
        $startDate = (string) ($data['startDate'] ?? '');
        $endDate = (string) ($data['endDate'] ?? '');

        $bindings = [];

        $sqlDate = '';
        if ($startDate !== '' && $endDate !== '') {
            $sqlDate = ' AND f.datef BETWEEN ? AND ?';
            $bindings[] = $startDate;
            $bindings[] = $endDate;
        }

        [$filterSql, $filterBindings] = $this->filterParser->parse($where, self::CAMPO_MAP);
        array_push($bindings, ...$filterBindings);

        $orderSql = ' ORDER BY f.tms DESC ';
        if (!empty($sort)) {
            $sortColumn = self::CAMPO_MAP[$sort[0]['name'] ?? ''] ?? null;
            if ($sortColumn !== null) {
                $dir = (($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC';
                $orderSql = " ORDER BY $sortColumn $dir";
            }
        }

        $joins = "
            FROM {$prefix}facture_fourn f
            LEFT JOIN {$prefix}societe s ON f.fk_soc = s.rowid
            LEFT JOIN {$prefix}user u ON f.fk_user_author = u.rowid
            LEFT JOIN {$prefix}bank_account ba ON f.fk_account = ba.rowid
            LEFT JOIN {$prefix}c_payment_term cpt ON f.fk_cond_reglement = cpt.rowid
            LEFT JOIN {$prefix}facture_fourn_extrafields ef ON ef.fk_object = f.rowid
        ";

        $whereSql = "WHERE 1=1 $sqlDate $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(DISTINCT f.rowid) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT f.rowid, f.ref, f.ref_supplier, f.type,
                f.datef, f.total_ht, f.total_tva, f.localtax1, f.total_ttc,
                f.multicurrency_code, f.multicurrency_tx,
                f.datec, f.tms, f.date_closing, f.fk_statut, f.note_public, f.note_private,
                s.rowid AS s_rowid, s.nom AS s_nom,
                u.rowid AS u_rowid, CONCAT_WS(' ', u.firstname, u.lastname) AS creadoPor,
                ba.rowid AS ba_rowid, ba.label AS ba_label,
                cpt.libelle AS cond_reglement_label,
                ef.cuenta_cobro
            $joins $whereSql $orderSql";

        $dataBindings = $bindings;
        if (!$all) {
            $sql .= ' LIMIT ? OFFSET ?';
            $dataBindings[] = $take;
            $dataBindings[] = $skip;
        }

        $rows = $db->select($sql, $dataBindings);

        $rowData = [];
        foreach ($rows as $obj) {
            $rowData[] = [
                'f_rowid' => $obj->rowid,
                'f_ref' => $obj->ref,
                'f_ref_supplier' => $obj->ref_supplier,
                'f_type' => $this->tipoFactura($obj),
                'f_datef' => $this->soloFecha($obj->datef),
                's_rowid' => $obj->s_rowid,
                's_nom' => $obj->s_nom,
                'f_total_ht' => is_numeric($obj->total_ht) ? (float) $obj->total_ht : 0,
                'f_total_tva' => is_numeric($obj->total_tva) ? (float) $obj->total_tva : 0,
                'f_localtax1' => is_numeric($obj->localtax1) ? (float) $obj->localtax1 : 0,
                'f_total_ttc' => is_numeric($obj->total_ttc) ? (float) $obj->total_ttc : 0,
                'f_multicurrency_code' => $obj->multicurrency_code,
                'f_multicurrency_tx' => is_numeric($obj->multicurrency_tx) ? (float) $obj->multicurrency_tx : 0,
                'f_datec' => $this->soloFecha($obj->datec),
                'f_tms' => $this->soloFecha($obj->tms),
                'f_date_closing' => $this->soloFecha($obj->date_closing),
                'f_note_public' => $obj->note_public,
                'f_note_private' => $obj->note_private,
                'f_fk_statut' => self::ESTADOS[(string) $obj->fk_statut] ?? 'Estado no seteado',
                'u_rowid' => $obj->u_rowid,
                'creadoPor' => $obj->creadoPor,
                'ba_rowid' => $obj->ba_rowid,
                'ba_label' => $obj->ba_label,
                'f_fk_cond_reglement' => $obj->cond_reglement_label,
            ];
        }

        return [
            'rowData' => $rowData,
            'count' => $total,
        ];
    }

    private function tipoFactura(object $obj): string
    {
        if ((int) ($obj->cuenta_cobro ?? 0) === 1) {
            return 'Cuenta cobro';
        }

        return ((string) $obj->type) === '0' ? 'Factura' : 'Nota crédito';
    }

    private function soloFecha(mixed $valor): ?string
    {
        if (!$valor) {
            return null;
        }

        return Carbon::parse($valor)->format('Y-m-d');
    }
}
