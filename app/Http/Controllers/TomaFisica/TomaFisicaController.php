<?php

namespace App\Http\Controllers\TomaFisica;

use App\Http\Controllers\Controller;
use App\Services\TomaFisica\TomaFisicaService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TomaFisicaController extends Controller
{
    public function __construct(
        private TomaFisicaService $tomaFisicaService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/guardarLinea',
        summary: 'Guarda (upsert) una linea de toma fisica en curso del usuario autenticado; "tomafisica" vacio/null elimina la linea',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idproducto', 'idalmacen'],
                properties: [
                    new OA\Property(property: 'idproducto', type: 'integer', example: 45),
                    new OA\Property(property: 'idalmacen', type: 'integer', example: 1),
                    new OA\Property(property: 'tomafisica', type: 'number', nullable: true, description: 'Cantidad contada. Vacio/null elimina la linea', example: 12.5),
                    new OA\Property(property: 'numero_toma', type: 'integer', description: '1 o 2 (dos conteos simultaneos). Default 1', default: 1, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Linea guardada, eliminada, o rechazada por regla de negocio (success:false + message)',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'deleted' => false,
                    'tomafisica' => 12.5,
                    'existencia' => 15.0,
                    'diferencia' => 2.5,
                    'toma_otra' => null,
                    'fecha' => '2026-07-16 14:32:10',
                    'usuario' => 'jperez',
                ])
            ),
        ]
    )]
    public function guardarLinea(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->tomaFisicaService->guardarLinea($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/tomaActivaUsuario',
        summary: 'En que almacen(es) y con que numero de toma tiene el usuario autenticado por DOLAPIKEY una toma fisica en curso (sin recibir idalmacen)',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de almacen+numero_toma con conteo en curso (vacia si no tiene ninguna)',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'total' => 1,
                    'tomas' => [
                        ['idalmacen' => 1, 'descripcion' => 'Almacen Principal', 'numero_toma' => 1, 'contados' => 12],
                    ],
                ])
            ),
        ]
    )]
    public function tomaActivaUsuario(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->tomaFisicaService->tomaActivaUsuario($usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/temporalTomaFisica',
        summary: 'Todos los productos del catalogo con su conteo en curso (si lo tiene) para la grilla de toma fisica. Version liviana, sin las subconsultas pesadas de categorias/proveedor/toma_otra',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idalmacen', 'idusuario'],
                properties: [
                    new OA\Property(property: 'idalmacen', type: 'integer', example: 1),
                    new OA\Property(property: 'idusuario', type: 'integer', example: 20),
                    new OA\Property(property: 'numero_toma', type: 'integer', description: '1 o 2. Default 1', default: 1, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catalogo completo con la cantidad contada (null si aun no se ha contado) y el usuario que la conto',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'total' => 7038,
                    'productos' => [
                        ['idproducto' => 45, 'ref' => '012345', 'descripcion' => 'ACCESORIO CEJAS', 'existencia' => 12.0, 'cantidad' => null, 'usuario' => ''],
                        ['idproducto' => 46, 'ref' => '005618', 'descripcion' => 'BALDE HALLOWEEN CARITAS', 'existencia' => 5.0, 'cantidad' => 5.0, 'usuario' => 'jperez'],
                    ],
                ])
            ),
        ]
    )]
    public function temporalTomaFisica(Request $request)
    {
        return response()->json($this->tomaFisicaService->temporalTomaFisica($request));
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/inicializarToma',
        summary: 'Inicializa la toma en curso del usuario autenticado: elimina sus lineas de conteo (fk_tomafisica=0) en el almacen/numero de toma indicados',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idalmacen'],
                properties: [
                    new OA\Property(property: 'idalmacen', type: 'integer', example: 1),
                    new OA\Property(property: 'numero_toma', type: 'integer', description: '1 o 2. Default 1', default: 1, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registros de conteo en curso eliminados',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'eliminados' => 12,
                    'message' => 'Toma #1 inicializada: 12 registro(s) de conteo eliminados.',
                ])
            ),
        ]
    )]
    public function inicializarToma(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->tomaFisicaService->inicializarToma($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/cerrarToma',
        summary: 'Cierra la toma en curso del usuario autenticado: crea el encabezado, asocia las lineas contadas, y genera los movimientos de correccion de stock (product_stock/PMP) por cada diferencia (productos sin lote)',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idalmacen'],
                properties: [
                    new OA\Property(property: 'idalmacen', type: 'integer', example: 1),
                    new OA\Property(property: 'numero_toma', type: 'integer', description: '1 o 2. Default 1', default: 1, example: 1),
                    new OA\Property(property: 'categorias_arr', type: 'array', description: 'Categorias del filtro usado, solo para dejarlas registradas en el encabezado', items: new OA\Items(type: 'integer')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Toma cerrada (o rechazada por regla de negocio: sin productos contados, etc.)',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'idToma' => 7,
                    'ref' => 'TOMA-0000007',
                    'lineas' => 18,
                    'movimientos' => 5,
                    'omitidos_lote' => [],
                    'mov_errores' => [],
                    'totales' => [
                        'contados' => 18,
                        'total_toma' => 340.0,
                        'dif_qty' => -6.0,
                        'costo_total' => 1250000.0,
                        'perdida' => -42000.0,
                        'faltante_valor' => 18000.0,
                        'sobrante_valor' => 60000.0,
                    ],
                    'message' => 'Toma TOMA-0000007 cerrada con 18 producto(s). Movimientos de stock generados: 5.',
                ])
            ),
        ]
    )]
    public function cerrarToma(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->tomaFisicaService->cerrarToma($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integros/tomafisica/exportPdfToma',
        summary: 'PDF de una toma fisica ya cerrada (encabezado + lineas). Responde el binario del PDF (Content-Type: application/pdf) listo para que la app Android lo guarde y lo abra',
        security: [['DolApiKey' => []]],
        tags: ['TomaFisica'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idtoma'],
                properties: [
                    new OA\Property(property: 'idtoma', type: 'integer', description: 'Id (rowid) de la toma fisica cerrada', example: 7),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Binario del PDF (application/pdf)'),
            new OA\Response(
                response: 200,
                description: 'Si la toma no existe o el id es invalido, responde JSON en vez de PDF',
                content: new OA\JsonContent(example: ['success' => false, 'message' => 'Toma no encontrada.'])
            ),
        ]
    )]
    public function exportPdfToma(Request $request)
    {
        $idToma = (int) $request->input('idtoma', 0);
        $resultado = $this->tomaFisicaService->generarPdfToma($idToma);

        if (!$resultado['success']) {
            return response()->json($resultado);
        }

        return response($resultado['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$resultado['filename'].'"',
            'Content-Length' => (string) strlen($resultado['contenido']),
        ]);
    }
}
