<?php

namespace App\Services\Usuarios;

use App\Models\LogErrorEndpoint;
use App\Models\PosAuditoria;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Registro de errores de API (status >= 400) que el POS intercepta con su
 * LoggingClient y sube en el siguiente ciclo de sincronizacion. Ver
 * lib/global/custom_http_client.dart / sync_service.dart del lado del POS.
 */
class LogErrorEndpointService
{
    public function registrar(Request $request): void
    {
        $metodo = strtoupper(trim((string) $request->input('metodo', '')));
        $url = trim((string) $request->input('url', ''));
        $statusCode = $request->input('status_code');
        $fecha = $request->input('fecha');

        if ($metodo === '') {
            abort(401, "El campo 'metodo' es obligatorio.");
        }

        if ($url === '') {
            abort(401, "El campo 'url' es obligatorio.");
        }

        if (! is_numeric($statusCode) || (int) $statusCode < 400) {
            abort(401, "El campo 'status_code' es obligatorio y debe ser >= 400.");
        }

        if (! $fecha) {
            abort(401, "El campo 'fecha' es obligatorio.");
        }

        try {
            $fechaCliente = Carbon::parse($fecha);
        } catch (\Throwable) {
            abort(401, "El campo 'fecha' no es una fecha valida.");
        }

        LogErrorEndpoint::create([
            'deviceid' => $request->input('deviceid'),
            'fk_user' => $request->filled('fk_user') ? (int) $request->input('fk_user') : null,
            'metodo' => $metodo,
            'url' => $url,
            'status_code' => (int) $statusCode,
            'tiempo_ms' => $request->filled('tiempo_ms') ? (int) $request->input('tiempo_ms') : null,
            'request_body' => $request->input('request_body'),
            'response_body' => $request->input('response_body'),
            'fecha_cliente' => $fechaCliente,
        ]);
    }

    /**
     * Evento de auditoria generico (cotizacion, pedido_en_curso, nota_credito, caja,
     * etc. - entidad_tipo es lista abierta) subido desde la cola local de
     * sincronizacion del POS. 'id' es el id LOCAL de SQLite de la terminal: se
     * guarda como id_local (referencia externa), nunca como PK propio, porque se
     * repite entre terminales distintas. sincronizado/intentos/ultimo_error son
     * bookkeeping interno del cliente y se ignoran por completo.
     */
    public function registrarAuditoria(Request $request): array
    {
        $modulo = trim((string) $request->input('modulo', ''));
        $accion = trim((string) $request->input('accion', ''));
        $entidadTipo = trim((string) $request->input('entidad_tipo', ''));
        $fecha = $request->input('fecha');
        $idLocal = $request->filled('id') ? (int) $request->input('id') : null;

        if ($modulo === '') {
            abort(401, "El campo 'modulo' es obligatorio.");
        }

        if ($accion === '') {
            abort(401, "El campo 'accion' es obligatorio.");
        }

        if ($entidadTipo === '') {
            abort(401, "El campo 'entidad_tipo' es obligatorio.");
        }

        if (! $fecha) {
            abort(401, "El campo 'fecha' es obligatorio.");
        }

        try {
            $fechaEvento = Carbon::parse($fecha);
        } catch (\Throwable) {
            abort(401, "El campo 'fecha' no es una fecha valida.");
        }

        PosAuditoria::create([
            'id_local' => $idLocal,
            // Formateado explicito con microsegundos: Eloquent castea el Carbon a
            // string sin ellos al guardar, y la columna es DATETIME(6).
            'fecha_evento' => $fechaEvento->format('Y-m-d H:i:s.u'),
            'modulo' => $modulo,
            'accion' => $accion,
            'usuario_id' => $request->filled('usuario_id') ? (int) $request->input('usuario_id') : null,
            'usuario_nombre' => $request->input('usuario_nombre'),
            'entidad_tipo' => $entidadTipo,
            'entidad_id' => $request->input('entidad_id'),
            'tipo_documento' => $request->input('tipo_documento'),
            'datos_antes_json' => $request->input('datos_antes_json'),
            'datos_despues_json' => $request->input('datos_despues_json'),
            'descripcion' => $request->input('descripcion'),
            'fk_caja_local' => $request->input('fk_caja_local'),
            'total' => $request->input('total'),
            'subtotal' => $request->input('subtotal'),
            'iva' => $request->input('iva'),
            'items' => $request->filled('items') ? (int) $request->input('items') : null,
        ]);

        return ['ok' => true, 'id' => $idLocal];
    }
}
