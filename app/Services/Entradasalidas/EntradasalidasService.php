<?php

namespace App\Services\Entradasalidas;

use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServiceGlobal\ServiceGlobalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntradasalidasService
{
    private const TIPOS_VALIDOS = [1, 2];

    public function __construct(
        private ServiceGlobalService $serviceGlobal,
    ) {}

    /**
     * Productos configurados como servicio de corresponsal bancario
     * (consignacion y/o retiro), agrupados por tipo.
     */
    public function serviciosCorresponsal(): array
    {
        $productos = Producto::query()
            ->where('fk_product_type', 1)
            ->whereHas('extrafields', function ($q) {
                $q->where('consignacion', 1)->orWhere('retiro', 1);
            })
            ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'consignacion', 'retiro')])
            ->orderBy('label')
            ->get(['rowid', 'ref', 'label']);

        $consignacion = [];
        $retiro = [];
        $malConfigurado = [];

        foreach ($productos as $producto) {
            $item = [
                'rowid' => (int) $producto->rowid,
                'ref' => $producto->ref,
                'label' => $producto->label,
            ];

            $esConsignacion = ! empty($producto->extrafields?->consignacion);
            $esRetiro = ! empty($producto->extrafields?->retiro);

            if ($esConsignacion && $esRetiro) {
                $malConfigurado[] = $item;
            } elseif ($esConsignacion) {
                $consignacion[] = $item;
            } elseif ($esRetiro) {
                $retiro[] = $item;
            }
        }

        return [
            'status' => 0,
            'consignacion' => $consignacion,
            'retiro' => $retiro,
            'mal_configurado' => $malConfigurado,
        ];
    }

    /**
     * Registra un movimiento de caja (entrada=1/salida=2, ej. consignacion o
     * retiro de un corresponsal bancario) via sp_pos_entrada_salida_crear,
     * que recibe todo el payload como un unico parametro JSON (ver
     * ejecutarProcedimientoJson) y devuelve status/message en la fila del
     * propio CALL. Esto SOLO crea la bitacora de caja (pos_entrada_salidas);
     * para abonar facturas usar crearReciboCaja() (sp_pos_recibo_caja_crear).
     */
    public function createApp(Request $request, Usuario $usuario): array
    {
        $monto = $request->input('monto');
        $tipo = (int) $request->input('tipo', 0);
        $fkUsuario = $request->input('fk_usuario');
        $descripcion = trim((string) $request->input('descripcion', ''));
        $refLocal = trim((string) $request->input('refLocal', ''));
        $metodosPago = $request->input('metodos_pago');
        $facturasAfectadas = $request->input('facturas_afectadas', []);

        if (! in_array($tipo, self::TIPOS_VALIDOS, true)) {
            abort(401, "El campo 'tipo' debe ser 1 (entrada) o 2 (salida).");
        }

        $payload = [
            'monto' => (float) $monto,
            'tipo' => $tipo,
            'fk_usuario' => (int) $fkUsuario,
            'fk_client' => (int) $request->input('fk_client', 0),
            'descripcion' => $descripcion,
            'facturas_afectadas' => $facturasAfectadas,
            'refLocal' => $refLocal,
            'fk_caja_local' => trim((string) $request->input('fk_caja_local', '')),
            'fk_product' => (int) $request->input('fk_product', 0),
            'metodos_pago' => $metodosPago,
        ];

        try {
            $resultado = $this->serviceGlobal->ejecutarProcedimientoJson('sp_pos_entrada_salida_crear', $payload);
        } catch (\Throwable $e) {
            // Solo se copia a procedimientos.log/portapapeles cuando el SP fallo -
            // hacerlo siempre (exito incluido) hizo crecer el log a 200+MB.
            $this->serviceGlobal->copiarSqlAlPortapapelesJson('sp_pos_entrada_salida_crear', $payload);
            abort(response()->json(['status' => 0, 'message' => $e->getMessage()], 500));
        }

        if ((int) ($resultado['id'] ?? 0) <= 0 || (int) ($resultado['status'] ?? 1) !== 0) {
            $this->serviceGlobal->copiarSqlAlPortapapelesJson('sp_pos_entrada_salida_crear', $payload);
            abort(response()->json($resultado, 400));
        }

        return $resultado;
    }

    /**
     * Crea el recibo de caja (tipo 1=abono a factura, 2=anticipo, 3=libre)
     * via sp_pos_recibo_caja_crear: un unico CALL crea el recibo, lo valida,
     * numera, genera asientos, abona la(s) factura(s) y ajusta el cupo del
     * tercero, y registra la entrada de caja asociada. Sin OUT params, el
     * resultado viene en la fila que devuelve el propio CALL.
     *
     * OJO: sp_pos_entrada_salida_crear (createApp() arriba) SOLO crea la
     * bitacora de caja (pos_entrada_salidas) - no toca facturas ni cupo.
     * Llamarlo por error "funciona" (no tira excepcion) pero no abona nada;
     * la señal de ese bug es una fila nueva en pos_entrada_salidas sin nada
     * nuevo en integros_recibo_caja/paiement/paiement_facture.
     */
    public function crearReciboCaja(Request $request, Usuario $usuario): array
    {
        $fkSoc = $request->input('fk_soc');
        $tipo = (int) $request->input('tipo', 0);
        $amount = $request->input('amount');
        $metodosPago = $request->input('metodos_pago');
        $facturas = $request->input('facturas');
        $lineasLibres = $request->input('lineas_libres');
        $fkUser = $request->input('fk_user');

        if (! $fkSoc) {
            abort(401, "El campo 'fk_soc' es obligatorio.");
        }

        if (! in_array($tipo, [1, 2, 3], true)) {
            abort(401, "El campo 'tipo' debe ser 1 (abono), 2 (anticipo) o 3 (libre).");
        }

        if (! is_numeric($amount) || (float) $amount <= 0) {
            abort(401, "El campo 'amount' es obligatorio y debe ser mayor a 0.");
        }

        if (! is_array($metodosPago) || count($metodosPago) === 0) {
            abort(401, "El campo 'metodos_pago' es obligatorio.");
        }

        if ($tipo === 1 && (! is_array($facturas) || count($facturas) === 0)) {
            abort(401, "El campo 'facturas' es obligatorio cuando 'tipo' es 1 (abono).");
        }

        if ($tipo === 3 && (! is_array($lineasLibres) || count($lineasLibres) === 0)) {
            abort(401, "El campo 'lineas_libres' es obligatorio cuando 'tipo' es 3 (libre).");
        }

        if (! $fkUser) {
            abort(401, "El campo 'fk_user' es obligatorio.");
        }

        $payload = [
            'fk_soc' => (int) $fkSoc,
            'tipo_fk_soc' => $request->filled('tipo_fk_soc') ? (int) $request->input('tipo_fk_soc') : null,
            'tipo' => $tipo,
            'amount' => (float) $amount,
            'observacion' => (string) $request->input('observacion', ''),
            'metodos_pago' => $metodosPago,
            'lineas_libres' => $tipo === 3 ? $lineasLibres : null,
            'facturas' => $tipo === 1 ? $facturas : null,
            'fk_user' => (int) $fkUser,
            'refLocal' => trim((string) $request->input('refLocal', '')),
            'fk_caja_local' => trim((string) $request->input('fk_caja_local', '')),
            'fk_client' => (int) $request->input('fk_client', 0),
        ];

        try {
            $resultado = $this->serviceGlobal->ejecutarProcedimientoJson('sp_pos_recibo_caja_crear', $payload);
        } catch (\Throwable $e) {
            // Solo se copia a procedimientos.log/portapapeles cuando el SP fallo -
            // hacerlo siempre (exito incluido) hizo crecer el log a 200+MB.
            $this->serviceGlobal->copiarSqlAlPortapapelesJson('sp_pos_recibo_caja_crear', $payload);
            abort(response()->json(['status' => 0, 'message' => $e->getMessage()], 500));
        }

        if ((int) ($resultado['status'] ?? 0) !== 1) {
            $this->serviceGlobal->copiarSqlAlPortapapelesJson('sp_pos_recibo_caja_crear', $payload);
            abort(response()->json($resultado, 400));
        }

        return $resultado;
    }

    /**
     * Respaldo/bitacora de entradas_salidas_caja (Flutter), en paralelo al
     * intento de negocio contra entradasalidas/create (createApp() arriba) -
     * no lo reemplaza ni depende de el. Upsert por refLocal via ON DUPLICATE
     * KEY UPDATE: el reintento en background del cliente puede reenviar el
     * mismo registro si la respuesta se perdio aunque el POST si llego.
     */
    public function backupEntradaSalida(Request $request): array
    {
        $refLocal = trim((string) $request->input('refLocal', ''));

        if ($refLocal === '') {
            abort(401, "El campo 'refLocal' es obligatorio.");
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();

        DB::connection('mysql')->statement("
            INSERT INTO {$prefijo}pos_backup_entradas_salidas
                (ref_local, monto, tipo, fk_usuario, fk_client, nombre_cliente, descripcion, facturas_afectadas_json, fecha, id_servicio, metodo_pago_json, datos_server_json, fecha_recibido)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                monto = VALUES(monto),
                tipo = VALUES(tipo),
                fk_usuario = VALUES(fk_usuario),
                fk_client = VALUES(fk_client),
                nombre_cliente = VALUES(nombre_cliente),
                descripcion = VALUES(descripcion),
                facturas_afectadas_json = VALUES(facturas_afectadas_json),
                fecha = VALUES(fecha),
                id_servicio = VALUES(id_servicio),
                metodo_pago_json = VALUES(metodo_pago_json),
                datos_server_json = VALUES(datos_server_json),
                fecha_recibido = NOW()
        ", [
            $refLocal,
            $request->filled('monto') ? (float) $request->input('monto') : null,
            $request->filled('tipo') ? (int) $request->input('tipo') : null,
            $request->filled('fkUsuario') ? (int) $request->input('fkUsuario') : null,
            $request->filled('fkClient') ? (int) $request->input('fkClient') : null,
            $request->input('nombreCliente'),
            $request->input('descripcion'),
            $this->jsonNulable($request->input('facturasAfectadas')),
            $request->input('fecha'),
            $request->filled('idServicio') ? (int) $request->input('idServicio') : null,
            $this->jsonNulable($request->input('metodoPago')),
            $this->jsonNulable($request->input('datosServer')),
        ]);

        return ['ok' => true];
    }

    private function jsonNulable($valor): ?string
    {
        return $valor === null ? null : json_encode($valor);
    }
}
