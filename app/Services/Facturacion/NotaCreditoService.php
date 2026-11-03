<?php

namespace App\Services\Facturacion;

use App\Models\Usuario;
use App\Services\ServiceGlobal\ServiceFuncionesService;
use App\Services\ServiceGlobal\ServiceGlobalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notas credito (/cancelarfactura/) contra sp_pos_nota_credito_crear.
 * El SP resuelve toda la logica (con/sin factura origen); este servicio
 * solo arma los 12 parametros IN y expone los 4 OUT (id_factura, ref,
 * status, message) como arreglo.
 */
class NotaCreditoService
{
    private const CAMPOS_SALIDA = ['id_factura', 'ref', 'status', 'message'];

    public function __construct(
        private ServiceGlobalService $serviceGlobal,
        private ServiceFuncionesService $serviceFunciones,
    ) {}

    public function crear(Request $request, Usuario $usuario): array
    {

        $lineas = $request->input('lines');
        $metodosPago = $request->input('metodoPago');

        $parametros = [
            $request->filled('idTercero') ? (int) $request->input('idTercero') : null,
            $request->filled('idFacture') ? (int) $request->input('idFacture') : null,
            $request->input('ref') ?: null,
            is_array($lineas) ? json_encode($lineas) : null,
            $request->filled('tipo_factura') ? (string) $request->input('tipo_factura') : null,
            $request->input('refLocal'),
            $request->input('fk_caja_local') ?: null,
            is_array($metodosPago) ? json_encode($metodosPago) : null,
            (int) $request->input('fk_bank_terminal', 0),
            (int) $request->input('idwarehouse', 0),
            $request->input('notaPrivada') ?: null,
            (int) $usuario->rowid,
            // Numeracion local: el servidor local (offline, con los datos de
            // resolucion/DIAN que ya le manda getUsersPos) ya calculo el
            // consecutivo y el CUFE/QR el mismo. sp_pos_nota_credito_crear los usa
            // tal cual (no genera un numero ni un CUFE nuevo).
            $request->boolean('numeracionLocal'),
            $request->input('numNcLocal'),
            $request->filled('consecutivoLocal') ? (int) $request->input('consecutivoLocal') : null,
            $request->input('cufeLocal'),
            $request->input('qrLocal'),
            $request->input('resolucionLocal'),
            $request->input('resolucionNumeroLocal'),
            $request->input('softwareIdLocal'),
            $request->input('fechaNcLocal'),
        ];

        try {

            $resultado = $this->serviceGlobal->ejecutarProcedimientoConResultado('sp_pos_nota_credito_crear', $parametros);

        } catch (\Throwable $e) {
            // Solo se copia a procedimientos.log/portapapeles cuando el SP fallo -
            // hacerlo siempre (exito incluido) hizo crecer el log a 200+MB.
            $this->serviceGlobal->copiarSqlAlPortapapeles('sp_pos_nota_credito_crear', $parametros);
            abort(response()->json(['status' => 0, 'message' => $e->getMessage()], 500));
        }

        $idFactura = $resultado['idFactura'] ?? null;

        if ($idFactura === null || (int) $idFactura <= 0) {
            $this->serviceGlobal->copiarSqlAlPortapapeles('sp_pos_nota_credito_crear', $parametros);
            abort(response()->json($resultado, 400));
        }

        return $this->serviceFunciones->datosFactura((int) $idFactura);

        return $resultado;
    }

    /**
     * Respaldo/bitacora de notas_credito_local (Flutter), en paralelo al
     * intento de negocio contra cancelarfactura (crear() arriba) - no lo
     * reemplaza ni depende de que la factura/NC original exista en el ERP.
     * Upsert por refLocal via ON DUPLICATE KEY UPDATE: el reintento en
     * background del cliente puede reenviar el mismo registro si la
     * respuesta se perdio aunque el POST si llego.
     */
    public function backupNotaCredito(Request $request): array
    {
        $refLocal = trim((string) $request->input('refLocal', ''));

        if ($refLocal === '') {
            abort(401, "El campo 'refLocal' es obligatorio.");
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();

        DB::connection('mysql')->statement("
            INSERT INTO {$prefijo}pos_backup_notas_credito
                (ref_local, numero_servidor, fecha, cliente_nombre, total_con_iva, payload_json, datos_server_json, fecha_recibido)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                numero_servidor = VALUES(numero_servidor),
                fecha = VALUES(fecha),
                cliente_nombre = VALUES(cliente_nombre),
                total_con_iva = VALUES(total_con_iva),
                payload_json = VALUES(payload_json),
                datos_server_json = VALUES(datos_server_json),
                fecha_recibido = NOW()
        ", [
            $refLocal,
            $request->input('numeroServidor'),
            $request->input('fecha'),
            $request->input('clienteNombre'),
            $request->filled('totalConIva') ? (float) $request->input('totalConIva') : null,
            $this->jsonNulable($request->input('payload')),
            $this->jsonNulable($request->input('datosServer')),
        ]);

        return ['ok' => true];
    }

    private function jsonNulable($valor): ?string
    {
        return $valor === null ? null : json_encode($valor);
    }
}
