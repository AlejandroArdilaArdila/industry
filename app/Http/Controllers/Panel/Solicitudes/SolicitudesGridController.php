<?php

namespace App\Http\Controllers\Panel\Solicitudes;

use App\Http\Controllers\Controller;
use App\Services\Grids\SyncfusionFilterParser;
use App\Services\Panel\ActividadesService;
use App\Services\Panel\SolicitudesEstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listado de solicitudes, mismo patrón que Facturas/Terceros.
 * Port de api_solicitudes.class.php::getSolicitudesJson (solicitudes_solicitud + cliente + creador).
 * Un usuario que no es administrador solo ve las suyas (mismo campo que usa el seguimiento).
 */
class SolicitudesGridController extends Controller
{
    /** @var array<string, string> Estados de Solicitud::fields['status']. */
    public const ESTADOS = [
        '0' => 'Borrador',
        '1' => 'Activo',
        '2' => 'En solicitud de cotización exterior',
        '3' => 'En solicitud de cotización local',
        '4' => 'En importación',
        '5' => 'Cotización enviada',
        '6' => 'En solicitud de cotización USA',
        '9' => 'Cerrada',
        '10' => 'Orden de compra abierta para pago',
        '11' => 'Orden de compra enviada',
        '12' => 'Orden de compra aprobación solicitada',
        '13' => 'Orden de compra liquidación solicitada',
        '14' => 'Orden de compra liquidada',
        '15' => 'Orden de compra aprobada',
        '16' => 'Orden de compra pagada',
        '17' => 'Orden de compra desembolso solicitado',
        '18' => 'Orden de compra desembolso aprobado',
        '19' => 'Orden de compra devolución registrada',
        '20' => 'Orden de compra cerrada',
        '21' => 'Orden de compra cancelada',
        '22' => 'En cotización exterior',
        '23' => 'En cotización local',
        '24' => 'En cotización USA',
    ];

    /** Campo del grid (field) => columna/expresión SQL real. Para filtros y orden. */
    private const CAMPO_MAP = [
        't_rowid' => 't.rowid',
        't_ref' => 't.ref',
        's_nom' => 's.nom',
        'fecha_solicitud' => 't.fecha_solicitud',
        'mora' => 'DATEDIFF(CURDATE(), DATE(t.date_creation))',
        'asunto' => 't.asunto',
        'envio_email' => 't.envio_email',
        'creadoPor' => "CONCAT_WS(' ', u.firstname, u.lastname)",
        'estado' => 't.status',
        'date_creation' => 't.date_creation',
        'fecha_proximo_contacto' => 't.fecha_proximo_contacto',
        'ultima_respuesta' => 't.ultima_respuesta',
    ];

    public function __construct(private SyncfusionFilterParser $filterParser, private ActividadesService $actividades)
    {
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->all();
        $result = $this->buildRows($data, false, (int) ($data['skip'] ?? 0), (int) ($data['take'] ?? 100));

        return response()->json(['rowData' => $result['rowData'], 'count' => $result['count']]);
    }

    public function exportXlsx(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->all();
        $columnas = array_values($data['columnas'] ?? []);
        $result = $this->buildRows($data, true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Solicitudes');

        foreach ($columnas as $i => $columna) {
            $sheet->setCellValue([$i + 1, 1], $columna['label'] ?? $columna['dataField']);
        }
        $sheet->getStyle([1, 1, max(1, count($columnas)), 1])->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($result['rowData'] as $fila) {
            foreach ($columnas as $i => $columna) {
                $sheet->setCellValue([$i + 1, $rowIndex], (string) ($fila[$columna['dataField']] ?? ''));
            }
            $rowIndex++;
        }

        foreach (range(1, max(1, count($columnas))) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, 'Solicitudes_'.now()->format('Ymd_His').'.xlsx', [
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
            'Content-Disposition' => 'attachment; filename="Solicitudes_'.now()->format('Ymd_His').'.csv"',
        ]);
    }

    /**
     * @return array{rowData: array<int, array<string, mixed>>, count: int}
     */
    private function buildRows(array $data, bool $all, int $skip = 0, int $take = 100): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();
        $usuario = Auth::guard('web')->user();

        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sort = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        [$filterSql, $bindings] = $this->filterParser->parse($where, self::CAMPO_MAP);

        $ambito = '';
        if (! $usuario->isAdmin()) {
            $ambito = ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $bindings[] = $usuario->getKey();
        }

        // Tarjeta elegida arriba del listado: el mismo grupo de estados que cuenta.
        $actividad = (string) ($data['actividad'] ?? '');
        if ($actividad !== '') {
            $ambito .= ' AND '.app(SolicitudesEstadisticasService::class)->condicion($actividad);
        }

        $orderSql = ' ORDER BY t.rowid DESC';
        if (! empty($sort)) {
            $col = self::CAMPO_MAP[$sort[0]['name'] ?? ''] ?? null;
            if ($col !== null) {
                $orderSql = " ORDER BY $col ".((($sort[0]['direction'] ?? '') === 'descending') ? 'DESC' : 'ASC').', t.rowid DESC';
            }
        }

        $joins = "
            FROM {$prefix}solicitudes_solicitud t
            LEFT JOIN {$prefix}societe s ON s.rowid = t.fk_soc
            LEFT JOIN {$prefix}user u ON u.rowid = t.fk_user_creat
        ";
        $whereSql = "WHERE 1=1 $ambito $filterSql";

        $total = (int) ($db->selectOne("SELECT COUNT(*) AS total $joins $whereSql", $bindings)->total ?? 0);

        $sql = "SELECT t.rowid, t.ref, t.fk_soc, s.nom AS s_nom, t.fecha_solicitud, t.date_creation,
                t.fecha_proximo_contacto, t.ultima_respuesta, t.asunto, t.envio_email, t.status,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) AS mora,
                CONCAT_WS(' ', u.firstname, u.lastname) AS creadoPor
            $joins $whereSql $orderSql";
        $dataBindings = $bindings;
        if (! $all) {
            $sql .= ' LIMIT ? OFFSET ?';
            array_push($dataBindings, $take, $skip);
        }

        $rowData = [];
        foreach ($db->select($sql, $dataBindings) as $o) {
            $rowData[] = [
                't_rowid' => $o->rowid,
                't_ref' => $o->ref,
                's_rowid' => $o->fk_soc,
                's_nom' => $o->s_nom,
                'fecha_solicitud' => $this->soloFecha($o->fecha_solicitud),
                'date_creation' => $this->soloFecha($o->date_creation),
                'fecha_proximo_contacto' => $this->soloFecha($o->fecha_proximo_contacto),
                'ultima_respuesta' => $this->soloFecha($o->ultima_respuesta),
                'mora' => max(0, (int) $o->mora),
                'asunto' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $o->asunto), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'envio_email' => $o->envio_email ? 'Enviado' : 'No enviado',
                'creadoPor' => $o->creadoPor,
                'estado' => self::ESTADOS[(string) $o->status] ?? 'Estado '.$o->status,
                'status' => (int) $o->status,
            ];
        }

        return ['rowData' => $rowData, 'count' => $total];
    }

    private function soloFecha(mixed $valor): ?string
    {
        return $valor ? substr((string) $valor, 0, 10) : null;
    }
}
