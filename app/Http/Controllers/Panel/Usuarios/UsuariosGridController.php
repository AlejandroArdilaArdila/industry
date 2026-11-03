<?php

namespace App\Http\Controllers\Panel\Usuarios;

use App\Http\Controllers\Controller;
use App\Services\Grids\ExtrafieldResolver;
use App\Services\Grids\SyncfusionFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsuariosGridController extends Controller
{
    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        'u_rowid' => 'u.rowid',
        'u_login' => 'u.login',
        'u_fullname' => "CONCAT(u.firstname, ' ', u.lastname)",
        'u_email' => 'u.email',
        'u_job' => 'u.job',
        'u_office_phone' => 'u.office_phone',
        'u_town' => 'u.town',
        'state_nom' => 'state.nom',
        'region_nom' => 'region.nom',
        'country_code_iso' => 'country.code_iso',
        'u_datec' => 'u.datec',
        'u_admin' => 'u.admin',
        'u_status' => 'u.statut',
    ];

    public function __construct(
        private SyncfusionFilterParser $filterParser,
        private ExtrafieldResolver $extrafieldResolver,
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

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listado de usuarios');

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

        $fileName = 'Usuarios_'.now()->format('Ymd_His').'.xlsx';

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

        $fileName = 'Usuarios_'.now()->format('Ymd_His').'.csv';

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
     * Mismo query que TercerosGridController::buildRows, adaptado a la tabla
     * `user`: joins de catálogo, filtro de grupo de usuario (usergroup),
     * filtros de columna (Syncfusion) y orden.
     *
     * @return array{rowData: array<int, array<string, mixed>>, count: int, extrafieldsList: array<int, array<string, mixed>>}
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
            $sqlDate = ' AND u.datec BETWEEN ? AND ?';
            $bindings[] = $startDate;
            $bindings[] = $endDate;
        }

        $extraSql = '';

        $idsGrupo = $this->extrafieldResolver->decodeIdList($data['currentIdsGrupo'] ?? null);
        if (!empty($idsGrupo)) {
            $placeholders = implode(',', array_fill(0, count($idsGrupo), '?'));
            $extraSql .= " AND EXISTS (SELECT 1 FROM {$prefix}usergroup_user gu WHERE gu.fk_user = u.rowid AND gu.fk_usergroup IN ($placeholders))";
            array_push($bindings, ...$idsGrupo);
        }

        [$filterSql, $filterBindings] = $this->filterParser->parse($where, self::CAMPO_MAP);
        array_push($bindings, ...$filterBindings);

        $orderSql = ' ORDER BY u.tms DESC ';
        if (!empty($sort)) {
            $sortColumn = self::CAMPO_MAP[$sort[0]['name'] ?? ''] ?? null;
            if ($sortColumn !== null) {
                $dir = (($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC';
                $orderSql = " ORDER BY $sortColumn $dir";
            }
        }

        $extrafieldDefs = $db->table('extrafields')
            ->where('elementtype', 'user')
            ->orderBy('pos')
            ->get(['name', 'label', 'type', 'enabled', 'param']);

        $extraSelects = $extrafieldDefs
            ->map(fn ($d) => "ef.`{$d->name}` AS ef_{$d->name}")
            ->implode(', ');

        $joins = "
            FROM {$prefix}user u
            LEFT JOIN {$prefix}user_extrafields ef ON u.rowid = ef.fk_object
            LEFT JOIN {$prefix}c_country country ON country.rowid = u.fk_country
            LEFT JOIN {$prefix}c_departements state ON state.rowid = u.fk_state
            LEFT JOIN {$prefix}c_regions region ON region.code_region = state.fk_region
        ";

        // Orden importa: debe calzar con el orden en que se hizo array_push($bindings, ...)
        // arriba (sqlDate, luego extraSql -grupo-, luego filterBindings), si no los "?"
        // quedan atados a los valores equivocados.
        $whereSql = "WHERE 1=1 $sqlDate $extraSql $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(DISTINCT u.rowid) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT u.rowid AS idUsuario, u.login, u.firstname, u.lastname,
                u.email, u.job, u.office_phone, u.town,
                state.nom AS departamento, region.nom AS region, country.code_iso,
                u.datec, u.tms, u.admin, u.statut, u.import_key".
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
                $rawValue = $obj->{'ef_'.$def->name} ?? null;
                $value = $this->extrafieldResolver->resolveExtrafieldValue($def, $rawValue, $optionCache);

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
                'u_rowid' => $obj->idUsuario,
                'u_login' => $obj->login,
                'u_fullname' => trim($obj->firstname.' '.$obj->lastname),
                'u_email' => $obj->email,
                'u_job' => $obj->job,
                'u_office_phone' => $obj->office_phone,
                'u_town' => $obj->town,
                'state_nom' => $obj->departamento,
                'region_nom' => $obj->region,
                'country_code_iso' => $obj->code_iso,
                'u_datec' => $obj->datec,
                'u_tms' => $obj->tms,
                'u_admin' => $obj->admin,
                'u_status' => $obj->statut,
                'u_import_key' => $obj->import_key,
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
