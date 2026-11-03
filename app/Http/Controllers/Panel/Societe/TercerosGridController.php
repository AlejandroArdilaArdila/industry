<?php

namespace App\Http\Controllers\Panel\Societe;

use App\Http\Controllers\Controller;
use App\Services\Grids\ExtrafieldResolver;
use App\Services\Grids\SyncfusionFilterParser;
use App\Services\Societe\TercerosEstadisticasService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TercerosGridController extends Controller
{
    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        's_rowid' => 's.rowid',
        's_siren' => 's.siren',
        's_url' => 's.url',
        's_nom' => 's.nom',
        's_name_alias' => 's.name_alias',
        's_phone' => 's.phone',
        's_town' => 's.town',
        's_email' => 's.email',
        's_datec' => 's.datec',
        's_code_client' => 's.code_client',
        's_code_fournisseur' => 's.code_fournisseur',
        'region_nom' => 'region.nom',
        'country_code_iso' => 'country.code_iso',
        'typent_libelli' => 'typent.libelle',
        's_status' => 's.status',
        's_address' => 's.address',
        'naturaleza' => "CASE WHEN s.client = 1 AND s.fournisseur = 1 THEN 'Cliente / Proveedor' WHEN s.client = 1 THEN 'Cliente' WHEN s.fournisseur = 1 THEN 'Proveedor' ELSE 'N/A' END",
    ];

    public function __construct(
        private SyncfusionFilterParser $filterParser,
        private ExtrafieldResolver $extrafieldResolver,
        private TercerosEstadisticasService $estadisticas,
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
        $sheet->setTitle('Listado de terceros');

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

        $fileName = 'Terceros_'.now()->format('Ymd_His').'.xlsx';

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

        $fileName = 'Terceros_'.now()->format('Ymd_His').'.csv';

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
     * Mismo query que el legado getListTercerosSF: societe + joins de catálogo,
     * filtros de categoría, naturaleza, tipo, filtros de columna (Syncfusion) y orden.
     *
     * @return array{rowData: array<int, array<string, mixed>>, count: int, extrafieldsList: array<int, array<string, mixed>>}
     */
    private function buildRows(array $data, bool $all, int $skip = 0, int $take = 100): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sort = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];
        $type = (string) ($data['type'] ?? '');
        $startDate = (string) ($data['startDate'] ?? '');
        $endDate = (string) ($data['endDate'] ?? '');

        $bindings = [];

        $sqlDate = '';
        if ($startDate !== '' && $endDate !== '') {
            $sqlDate = ' AND s.datec BETWEEN ? AND ?';
            $bindings[] = $startDate;
            $bindings[] = $endDate;
        }

        $extraSql = '';

        $idsCatClient = $this->extrafieldResolver->decodeIdList($data['currentIdsCatClient'] ?? null);
        if (!empty($idsCatClient)) {
            $placeholders = implode(',', array_fill(0, count($idsCatClient), '?'));
            $extraSql .= " AND EXISTS (SELECT 1 FROM {$prefix}categorie_societe cs WHERE cs.fk_soc = s.rowid AND cs.fk_categorie IN ($placeholders))";
            array_push($bindings, ...$idsCatClient);
        }

        $idsCatProv = $this->extrafieldResolver->decodeIdList($data['currentIdsCatProv'] ?? null);
        if (!empty($idsCatProv)) {
            $placeholders = implode(',', array_fill(0, count($idsCatProv), '?'));
            $extraSql .= " AND EXISTS (SELECT 1 FROM {$prefix}categorie_fournisseur cf WHERE cf.fk_soc = s.rowid AND cf.fk_categorie IN ($placeholders))";
            array_push($bindings, ...$idsCatProv);
        }

        [$where, $naturalezaVals] = $this->extractNaturaleza($where);

        if (!empty($naturalezaVals)) {
            $parts = [];
            if (in_array('C', $naturalezaVals, true)) {
                $parts[] = 's.client = 1';
            }
            if (in_array('P', $naturalezaVals, true)) {
                $parts[] = 's.fournisseur = 1';
            }
            if ($parts) {
                $extraSql .= ' AND ('.implode(' OR ', $parts).')';
            }
        }

        if ($type === 'c') {
            $extraSql .= ' AND s.client IN (1,3)';
        } elseif ($type === 'p') {
            $extraSql .= ' AND s.client = 2';
        } elseif ($type === 'f') {
            $extraSql .= ' AND s.fournisseur IN (1,3)';
        }

        // Terceros con actividad comercial (tarjetas del listado): solicitudes, cotizaciones, órdenes o seguimientos pendientes
        $actividad = (string) ($data['actividad'] ?? '');
        if ($actividad !== '') {
            [$sqlActividad, $bindingsActividad] = $this->estadisticas->condicionActividad($actividad, Auth::guard('web')->user());
            $extraSql .= ' AND '.$sqlActividad;
            array_push($bindings, ...$bindingsActividad);
        }

        [$filterSql, $filterBindings] = $this->filterParser->parse($where, self::CAMPO_MAP);
        array_push($bindings, ...$filterBindings);

        $orderSql = ' ORDER BY s.tms DESC ';
        if (!empty($sort)) {
            $sortColumn = self::CAMPO_MAP[$sort[0]['name'] ?? ''] ?? null;
            if ($sortColumn !== null) {
                $dir = (($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC';
                $orderSql = " ORDER BY $sortColumn $dir";
            }
        }

        $extrafieldDefs = $db->table('extrafields')
            ->where('elementtype', 'societe')
            ->orderBy('pos')
            ->get(['name', 'label', 'type', 'enabled', 'param']);

        $extraSelects = $extrafieldDefs
            ->map(fn ($d) => "ef.`{$d->name}` AS ef_{$d->name}")
            ->implode(', ');

        $joins = "
            FROM {$prefix}societe s
            LEFT JOIN {$prefix}societe s2 ON s.parent = s2.rowid
            LEFT JOIN {$prefix}societe_extrafields ef ON s.rowid = ef.fk_object
            LEFT JOIN {$prefix}c_country country ON country.rowid = s.fk_pays
            LEFT JOIN {$prefix}c_typent typent ON typent.id = s.fk_typent
            LEFT JOIN {$prefix}c_effectif staff ON staff.id = s.fk_effectif
            LEFT JOIN {$prefix}c_departements state ON state.rowid = s.fk_departement
            LEFT JOIN {$prefix}c_regions region ON region.code_region = state.fk_region
            LEFT JOIN {$prefix}c_stcomm st ON s.fk_stcomm = st.id
        ";

        // Orden importa: debe calzar con el orden en que se hizo array_push($bindings, ...)
        // arriba (sqlDate, luego extraSql -categorías/naturaleza-, luego filterBindings),
        // si no los "?" quedan atados a los valores equivocados.
        $whereSql = "WHERE 1=1 $sqlDate $extraSql $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(DISTINCT s.rowid) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT s.rowid AS idTercero, s.nom AS nombreTercero, s.name_alias,
                s.code_client, s.code_fournisseur, s.code_compta, s.code_compta_fournisseur,
                s.address, s.zip, s.town,
                state.nom AS departamento, region.nom AS region, country.code_iso,
                s.email, s.url, s.phone, s.fax,
                typent.libelle AS tipoTercero, staff.id AS empleados,
                s.siren AS prof1, s.siret AS prof2, s.ape AS prof3,
                s.idprof4 AS prof4, s.idprof5 AS prof5, s.idprof6 AS prof6,
                s.tva_intra AS rut, s.client, s.fournisseur, s.fk_stcomm,
                s2.nom AS sedePrincipal,
                s.datec, s.tms, s.status, s.import_key".
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
                // También plano en la fila (ef_<name>): lo necesita tanto el
                // export (dataField) como, en el grid, la misma lógica de
                // mapeo que ya hace processResponse en el JS.
                $flatExtras['ef_'.$def->name] = $value;
            }

            $rowData[] = $flatExtras + [
                's_rowid' => $obj->idTercero,
                's_nom' => $obj->nombreTercero,
                's_name_alias' => $obj->name_alias,
                's_code_client' => $obj->code_client,
                's_code_fournisseur' => $obj->code_fournisseur,
                's_code_compta' => $obj->code_compta,
                's_code_compta_fournisseur' => $obj->code_compta_fournisseur,
                's_address' => $obj->address,
                's_zip' => $obj->zip,
                's_town' => $obj->town,
                'state_nom' => $obj->departamento,
                'region_nom' => $obj->region,
                'country_code_iso' => $obj->code_iso,
                's_email' => $obj->email,
                's_url' => $obj->url,
                's_phone' => $obj->phone,
                's_fax' => $obj->fax,
                'typent_libelli' => $obj->tipoTercero,
                'staff_id' => $obj->empleados,
                's_siren' => $obj->prof1,
                's_siret' => $obj->prof2,
                's_ape' => $obj->prof3,
                's_idprof4' => $obj->prof4,
                's_idprof5' => $obj->prof5,
                's_idprof6' => $obj->prof6,
                's_tva_intra' => $obj->rut,
                's_client' => $obj->client,
                's_fournisseur' => $obj->fournisseur,
                's_fk_stcomm' => $obj->fk_stcomm,
                's2_nom' => $obj->sedePrincipal,
                's_datec' => $obj->datec,
                's_tms' => $obj->tms,
                's_status' => $obj->status,
                's_import_key' => $obj->import_key,
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

    /**
     * Saca el pseudo-campo "naturaleza" del árbol where (no es una columna
     * SQL real, se resuelve como condición sobre client/fournisseur). Solo
     * mira un nivel de profundidad, igual que el legado.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function extractNaturaleza(array $where): array
    {
        $naturalezaVals = [];
        $newWhere = [];

        foreach ($where as $wItem) {
            if (!empty($wItem['isComplex']) && !empty($wItem['predicates']) && is_array($wItem['predicates'])) {
                $newPreds = [];
                foreach ($wItem['predicates'] as $pred) {
                    if (empty($pred['isComplex']) && ($pred['field'] ?? null) === 'naturaleza') {
                        array_push($naturalezaVals, ...(array) ($pred['value'] ?? []));
                    } else {
                        $newPreds[] = $pred;
                    }
                }
                if (!empty($newPreds)) {
                    $wItem['predicates'] = $newPreds;
                    $newWhere[] = $wItem;
                }
            } elseif (($wItem['field'] ?? null) === 'naturaleza') {
                array_push($naturalezaVals, ...(array) ($wItem['value'] ?? []));
            } else {
                $newWhere[] = $wItem;
            }
        }

        $naturalezaVals = array_values(array_unique(array_filter(
            $naturalezaVals,
            fn ($v) => $v !== ''
        )));

        return [$newWhere, $naturalezaVals];
    }
}
