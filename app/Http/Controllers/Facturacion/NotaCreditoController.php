<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Services\Facturacion\NotaCreditoService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotaCreditoController extends Controller
{
    public function __construct(
        private NotaCreditoService $notaCreditoService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cancelarfactura',
        summary: 'Crea una nota credito, manual o a partir de una factura existente (devolucion/anulacion)',
        security: [['DolApiKey' => []]],
        tags: ['NotaCredito'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refLocal', 'fk_bank_terminal'],
                properties: [
                    new OA\Property(property: 'idFacture', type: 'integer', description: 'Factura origen (con esto o "referencia" es devolucion/anulacion; sin ninguno es nota credito manual)'),
                    new OA\Property(property: 'referencia', type: 'string', description: 'Alternativa a idFacture, busqueda LIKE'),
                    new OA\Property(property: 'customerId', type: 'integer', description: 'Obligatorio solo sin factura origen'),
                    new OA\Property(property: 'tipo_fac', type: 'integer', enum: [1, 2, 3], description: 'Obligatorio solo sin factura origen'),
                    new OA\Property(property: 'refLocal', type: 'string'),
                    new OA\Property(property: 'fk_caja_local', type: 'string', description: 'Apertura activa; obligatoria solo sin factura origen'),
                    new OA\Property(property: 'fk_bank_terminal', type: 'integer', description: 'Caja fija del terminal (c_paiement resuelto por el llamador)'),
                    new OA\Property(property: 'idwarehouse', type: 'integer', description: '0 = no revertir stock', default: 0),
                    new OA\Property(property: 'notaPrivada', type: 'string', description: 'Se ignora si hay factura origen'),
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        description: 'Sin factura origen: obligatorio. Con factura origen: NULL/omitido devuelve todas las lineas originales',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'idProduct', type: 'integer'),
                            new OA\Property(property: 'cant', type: 'number'),
                        ])
                    ),
                    new OA\Property(
                        property: 'metodoPago',
                        type: 'array',
                        description: 'idMetodo es un c_paiement.id directo (no bank_account.rowid)',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'idMetodo', type: 'integer'),
                            new OA\Property(property: 'valor', type: 'number'),
                        ])
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nota credito creada',
                content: new OA\JsonContent(example: [
                    'id_factura' => 9720,
                    'ref' => '(PROV9720)',
                    'status' => 1,
                    'message' => 'OK',
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'refLocal'/'fk_bank_terminal', o 'customerId'/'tipo_fac'/'fk_caja_local'/'lines' cuando no hay factura origen"),
            new OA\Response(response: 400, description: 'El SP rechazo la operacion (status != 1): producto no encontrado, cantidad excedida, etc.'),
        ]
    )]
    public function crearApp(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);
        return response()->json($this->notaCreditoService->crear($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/backup/notaCredito',
        summary: 'Respaldo/bitacora de una nota credito confirmada en el POS offline (Flutter) - llamada adicional, en paralelo al sync de negocio de facturepos/cancelarfactura',
        security: [['DolApiKey' => []]],
        tags: ['Backup'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refLocal'],
                properties: [
                    new OA\Property(property: 'refLocal', type: 'string', example: '12-NC-10072026143210-45'),
                    new OA\Property(property: 'numeroServidor', type: 'string', example: 'NC-9876'),
                    new OA\Property(property: 'fecha', type: 'string', example: '2026-07-10 14:32:10'),
                    new OA\Property(property: 'clienteNombre', type: 'string', example: 'Juan Pérez'),
                    new OA\Property(property: 'totalConIva', type: 'number', example: 45000),
                    new OA\Property(property: 'payload', type: 'object', description: 'Contenido integro de payload_json (mismo body enviado a cancelarfactura)'),
                    new OA\Property(property: 'datosServer', type: 'object', description: 'datos_server_json si ya sincronizo, o null'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Guardado (upsert por refLocal)', content: new OA\JsonContent(example: ['ok' => true])),
            new OA\Response(response: 401, description: "Falta 'refLocal'"),
        ]
    )]
    public function backupNotaCredito(Request $request)
    {
        return response()->json($this->notaCreditoService->backupNotaCredito($request));
    }
}
