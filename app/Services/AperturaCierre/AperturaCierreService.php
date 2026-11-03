<?php

namespace App\Services\AperturaCierre;

use App\Models\Configuracion;
use App\Models\CuentaBancaria;
use App\Models\DolibarrUser;
use App\Models\Factura;
use App\Models\PosEntradaSalida;
use App\Models\TerminalAperturaCierre;
use App\Models\TerminalPos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AperturaCierreService
{
    /**
     * Registra la apertura (entrada) de caja de un terminal.
     *
     * Reglas (equivalentes a la funcionalidad de octubre 2025):
     *  - Si viene "fk_caja_local" y ya existe una apertura con ese ref_local,
     *    se devuelve la existente (comportamiento idempotente, 200).
     *  - Si no viene "fk_caja_local", se rechaza (403) si la caja del usuario
     *    ya esta abierta (estado = 1).
     *  - En otro caso se inserta la apertura y se devuelve el registro creado.
     */
    public function entradaCaja(Request $request): JsonResponse
    {
        $data = $request->all();

        $refLocal = trim((string) ($data['fk_caja_local'] ?? ''));

        // Si viene fk_caja_local, verificar si ya existe -> retornar 200 (idempotente)
        if ($refLocal !== '') {
            $existente = TerminalAperturaCierre::query()
                ->where('ref_local', $refLocal)
                ->first();

            if ($existente) {
                return response()->json([
                    'status'         => 0,
                    'message'        => 'Apertura de caja ya existe',
                    'rowid'          => (int) $existente->rowid,
                    'fk_user'        => (int) $existente->fk_user,
                    'fecha_apertura' => $existente->fecha_apertura,
                    'base'           => (float) $existente->base,
                    'estado'         => (int) $existente->estado,
                    'ref_local'      => $existente->ref_local,
                    'id_almacen'     => (int) $existente->fk_warehouse,
                    'id_turno'       => (int) $existente->fk_turno,
                ], 200);
            }
        }

        // Sin fk_caja_local: verificar si la caja del usuario ya esta abierta
        if ($refLocal === '') {
            $abierta = TerminalAperturaCierre::query()
                ->where('fk_user', $data['idTerminal'] ?? null)
                ->where('estado', 1)
                ->first();

            if ($abierta) {
                return response()->json([
                    'error' => "La caja ya se encuentra abierta [{$abierta->fecha_apertura}]",
                ], 403);
            }
        }

        $fechaApertura = $this->normalizarFecha($data['fecha'] ?? null);
        $deviceApe     = !empty($data['deviceid']) ? $data['deviceid'] : '';

        // Almacen/sucursal y turno con los que el cajero abre su caja. Se guardan
        // una sola vez aqui (por turno de caja) para que sp_pos_crear_ticket_v1
        // pueda sacar el almacen de esta apertura en vez de pedirlo en cada venta.
        $idAlmacen = (int) ($data['id_almacen'] ?? 0);
        $idTurno   = (int) ($data['id_turno'] ?? 0);

        $apertura = new TerminalAperturaCierre();
        $apertura->fk_user              = $data['fk_user'] ?? null;
        $apertura->fecha_apertura       = $fechaApertura;
        $apertura->base                 = $data['base'] ?? null;
        $apertura->fecha_cierre         = null;
        $apertura->cierre_efectivo_caja = null;
        $apertura->total_ventas         = null;
        $apertura->ventas_medios        = '[]';
        $apertura->estado               = 1;
        $apertura->fk_user_recibe       = null;
        $apertura->ref_local            = $refLocal;
        $apertura->device_ape           = $deviceApe;
        $apertura->fk_warehouse         = $idAlmacen > 0 ? $idAlmacen : null;
        $apertura->fk_turno             = $idTurno > 0 ? $idTurno : null;

        if (!$apertura->save()) {
            return response()->json([
                'error' => 'Imposible registrar apertura de caja [ERROR INSERT SQL]',
            ], 403);
        }

        return response()->json([
            'status'         => 0,
            'message'        => 'Apertura de caja registrada correctamente',
            'fk_user'        => (int) ($data['fk_user'] ?? 0),
            'fecha_apertura' => $fechaApertura,
            'base'           => (float) ($data['base'] ?? 0),
            'estado'         => 1,
            'ref_local'      => $refLocal,
            'id_almacen'     => $idAlmacen,
            'id_turno'       => $idTurno,
            'fk_apertura'      => $apertura->rowid,
        ], 200);
    }

    /**
     * Genera el resumen de cierre de caja de la apertura activa de un terminal.
     */
    public function getCierreCaja(Request $request): JsonResponse
    {
        $idTerminal = (int) $request->input('idTerminal');
        $device     = trim((string) $request->input('device', ''));

        $aperturaQuery = fn () => TerminalAperturaCierre::query()
            ->with(['almacen', 'turno'])
            ->where('fk_user', $idTerminal)
            ->where('estado', 1);

        $apertura = null;
        if ($device !== '') {
            $apertura = $aperturaQuery()->where('device_ape', $device)->orderByDesc('rowid')->first();
        }

        if (!$apertura) {
            $apertura = $aperturaQuery()->orderByDesc('rowid')->first();
        }

        if (!$apertura) {
            return response()->json([
                'error' => 'NO SE ENCONTRÓ APERTURA DE CAJA ACTIVA',
            ], 403);
        }

        $terminal = (int) (TerminalPos::query()
            ->where('fk_soc', $idTerminal)
            ->orderByDesc('rowid')
            ->value('rowid') ?? 0);

        $fechaAperturaCompleta = $apertura->fecha_apertura;
        $fechaAperturaDia      = (new \DateTime($fechaAperturaCompleta))->format('Y-m-d');
        $ahora                 = now()->format('Y-m-d H:i:s');
        $hoy                   = now()->format('Y-m-d');

        $metodos = CuentaBancaria::query()
            ->where('clos', 0)
            ->get(['rowid', 'label'])
            ->mapWithKeys(fn ($cuenta) => [(int) $cuenta->rowid => $cuenta->label])
            ->all();

        // Ventas de la sesión (día de la apertura), solo facturas validadas/pagadas
        $facturasSesion = Factura::query()
            ->where('pos_source', $terminal)
            ->whereIn('type', [0, 2])
            ->whereIn('fk_statut', [1, 2])
            ->where('datef', $fechaAperturaDia)
            ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'metodoPago', 'regreso')])
            ->get(['rowid', 'total_ttc']);

        $totalVentas    = 0.0;
        $mEfectivo      = 0.0;
        $mTransferencia = 0.0;
        $acumMetodos    = [];

        foreach ($facturasSesion as $factura) {
            $totalVentas += (float) $factura->total_ttc;

            $pagos = json_decode($factura->extrafields->metodoPago ?? '', true);
            if (!is_array($pagos)) {
                continue;
            }

            foreach ($pagos as $pago) {
                $idMetodo = (int) ($pago['idMetodo'] ?? 0);
                $valor    = (float) ($pago['valor'] ?? 0);
                $label    = strtoupper($metodos[$idMetodo] ?? '');

                if (str_contains($label, 'EFEC')) {
                    $mEfectivo      += $valor;
                    $valorRegresado = (float) ($factura->extrafields->regreso ?? 0);
                } else {
                    $mTransferencia += $valor;
                    $valorRegresado = 0.0;
                }

                $acumMetodos[$idMetodo] = ($acumMetodos[$idMetodo] ?? 0.0) + $valor - $valorRegresado;
            }
        }

        $ventasTotales = [];
        foreach ($acumMetodos as $idMetodo => $total) {
            $ventasTotales[] = [
                'idMetodo' => $idMetodo,
                'tipo'     => $metodos[$idMetodo] ?? 'Sin método',
                'valor'    => $total,
            ];
        }

        // Cantidad y valor de facturas por tipo (día actual)
        $facturasHoy = Factura::query()
            ->where('pos_source', $terminal)
            ->where('datef', $hoy)
            ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'tipo_fac')])
            ->get(['rowid', 'type', 'total_ttc']);

        $cantidadFacturas = [
            'POS'           => ['cantidad' => 0, 'valor' => 0.0],
            'ELECTRONICAS'  => ['cantidad' => 0, 'valor' => 0.0],
            'BORRADOR'      => ['cantidad' => 0, 'valor' => 0.0],
            'NOTAS_CREDITO' => ['cantidad' => 0, 'valor' => 0.0],
        ];

        foreach ($facturasHoy as $factura) {
            $tipo     = (int) $factura->type;
            $totalTtc = (float) $factura->total_ttc;

            if ($tipo === 2) {
                $cantidadFacturas['NOTAS_CREDITO']['cantidad']++;
                $cantidadFacturas['NOTAS_CREDITO']['valor'] += $totalTtc;
                continue;
            }

            if ($tipo === 0) {
                $clave = match ((int) ($factura->extrafields->tipo_fac ?? 0)) {
                    2 => 'POS',
                    1 => 'ELECTRONICAS',
                    3 => 'BORRADOR',
                    default => null,
                };

                if ($clave !== null) {
                    $cantidadFacturas[$clave]['cantidad']++;
                    $cantidadFacturas[$clave]['valor'] += $totalTtc;
                }
            }
        }

        // Entradas y salidas de caja registradas desde la apertura
        $movimientos = PosEntradaSalida::query()
            ->where('fk_usuario', $idTerminal)
            ->whereBetween('fecha', [$fechaAperturaCompleta, $ahora])
            ->groupBy('tipo')
            ->get(['tipo', DB::raw('SUM(monto) as total')]);

        $totalEntradas = 0.0;
        $totalSalidas  = 0.0;
        foreach ($movimientos as $movimiento) {
            if ((int) $movimiento->tipo === 1) {
                $totalEntradas = (float) $movimiento->total;
            }
            if ((int) $movimiento->tipo === 2) {
                $totalSalidas = (float) $movimiento->total;
            }
        }

        return response()->json([
            'totalVentas'       => $totalVentas,
            'totalAperturaCaja' => (float) $apertura->base,
            'fechaApertura'     => $fechaAperturaCompleta,
            'ref_local'         => $apertura->ref_local,
            'fk_apertura'      => $apertura->rowid,
            'almacen'           => $apertura->almacen?->ref,
            'turno'             => $apertura->turno?->nombre,
            'sucursal'          => $this->nombreSucursal($apertura->fk_warehouse ? (int) $apertura->fk_warehouse : null),
            'device_ape'        => $apertura->device_ape ?? '',
            'observacion'       => $apertura->observacion ?? null,
            'mEfectivo'         => $mEfectivo,
            'mTransferencia'    => $mTransferencia,
            'totalEntradas'     => $totalEntradas,
            'totalSalidas'      => $totalSalidas,
            'ventasTotales'     => $ventasTotales,
            'cantidadFacturas'  => $cantidadFacturas,
        ], 200);
    }

    /**
     * Lista las cajas (aperturas/cierres) del día actual para un usuario,
     * con el device_id asociado (tabla legacy integros_user_token).
     */
    public function cajasHoy(Request $request): JsonResponse
    {
        $fkUser = (int) ($request->input('fk_user') ?? 0);

        if (!$fkUser) {
            return response()->json([
                'error' => 'El campo fk_user es requerido',
            ], 400);
        }

        $rows = TerminalAperturaCierre::query()
            ->leftJoin('integros_user_token as iut', 'iut.fk_user', '=', 'integrospos_terminal_aperturacierre.fk_user')
            ->leftJoin('entrepot as alm', 'alm.rowid', '=', 'integrospos_terminal_aperturacierre.fk_warehouse')
            ->leftJoin('integrospos_turnos as tur', 'tur.rowid', '=', 'integrospos_terminal_aperturacierre.fk_turno')
            ->where('integrospos_terminal_aperturacierre.fk_user', $fkUser)
            ->whereDate('integrospos_terminal_aperturacierre.fecha_apertura', now()->toDateString())
            ->orderBy('integrospos_terminal_aperturacierre.rowid')
            ->get([
                'integrospos_terminal_aperturacierre.rowid',
                'integrospos_terminal_aperturacierre.ref_local',
                'integrospos_terminal_aperturacierre.fecha_apertura',
                'integrospos_terminal_aperturacierre.fecha_cierre',
                'integrospos_terminal_aperturacierre.estado',
                'integrospos_terminal_aperturacierre.fk_warehouse',
                'iut.device_id as deviceid',
                'alm.ref as almacen',
                'tur.nombre as turno',
            ]);

        $resultado = $rows->map(fn ($row) => [
            'ref_local'      => $row->ref_local,
            'fk_apertura'      => $row->rowid,
            'fecha_apertura' => $row->fecha_apertura,
            'fecha_cierre'   => $row->fecha_cierre,
            'estado'         => (int) $row->estado,
            'deviceid'       => $row->deviceid ?? '',
            'almacen'        => $row->almacen,
            'turno'          => $row->turno,
            'sucursal'       => $this->nombreSucursal($row->fk_warehouse ? (int) $row->fk_warehouse : null),
        ])->all();

        return response()->json($resultado, 200);
    }

    /**
     * Lista los movimientos (entradas/salidas de caja) filtrados por caja local y/o usuario.
     */
    public function movimientos(Request $request): JsonResponse
    {
        $fkCajaLocal = trim((string) ($request->input('fk_caja_local') ?? ''));
        $byUser      = (int) ($request->input('byUser') ?? 0);
        $cerrado     = $request->input('cerrado') === true;

        if ($fkCajaLocal === '' && !$byUser) {
            return response()->json([
                'error' => 'Se requiere fk_caja_local o byUser',
            ], 400);
        }

        $query = PosEntradaSalida::query()
            ->leftJoin('societe as s', 's.rowid', '=', 'pos_entrada_salidas.fk_client')
            ->leftJoin('product as p', 'p.rowid', '=', 'pos_entrada_salidas.fk_product')
            ->leftJoin('integrospos_terminal_aperturacierre as ita', 'ita.rowid', '=', 'pos_entrada_salidas.fk_apertura');

        if ($fkCajaLocal !== '') {
            $apertura = TerminalAperturaCierre::query()
                ->where('ref_local', $fkCajaLocal)
                ->first(['rowid']);

            if (!$apertura) {
                return response()->json([
                    'error' => 'No se encontró la caja con ref_local: '.$fkCajaLocal,
                ], 404);
            }

            $query->where('pos_entrada_salidas.fk_apertura', (int) $apertura->rowid);
        }

        if ($byUser) {
            $query->where('pos_entrada_salidas.fk_usuario', $byUser);
        }

        if ($cerrado) {
            $query->where('ita.estado', 1);
        }

        $rows = $query
            ->orderBy('pos_entrada_salidas.fecha')
            ->select([
                'pos_entrada_salidas.id',
                'pos_entrada_salidas.ref',
                'pos_entrada_salidas.monto',
                'pos_entrada_salidas.tipo',
                'pos_entrada_salidas.fecha',
                'pos_entrada_salidas.descripcion',
                'pos_entrada_salidas.ref_local',
                'pos_entrada_salidas.fk_client',
                'pos_entrada_salidas.fk_product',
                'pos_entrada_salidas.metodos_pago',
                's.nom as nombre_cliente',
                'p.label as label_product',
                'ita.ref_local as fk_caja_local',
            ])
            ->get();

        $movimientos = $rows->map(fn ($row) => [
            'id'             => (int) $row->id,
            'ref'            => $row->ref ?? '',
            'monto'          => (float) $row->monto,
            'tipo'           => (int) $row->tipo,
            'fecha'          => $row->fecha,
            'descripcion'    => $row->descripcion ?? '',
            'ref_local'      => $row->ref_local ?? '',
            'fk_client'      => (int) $row->fk_client,
            'nombre_cliente' => $row->nombre_cliente ?? '',
            'fk_product'     => $row->fk_product !== null ? (int) $row->fk_product : null,
            'label_product'  => $row->label_product ?? '',
            'fk_caja_local'  => $row->fk_caja_local ?? '',
            'metodos_pago'   => !empty($row->metodos_pago) ? json_decode($row->metodos_pago, true) : [],
        ])->all();

        return response()->json([
            'fk_caja_local' => $fkCajaLocal,
            'total'         => count($movimientos),
            'movimientos'   => $movimientos,
        ], 200);
    }

    /**
     * Agrega una observación al historial (log) de una caja, identificada por su ref_local.
     */
    public function cajaObservacion(Request $request): JsonResponse
    {
        $fkCajaLocal = trim((string) ($request->input('fk_caja_local') ?? ''));
        $observacion = trim((string) ($request->input('observacion') ?? ''));
        $deviceid    = trim((string) ($request->input('device') ?? ''));

        if ($fkCajaLocal === '' || $observacion === '') {
            return response()->json([
                'error' => 'Se requieren fk_caja_local y observacion',
            ], 400);
        }

        $apertura = TerminalAperturaCierre::query()
            ->where('ref_local', $fkCajaLocal)
            ->first(['rowid', 'observacion']);

        if (!$apertura) {
            return response()->json([
                'error' => 'No se encontró la caja con ref_local: '.$fkCajaLocal,
            ], 404);
        }

        $log = [];
        if (!empty($apertura->observacion)) {
            $decoded = json_decode($apertura->observacion, true);
            if (is_array($decoded)) {
                $log = $decoded;
            }
        }

        $log[] = [
            'obs'      => $observacion,
            'fecha'    => now()->format('Y-m-d H:i:s'),
            'deviceid' => $deviceid,
        ];

        $apertura->observacion = json_encode($log, JSON_UNESCAPED_UNICODE);

        if (!$apertura->save()) {
            return response()->json([
                'error' => 'Error al actualizar la observación',
            ], 500);
        }

        return response()->json([
            'status'      => 0,
            'message'     => 'Observación registrada correctamente',
            'observacion' => $log,
        ], 200);
    }

    /**
     * Cierra la caja (apertura activa) de un terminal y devuelve el resumen completo del cierre.
     */
    public function cierreCaja(Request $request): JsonResponse
    {
        $data = $request->all();

        $fkCajaLocal = trim((string) ($data['fk_caja_local'] ?? ''));
        $fkUser      = (int) ($data['fk_user'] ?? 0);

        
        $apertura = TerminalAperturaCierre::query()
            ->where('ref_local', $fkCajaLocal)
            ->first();
        
        if (!$apertura) {
            return response()->json([
                'error' => 'No se encontró ninguna caja abierta para este usuario',
            ], 401);
        }

        $deviceCierre      = !empty($data['deviceid'])            ? $data['deviceid']            : '';
        $fechaCierre       = $this->normalizarFecha($data['fecha'] ?? null);
        $montoCajero       = isset($data['cajero'])                ? (float) $data['cajero']       : null;
        $efectivoEntregado = isset($data['efectivo_entregado'])    ? (float) $data['efectivo_entregado'] : null;
        $apertura->datos                = $data['datos'];
        $apertura->fecha_cierre         = $fechaCierre;
        $apertura->cierre_efectivo_caja = 0;
        $apertura->total_ventas         = $data['cierreCaja']['totalVentas'] ?? 0;
        $apertura->ventas_medios        = json_encode($data['cierreCaja']['ventasTotales'] ?? []);
        $apertura->estado               = 2;
        $apertura->device_cierre        = $deviceCierre;
        $apertura->monto_contado_cajero = $montoCajero;
        $apertura->efectivo_entregado   = $efectivoEntregado;
        
        if (!$apertura->save()) {
            return response()->json([
                'error' => 'Imposible registrar cierre de caja [ERROR INSERT SQL]',
            ], 403);
        }

        $nombreEmpresa = Configuracion::valores(['MAIN_INFO_SOCIETE_NOM'])['MAIN_INFO_SOCIETE_NOM'] ?? '';

        return response()->json([
            'status'               => 0,
            'message'              => 'Cierre de caja realizado correctamente',
            'error'                => 'Cierre de caja realizado correctamente', // compatibilidad legacy
            'rowid'                => (int) $apertura->rowid,
            'nombreEmpresa'        => $nombreEmpresa,
            'monto_contado_cajero' => $montoCajero,
            'id_almacen'           => (int) $apertura->fk_warehouse,
            'id_turno'             => (int) $apertura->fk_turno,
            'cierre'               => $this->obtenerDatosCierreApertura((int) $apertura->rowid),
        ], 200);
    }

    /**
     * Resumen completo (facturas, medios de pago, entradas/salidas) de una apertura/cierre.
     * Réplica de CierresApi::obtenerDatosCierreApertura().
     */
    private function obtenerDatosCierreApertura(int $rowid): ?array
    {
        if (!$rowid) {
            return null;
        }

        $apertura = TerminalAperturaCierre::query()->with(['almacen', 'turno'])->find($rowid);
        if (!$apertura) {
            return null;
        }

        $usuario       = DolibarrUser::query()->find((int) $apertura->fk_user, ['firstname', 'lastname']);
        $nombreUsuario = $usuario?->full_name ?? '';

        $posSource  = (int) $apertura->fk_user;
        $fmtFechaAp = substr($apertura->fecha_apertura, 0, 10);
        $fmtFechaCi = $apertura->fecha_cierre ? substr($apertura->fecha_cierre, 0, 10) : '(en curso)';

        $metodosBanco = CuentaBancaria::query()
            ->orderByRaw("(ref = 'LIQ') DESC")
            ->orderBy('ref')
            ->get(['rowid', 'label'])
            ->map(fn ($m) => ['id' => (int) $m->rowid, 'metodo' => $m->label])
            ->all();

        $metodosBancoMap = [];
        foreach ($metodosBanco as $m) {
            $metodosBancoMap[$m['id']] = $m['metodo'];
        }

        $resolverMetodo = function ($metodoPagoJson) use ($metodosBanco) {
            $pagos = json_decode((string) $metodoPagoJson, true);
            if (!is_array($pagos)) {
                return '';
            }

            $partes = [];
            foreach ($pagos as $pago) {
                $partes[] = $this->resolverMetodoPorId($metodosBanco, (int) ($pago['idMetodo'] ?? 0));
            }

            return implode(' | ', array_filter($partes));
        };

        $metodosPorTipo = [];
        $mEfectivo      = 0.0;
        $mTransferencia = 0.0;

        $acumPagos = function ($metodoPagoJson) use ($metodosBancoMap, &$metodosPorTipo, &$mEfectivo, &$mTransferencia) {
            $pagos = json_decode((string) $metodoPagoJson, true);
            if (!is_array($pagos)) {
                return;
            }

            foreach ($pagos as $pago) {
                $idMetodo      = (int) ($pago['idMetodo'] ?? 0);
                $labelOriginal = $metodosBancoMap[$idMetodo] ?? ('ID '.$idMetodo);
                $label         = strtoupper($labelOriginal);
                $valor         = (float) ($pago['valor'] ?? 0);

                if (str_contains($label, 'EFEC')) {
                    $mEfectivo += $valor;
                } else {
                    $mTransferencia += $valor;
                }

                $metodosPorTipo[$labelOriginal] = ($metodosPorTipo[$labelOriginal] ?? 0.0) + $valor;
            }
        };

        $facturasPorApertura = function (int $tipoFac) use ($rowid) {
            return Factura::query()
                ->where('type', 0)
                ->whereHas('extrafields', fn ($q) => $q->where('fk_apertura', $rowid)->where('tipo_fac', $tipoFac))
                ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'metodoPago')])
                ->orderBy('date_valid')
                ->orderBy('rowid')
                ->get(['rowid', 'ref', 'date_valid', 'total_ttc']);
        };

        $formatearFactura = function ($factura) use ($resolverMetodo) {
            return [
                'ref'       => $factura->ref,
                'fecha_fmt' => $factura->date_valid ? (new \DateTime($factura->date_valid))->format('d/m/Y') : null,
                'total_ttc' => $factura->total_ttc,
                'metodo'    => $resolverMetodo($factura->extrafields->metodoPago ?? null),
            ];
        };

        // Facturas POS (tipo_fac=2)
        $facturasPos = [];
        $totalPos    = 0.0;
        foreach ($facturasPorApertura(2) as $factura) {
            $facturasPos[] = $formatearFactura($factura);
            $totalPos     += (float) $factura->total_ttc;
            $acumPagos($factura->extrafields->metodoPago ?? null);
        }

        // Facturas Electrónicas (tipo_fac=1)
        $facturasElec = [];
        $totalElec    = 0.0;
        foreach ($facturasPorApertura(1) as $factura) {
            $facturasElec[] = $formatearFactura($factura);
            $totalElec     += (float) $factura->total_ttc;
            $acumPagos($factura->extrafields->metodoPago ?? null);
        }

        // Facturas Borrador (tipo_fac=3)
        $facturasBorrador = [];
        $totalBorrador    = 0.0;
        foreach ($facturasPorApertura(3) as $factura) {
            $facturasBorrador[] = $formatearFactura($factura);
            $totalBorrador     += (float) $factura->total_ttc;
            $acumPagos($factura->extrafields->metodoPago ?? null);
        }

        // Devoluciones / Notas crédito (type=2)
        $devoluciones = [];
        $totalDev     = 0.0;
        $facturasDev  = Factura::query()
            ->where('type', 2)
            ->whereHas('extrafields', fn ($q) => $q->where('fk_apertura', $rowid))
            ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'metodoPago')])
            ->orderBy('date_valid')
            ->orderBy('rowid')
            ->get(['rowid', 'ref', 'date_valid', 'total_ttc']);

        foreach ($facturasDev as $factura) {
            $devoluciones[] = $formatearFactura($factura);
            $totalDev      += abs((float) $factura->total_ttc);
        }

        // Entradas y salidas de caja
        $resolverMetodoES = function ($metodosPagoJson) use ($metodosBancoMap) {
            $mpv = json_decode((string) $metodosPagoJson, true);
            if (!is_array($mpv)) {
                return '';
            }

            if (isset($mpv['idMetodo'])) {
                $mpv = [$mpv];
            }

            $partes = [];
            foreach ($mpv as $pago) {
                if (!is_array($pago)) {
                    continue;
                }

                if (!empty($pago['idMetodo'])) {
                    $label = $metodosBancoMap[(int) $pago['idMetodo']] ?? '';
                    if ($label !== '') {
                        $partes[] = $label;
                        continue;
                    }
                }

                $label = $pago['nombre'] ?? $pago['metodo'] ?? $pago['tipo'] ?? $pago['name'] ?? '';
                if ($label !== '') {
                    $partes[] = $label;
                }
            }

            return implode(' | ', $partes);
        };

        $mEntradas       = 0.0;
        $mSalidas        = 0.0;
        $entradasSalidas = [];

        $movimientos = PosEntradaSalida::query()
            ->where('fk_apertura', $rowid)
            ->orderBy('fecha')
            ->get(['tipo', 'monto', 'fecha', 'descripcion', 'metodos_pago']);

        foreach ($movimientos as $movimiento) {
            $tipo  = (int) $movimiento->tipo;
            $monto = (float) $movimiento->monto;

            if ($tipo === 1) {
                $mEntradas += $monto;
            }
            if ($tipo === 2) {
                $mSalidas += $monto;
            }

            $entradasSalidas[] = [
                'tipo'          => $tipo,
                'monto'         => $monto,
                'fecha_fmt'     => $movimiento->fecha ? (new \DateTime($movimiento->fecha))->format('d/m/Y H:i') : null,
                'descripcion'   => $movimiento->descripcion ?? '',
                'metodo_nombre' => !empty($movimiento->metodos_pago) ? $resolverMetodoES($movimiento->metodos_pago) : '',
            ];
        }

        // Para sesiones cerradas usar ventas_medios guardado como fuente de verdad
        // de metodos de pago, en vez de recalcular desde las facturas.
        if ($apertura->estado == 0) {
            $ventasMediosStored = json_decode($apertura->ventas_medios ?: '[]', true);
            if (is_array($ventasMediosStored)) {
                $metodosPorTipo = [];
                $mEfectivo      = 0.0;
                $mTransferencia = 0.0;

                foreach ($ventasMediosStored as $vm) {
                    $idMetodo      = (int) ($vm['idMetodo'] ?? 0);
                    $labelOriginal = $metodosBancoMap[$idMetodo] ?? ('ID '.$idMetodo);
                    $label         = strtoupper($labelOriginal);
                    $valor         = (float) ($vm['valor'] ?? 0);

                    if (str_contains($label, 'EFEC')) {
                        $mEfectivo += $valor;
                    } else {
                        $mTransferencia += $valor;
                    }

                    $metodosPorTipo[$labelOriginal] = ($metodosPorTipo[$labelOriginal] ?? 0.0) + $valor;
                }
            }
        }

        $totalVentasCalculado = $totalPos + $totalElec + $totalBorrador;
        $totalVentasBD        = (float) $apertura->total_ventas;
        $totalVentasEfectivo  = ($apertura->estado == 1) ? $totalVentasCalculado : $totalVentasBD;

        return [
            'rowid'                => (int) $apertura->rowid,
            'fk_user'              => $posSource,
            'usuario'              => $nombreUsuario,
            'almacen'              => $apertura->almacen?->ref,
            'turno'                => $apertura->turno?->nombre,
            'sucursal'             => $this->nombreSucursal($apertura->fk_warehouse ? (int) $apertura->fk_warehouse : null),
            'estado'               => (int) $apertura->estado,
            'fecha_apertura'       => $apertura->fecha_apertura,
            'fecha_cierre'         => $apertura->fecha_cierre,
            'fmtFechaAp'           => $fmtFechaAp,
            'fmtFechaCi'           => $fmtFechaCi,
            'base'                 => (int) $apertura->base,
            'totalVentas'          => $totalVentasEfectivo,
            'totalVentasCalculado' => $totalVentasCalculado,
            'totalVentasBD'        => $totalVentasBD,
            'mEfectivo'            => $mEfectivo,
            'mTransferencia'       => $mTransferencia,
            'mEntradas'            => $mEntradas,
            'mSalidas'             => $mSalidas,
            'facturasPos'          => $facturasPos,
            'facturasElec'         => $facturasElec,
            'facturasBorrador'     => $facturasBorrador,
            'devoluciones'         => $devoluciones,
            'totalPos'             => $totalPos,
            'totalElec'            => $totalElec,
            'totalBorrador'        => $totalBorrador,
            'totalDev'             => $totalDev,
            'ventas_medios_bd'     => $apertura->ventas_medios,
            'metodosPago'          => $metodosPorTipo,
            'monto_contado_cajero' => $apertura->monto_contado_cajero !== null ? (float) $apertura->monto_contado_cajero : null,
            'datos'                => $apertura->datos ? (json_decode($apertura->datos, true) ?: null) : null,
            'entradasSalidas'      => $entradasSalidas,
            'device_ape'           => $apertura->device_ape ?: '',
            'device_cierre'        => $apertura->device_cierre ?: '',
            'observacion'          => $apertura->observacion ?? '',
        ];
    }

    /**
     * Nombre de la "sucursal" (entrepot padre) a la que pertenece un almacen,
     * segun la configuracion de pos_cash_sucursal[_almacen] (variable global
     * SUCURSALES): almacen -> pos_cash_sucursal_almacen.fk_warehouse ->
     * fk_cash_sucursal -> pos_cash_sucursal.fk_warehouse_sucursal -> entrepot.ref.
     * Null si el almacen no esta agrupado bajo ninguna sucursal (o si no viene
     * fk_warehouse).
     */
    private function nombreSucursal(?int $fkWarehouse): ?string
    {
        if (!$fkWarehouse) {
            return null;
        }

        return DB::connection('mysql')
            ->table('pos_cash_sucursal_almacen as pcsa')
            ->join('pos_cash_sucursal as pcs', 'pcs.rowid', '=', 'pcsa.fk_cash_sucursal')
            ->join('entrepot as e', 'e.rowid', '=', 'pcs.fk_warehouse_sucursal')
            ->where('pcsa.fk_warehouse', $fkWarehouse)
            ->value('e.ref');
    }

    /**
     * Busca el label de un metodo de pago por id dentro de una lista [id, metodo].
     * Réplica de obtenerMetodoPorId() (api_facturepos.lib.php).
     */
    private function resolverMetodoPorId(array $metodosBanco, int $idBuscado): string
    {
        foreach ($metodosBanco as $item) {
            if ($item['id'] === $idBuscado) {
                return $item['metodo'];
            }
        }

        return 'Método no encontrado';
    }

    /**
     * Normaliza cualquier formato de fecha aceptado por el cliente (incluido
     * ISO 8601 con milisegundos y sufijo "Z", p.ej. "2026-07-06T16:00:07.089Z")
     * al formato DATETIME de MySQL ("Y-m-d H:i:s"), único que la base de datos acepta.
     */
    private function normalizarFecha(?string $valor): string
    {
        if (empty($valor)) {
            return now()->format('Y-m-d H:i:s');
        }

        try {
            return \Carbon\Carbon::parse($valor)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return now()->format('Y-m-d H:i:s');
        }
    }
}
