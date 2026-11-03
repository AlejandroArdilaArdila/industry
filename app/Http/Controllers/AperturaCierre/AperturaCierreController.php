<?php

namespace App\Http\Controllers\AperturaCierre;

use App\Http\Controllers\Controller;
use App\Services\AperturaCierre\AperturaCierreService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AperturaCierreController extends Controller
{
    public function __construct(private AperturaCierreService $aperturaCierreService)
    {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/aperturaCaja',
        summary: 'Registra la apertura (entrada) de caja de un terminal',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_user', type: 'integer', example: 1, description: 'Usuario que abre la caja'),
                    new OA\Property(property: 'idTerminal', type: 'integer', example: 1, description: 'Terminal/caja del usuario (usado para validar caja abierta)'),
                    new OA\Property(property: 'base', type: 'number', example: 100000, description: 'Base con la que se abre la caja'),
                    new OA\Property(property: 'fk_caja_local', type: 'string', example: 'CAJA-LOCAL-001', description: 'Referencia local (idempotencia)'),
                    new OA\Property(property: 'fecha', type: 'string', format: 'date-time', description: 'Fecha de apertura (por defecto ahora)'),
                    new OA\Property(property: 'device', type: 'string', description: 'Identificador del dispositivo'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Apertura registrada o ya existente (idempotente)',
                content: new OA\JsonContent(example: [
                    'status'         => 0,
                    'message'        => 'Apertura de caja registrada correctamente',
                    'rowid'          => 10,
                    'fk_user'        => 1,
                    'fecha_apertura' => '2026-07-05 08:00:00',
                    'base'           => 100000,
                    'estado'         => 1,
                    'ref_local'      => 'CAJA-LOCAL-001',
                ])
            ),
            new OA\Response(response: 403, description: 'La caja ya se encuentra abierta o no se pudo registrar la apertura'),
        ]
    )]
    public function entradaCaja(Request $request)
    {
        return $this->aperturaCierreService->entradaCaja($request);
    }
    
    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/cajasHoy',
        summary: 'Lista las cajas (aperturas/cierres) del día actual para un usuario, con su device_id asociado',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_user', type: 'integer', example: 1, description: 'Usuario a consultar'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cajas del día para el usuario',
                content: new OA\JsonContent(example: [
                    [
                        'ref_local'      => 'CAJA-LOCAL-001',
                        'fecha_apertura' => '2026-07-06 08:00:00',
                        'fecha_cierre'   => null,
                        'estado'         => 1,
                        'deviceid'       => 'DEVICE-01',
                    ],
                ])
            ),
            new OA\Response(response: 400, description: 'El campo fk_user es requerido'),
        ]
    )]
    public function cajasHoy(Request $request)
    {
        return $this->aperturaCierreService->cajasHoy($request);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/movimientos',
        summary: 'Lista los movimientos (entradas/salidas de caja) filtrados por caja local y/o usuario',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_caja_local', type: 'string', example: 'CAJA-LOCAL-001', description: 'Referencia local de la apertura'),
                    new OA\Property(property: 'byUser', type: 'integer', example: 1, description: 'Filtrar por usuario'),
                    new OA\Property(property: 'cerrado', type: 'boolean', description: 'Si es true, solo incluye movimientos de cajas actualmente abiertas (estado=1)'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Movimientos encontrados',
                content: new OA\JsonContent(example: [
                    'fk_caja_local' => 'CAJA-LOCAL-001',
                    'total'         => 1,
                    'movimientos'   => [
                        [
                            'id'             => 1,
                            'ref'            => 'ES-0001',
                            'monto'          => 50000,
                            'tipo'           => 1,
                            'fecha'          => '2026-07-06 10:00:00',
                            'descripcion'    => 'Retiro de caja',
                            'ref_local'      => 'ES-LOCAL-0001',
                            'fk_client'      => 0,
                            'nombre_cliente' => '',
                            'fk_product'     => null,
                            'label_product'  => '',
                            'fk_caja_local'  => 'CAJA-LOCAL-001',
                            'metodos_pago'   => [],
                        ],
                    ],
                ])
            ),
            new OA\Response(response: 400, description: 'Se requiere fk_caja_local o byUser'),
            new OA\Response(response: 404, description: 'No se encontró la caja con ese ref_local'),
        ]
    )]
    public function movimientos(Request $request)
    {
        return $this->aperturaCierreService->movimientos($request);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/cajaObservacion',
        summary: 'Agrega una observación al historial (log) de una caja, identificada por su ref_local',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_caja_local', type: 'string', example: 'CAJA-LOCAL-001', description: 'Referencia local de la caja'),
                    new OA\Property(property: 'observacion', type: 'string', example: 'Faltante de $5.000 en el cuadre', description: 'Texto de la observación'),
                    new OA\Property(property: 'device', type: 'string', description: 'Identificador del dispositivo'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Observación registrada correctamente',
                content: new OA\JsonContent(example: [
                    'status'      => 0,
                    'message'     => 'Observación registrada correctamente',
                    'observacion' => [
                        ['obs' => 'Faltante de $5.000 en el cuadre', 'fecha' => '2026-07-06 10:00:00', 'deviceid' => 'DEVICE-01'],
                    ],
                ])
            ),
            new OA\Response(response: 400, description: 'Se requieren fk_caja_local y observacion'),
            new OA\Response(response: 404, description: 'No se encontró la caja con ese ref_local'),
        ]
    )]
    public function cajaObservacion(Request $request)
    {
        return $this->aperturaCierreService->cajaObservacion($request);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/getCierreCaja',
        summary: 'Genera el resumen (preview) de cierre de caja de la apertura activa de un terminal, sin cerrarla',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'idTerminal', type: 'integer', example: 1, description: 'Usuario/terminal a consultar'),
                    new OA\Property(property: 'device', type: 'string', description: 'Identificador del dispositivo (opcional, filtra la apertura activa)'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumen de cierre de caja',
                content: new OA\JsonContent(example: [
                    'totalVentas'       => 250000,
                    'totalAperturaCaja' => 100000,
                    'fechaApertura'     => '2026-07-05 08:00:00',
                    'ref_local'         => 'CAJA-LOCAL-001',
                    'device_ape'        => 'DEVICE-01',
                    'observacion'       => null,
                    'mEfectivo'         => 150000,
                    'mTransferencia'    => 100000,
                    'totalEntradas'     => 0,
                    'totalSalidas'      => 0,
                    'ventasTotales'     => [
                        ['idMetodo' => 1, 'tipo' => 'EFECTIVO', 'valor' => 150000],
                    ],
                    'cantidadFacturas'  => [
                        'POS'           => ['cantidad' => 5, 'valor' => 120000],
                        'ELECTRONICAS'  => ['cantidad' => 3, 'valor' => 80000],
                        'BORRADOR'      => ['cantidad' => 0, 'valor' => 0],
                        'NOTAS_CREDITO' => ['cantidad' => 1, 'valor' => 10000],
                    ],
                ])
            ),
            new OA\Response(response: 403, description: 'No se encontró apertura de caja activa'),
        ]
    )]
    public function getCierreCaja(Request $request)
    {
        return $this->aperturaCierreService->getCierreCaja($request);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/cashcontrol/cierreCaja',
        summary: 'Cierra la caja (apertura activa) de un terminal y devuelve el resumen completo del cierre',
        tags: ['CashControl'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_user', type: 'integer', example: 1, description: 'Usuario que cierra la caja'),
                    new OA\Property(property: 'fk_caja_local', type: 'string', example: 'CAJA-LOCAL-001', description: 'Referencia local de la apertura a cerrar'),
                    new OA\Property(property: 'device', type: 'string', description: 'Identificador del dispositivo'),
                    new OA\Property(property: 'fecha', type: 'string', format: 'date-time', description: 'Fecha de cierre (por defecto ahora)'),
                    new OA\Property(property: 'cajero', type: 'number', description: 'Monto contado por el cajero'),
                    new OA\Property(property: 'efectivo_entregado', type: 'number', description: 'Efectivo entregado al cierre'),
                    new OA\Property(property: 'datos', type: 'object', description: 'Datos crudos del POS (facturas, notas, entradas/salidas)'),
                    new OA\Property(
                        property: 'cierreCaja',
                        type: 'object',
                        description: 'Resumen previo obtenido de getCierreCaja',
                        properties: [
                            new OA\Property(property: 'totalVentas', type: 'number', example: 250000),
                            new OA\Property(property: 'ventasTotales', type: 'array', items: new OA\Items()),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cierre registrado correctamente',
                content: new OA\JsonContent(example: [
                    'status'               => 0,
                    'message'              => 'Cierre de caja realizado correctamente',
                    'error'                => 'Cierre de caja realizado correctamente',
                    'rowid'                => 10,
                    'nombreEmpresa'        => 'Mi Empresa',
                    'monto_contado_cajero' => 250000,
                    'cierre'               => ['rowid' => 10, 'totalVentas' => 250000],
                ])
            ),
            new OA\Response(response: 401, description: 'No se encontró ninguna caja abierta para este usuario'),
            new OA\Response(response: 403, description: 'No se pudo registrar el cierre de caja'),
        ]
    )]
    public function cierreCaja(Request $request)
    {
        return $this->aperturaCierreService->cierreCaja($request);
    }
}
