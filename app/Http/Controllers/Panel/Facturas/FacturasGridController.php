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
 * Listado de facturas de cliente, mismo patrón que Terceros/Usuarios/Productos.
 * Port de list_facturasClientesAG.php + api_factura.class.php::getFacturasClientesSF,
 * acotado a lo que esa página realmente muestra: no se replica el buscador de
 * producto (select2 async), el filtro "solo DIAN" ni "tipo_fac" (electrónica/pos/
 * borrador) porque el front-end de esta lista no los usa; tampoco `textEstado`
 * (requiere instanciar Facture::getLibStatut(), y el grid nunca lo pinta, solo
 * usa el f_fk_statut ya traducido).
 */
class FacturasGridController extends Controller
{
    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        'f_rowid' => 'f.rowid',
        'f_ref' => 'f.ref',
        'f_ref_client' => 'f.ref_client',
        'f_type' => 'f.type',
        'f_datef' => 'f.datef',
        'f_date_lim_reglement' => 'f.date_lim_reglement',
        'f_total_ht' => 'f.total_ht',
        'f_total_tva' => 'f.total_tva',
        'f_localtax1' => 'f.localtax1',
        'f_total_ttc' => 'f.total_ttc',
        'f_multicurrency_code' => 'f.multicurrency_code',
        'f_multicurrency_tx' => 'f.multicurrency_tx',
        'f_datec' => 'f.datec',
        'f_tms' => 'f.tms',
        'f_date_closing' => 'f.date_closing',
        'f_fk_statut' => 'f.fk_statut',
        'f_note_public' => 'f.note_public',
        'f_note_private' => 'f.note_private',
        's_nom' => 's.nom',
        's_town' => 's.town',
        's_zip' => 's.zip',
        'state_nom' => 'state.nom',
        'country_code_iso' => 'country.code_iso',
        'creadoPor' => "CONCAT_WS(' ', u.firstname, u.lastname)",
        'ba_label' => 'ba.label',
    ];

    /** @var array<string, string> */
    private const TIPOS = [
        '0' => 'Factura',
        '1' => 'Nota crédito',
        '3' => 'Nota crédito',
    ];

    /** @var array<string, string> */
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
        $sheet->setTitle('Listado de facturas');

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

        $fileName = 'Facturas_'.now()->format('Ymd_His').'.xlsx';

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

        $fileName = 'Facturas_'.now()->format('Ymd_His').'.csv';

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
            FROM {$prefix}facture f
            LEFT JOIN {$prefix}societe s ON f.fk_soc = s.rowid
            LEFT JOIN {$prefix}c_country country ON country.rowid = s.fk_pays
            LEFT JOIN {$prefix}c_departements state ON state.rowid = s.fk_departement
            LEFT JOIN {$prefix}user u ON f.fk_user_author = u.rowid
            LEFT JOIN {$prefix}bank_account ba ON f.fk_account = ba.rowid
            LEFT JOIN {$prefix}facture_extrafields ef ON ef.fk_object = f.rowid
        ";

        $whereSql = "WHERE 1=1 $sqlDate $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(DISTINCT f.rowid) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT f.rowid, f.ref, f.ref_client, f.type,
                f.datef, f.date_lim_reglement, f.total_ht, f.total_tva, f.localtax1, f.total_ttc,
                f.multicurrency_code, f.multicurrency_tx,
                f.datec, f.tms, f.date_closing, f.fk_statut, f.note_public, f.note_private,
                s.rowid AS s_rowid, s.nom AS s_nom, s.town AS s_town, s.zip AS s_zip,
                state.nom AS state_nom, country.code_iso,
                u.rowid AS u_rowid, CONCAT_WS(' ', u.firstname, u.lastname) AS creadoPor,
                ba.rowid AS ba_rowid, ba.label AS ba_label,
                ef.metodoPago
            $joins $whereSql $orderSql";

        $dataBindings = $bindings;
        if (!$all) {
            $sql .= ' LIMIT ? OFFSET ?';
            $dataBindings[] = $take;
            $dataBindings[] = $skip;
        }

        $rows = $db->select($sql, $dataBindings);

        // Nombres de cuentas bancarias para el tooltip de medios de pago (metodoPago
        // referencia bank_account.rowid, no c_paiement), una sola consulta reutilizada
        // para todas las filas, igual que el legado.
        $nombresCuentas = $db->table('bank_account')->pluck('label', 'rowid');

        $rowData = [];
        foreach ($rows as $obj) {
            $rowData[] = [
                'f_rowid' => $obj->rowid,
                'f_ref' => $obj->ref,
                'f_ref_client' => $obj->ref_client,
                'f_type' => self::TIPOS[(string) $obj->type] ?? 'Otro',
                'f_datef' => $this->soloFecha($obj->datef),
                'f_date_lim_reglement' => $this->soloFecha($obj->date_lim_reglement),
                's_rowid' => $obj->s_rowid,
                's_nom' => $obj->s_nom,
                's_town' => $obj->s_town,
                's_zip' => $obj->s_zip,
                'state_nom' => $obj->state_nom,
                'country_code_iso' => $obj->code_iso,
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
                'tooltipMetodoPago' => $this->tooltipMetodoPago($obj->metodoPago, $nombresCuentas),
            ];
        }

        return [
            'rowData' => $rowData,
            'count' => $total,
        ];
    }

    private function soloFecha(mixed $valor): ?string
    {
        if (!$valor) {
            return null;
        }

        return Carbon::parse($valor)->format('Y-m-d');
    }

    /**
     * "Efectivo: $2.500, Tarjeta: $10.000" a partir del JSON
     * [{"idMetodo": <bank_account.rowid>, "valor": <monto>}, ...] que guarda el POS
     * en facture_extrafields.metodoPago.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $nombresCuentas
     */
    private function tooltipMetodoPago(?string $metodoPagoJson, $nombresCuentas): string
    {
        $metodos = json_decode($metodoPagoJson ?? '[]', true);
        if (!is_array($metodos)) {
            return '';
        }

        $partes = [];
        foreach ($metodos as $metodo) {
            $idMetodo = (int) ($metodo['idMetodo'] ?? 0);
            $valor = (float) ($metodo['valor'] ?? 0);
            $nombre = $nombresCuentas[$idMetodo] ?? null;
            if ($idMetodo && $nombre) {
                $partes[] = $nombre.': $'.number_format($valor, 0, ',', '.');
            }
        }

        return implode(', ', $partes);
    }
}
