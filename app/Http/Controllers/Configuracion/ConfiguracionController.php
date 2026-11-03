<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Services\Configuracion\ConfiguracionService;
use OpenApi\Attributes as OA;

class ConfiguracionController extends Controller
{
    public function __construct(
        private ConfiguracionService $configuracionService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/configuracion',
        summary: 'Configuracion global del POS (nada especifico de un usuario: eso vive en /user/getUsersPos)',
        security: [['DolApiKey' => []]],
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Configuracion global: datos de la empresa, metodos de pago, domicilios y flags generales',
                content: new OA\JsonContent(example: [
                    'empresa' => 'Mi Empresa SAS',
                    'nit' => '900123456',
                    'tipoIdentificacion' => '9001234561',
                    'uvt' => 49799,
                    'anioUvt' => 2026,
                    'idClasificacionTributaria' => 1,
                    'code' => '05001',
                    'idCiudad' => 80,
                    'url' => 'https://miempresa.com',
                    'ciudad' => 'Medellin(Antioquia)',
                    'logo' => '/viewimage.php?modulepart=mycompany&file=logos/thumbs/logo_small.png',
                    'direccion' => 'Calle 1 # 2-3',
                    'telefono' => '3001234567',
                    'mensajeAlerta' => '',
                    'tiempoSegundosAlertas' => 0,
                    'fidelizacion' => false,
                    'domiciliosHabilitado' => true,
                    'domicilios' => [],
                    'controlStock' => true,
                    'crearproductos' => false,
                    'editarprecios' => false,
                    'almacenes' => [['rowid' => 1, 'almacen' => 'PRINCIPAL']],
                    'metodos' => [['id' => 1, 'code' => 'LIQ', 'metodo' => 'Efectivo']],
                    'mostrarImagenes' => false,
                    'altoFilas' => 0,
                    'cierreCajaPos' => false,
                    'cierreCajaDetallado' => false,
                    'crearClientes' => false,
                    'printLabels' => false,
                    'separadorBusqueda' => 'No configurado',
                    'facturaElectronica' => false,
                    'facturaPos' => true,
                    'facturaBorrador' => false,
                    'facturaElectronicaColor' => '#ffffff',
                    'facturaPosColor' => '#ffffff',
                    'facturaBorradorColor' => '#ffffff',
                    'facturaPredeterminada' => 'No configurado',
                    'nivelPrecioPin' => 'No configurado',
                    'tipoAplicacionPromociones' => 'No configurado',
                    'pedidosEspera' => 3,
                    'reglaRedondeo' => [
                        'activo' => true,
                        'cifras' => 2,
                        'base' => 100,
                        'reglas' => [
                            ['desde' => 1, 'hasta' => 25, 'terminaEn' => 0],
                            ['desde' => 26, 'hasta' => 75, 'terminaEn' => 50],
                            ['desde' => 76, 'hasta' => 99, 'terminaEn' => 100],
                        ],
                    ],
                    'datafonoConfig' => [[
                        'urlToken' => '', 'urlCompra' => '', 'urlConsulta' => '', 'username' => '',
                        'password' => '', 'client_id' => '', 'grant_type' => '', 'client_secret' => '',
                        'codigoDeComercio' => '', 'metodoPago' => '', 'tiempoValidacion' => 0,
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: 'Falta el header DOLAPIKEY o la clave no corresponde a ningun usuario activo'),
        ]
    )]
    public function listarConfiguracionApp()
    {
        return response()->json($this->configuracionService->getConfiguracion());
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/customers/posapi/departamentos',
        summary: 'Departamentos y ciudades (con codigo DIAN) para formularios de domicilio',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de departamentos y ciudades activos',
                content: new OA\JsonContent(example: [
                    'departamentos' => [
                        ['code_depto' => '05', 'depto' => 'Antioquia'],
                        ['code_depto' => '11', 'depto' => 'Bogotá, D.C.'],
                    ],
                    'ciudades' => [
                        ['rowid' => '80', 'code_depto' => '05', 'code' => '05001', 'ciudad' => 'Medellin', 'zip' => '050013'],
                        ['rowid' => '169', 'code_depto' => '11', 'code' => '11001', 'ciudad' => 'Bogotá, D.C.', 'zip' => '111511'],
                    ],
                ])
            ),
        ]
    )]
    public function departamentosYCiudades()
    {
        return response()->json($this->configuracionService->departamentosYCiudades());
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/pos/getCategorieIva',
        summary: 'Catalogos de categorias de producto, tarifas de IVA/impoconsumo y unidades de peso/volumen',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catalogos para el formulario de creacion/edicion de productos',
                content: new OA\JsonContent(example: [
                    'ivas' => [
                        ['valor' => '0', 'label' => 'Excento'],
                        ['valor' => '19', 'label' => '19'],
                        ['valor' => '5', 'label' => '5'],
                    ],
                    'categorias' => [
                        ['valor' => '1', 'label' => 'Abarrotes'],
                        ['valor' => '2', 'label' => 'Licores 1'],
                    ],
                    'peso' => [
                        ['valor' => 0, 'label' => 'kg'],
                        ['valor' => -3, 'label' => 'g'],
                    ],
                    'volumen' => [
                        ['valor' => 0, 'label' => 'm3'],
                        ['valor' => 98, 'label' => 'L'],
                    ],
                ])
            ),
        ]
    )]
    public function categorieIva()
    {
        return response()->json($this->configuracionService->getCategorieIva());
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/pos/almacenes/jerarquia',
        summary: 'Jerarquia de almacenes: sucursales (almacenes padre) con sus almacenes hijos',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de sucursales con sus almacenes hijos',
                content: new OA\JsonContent(example: [
                    ['id' => 1, 'nombre' => 'Sucursal Principal', 'hijos' => [
                        ['id' => 6, 'nombre' => 'Almacen Principal S1'],
                        ['id' => 7, 'nombre' => 'Almacen Calle 30 S1'],
                    ]],
                ])
            ),
        ]
    )]
    public function almacenesJerarquia()
    {
        return response()->json($this->configuracionService->almacenesJerarquia());
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/pos/actividadesEconomicasIca',
        summary: 'Catalogo de actividades economicas ICA (agrupacion, tarifa y base imponible), usado para resolver la retencion ICA a partir de la agrupacion ligada al CIIU de un cliente',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de actividades economicas ICA activas',
                content: new OA\JsonContent(example: [
                    ['agrupacion' => 101, 'tarifa' => 3.0, 'base_imponible' => 0.0],
                    ['agrupacion' => 201, 'tarifa' => 6.0, 'base_imponible' => 0.0],
                    ['agrupacion' => 302, 'tarifa' => 10.0, 'base_imponible' => 0.0],
                ])
            ),
        ]
    )]
    public function actividadesEconomicasIca()
    {
        return response()->json($this->configuracionService->actividadesEconomicasIca());
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/pos/cajas',
        summary: 'Listado de cajas (llx_integrospos_cajas) activas, con sus almacenes (llx_integrospos_cajas_almacen), resolucion DIAN y datafono',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de cajas activas',
                content: new OA\JsonContent(example: [
                    [
                        'rowid' => 1,
                        'caja' => 'CAJA PRINCIPAL',
                        'sucursales' => [
                            [
                                'idSucursal' => 1,
                                'nombreSucursal' => 'CAJA PRINCIPAL',
                            ],
                        ],
                        'almacenes' => [
                            ['rowid' => 2, 'almacen' => 'SECOS S1'],
                        ],
                        'datafonoTerminal' => ['idCajero' => '', 'numeroDeCaja' => '', 'codigoDeTerminal' => ''],
                        'resolucion' => [
                            'factura' => [
                                'pin' => '20020', 'activa' => true, 'infoQr' => '.', 'entorno' => '1',
                                'prefijo' => 'ESOL', 'prefijoNc' => 'NSOL', 'fechaDesde' => '2026-06-30',
                                'fechaHasta' => '2028-06-30', 'rangoDesde' => 1, 'rangoHasta' => 5000000,
                                'resolucion' => '18764111918201', 'softwareId' => 'ce6b52b7-5c46-46ae-a295-ec5f6d90cb63',
                                'claveTecnica' => '3802ff581ec76c6423133f889803598a9474e3e9fa3c025f819f6a898418a589',
                                'ultimaNotaCredito' => 'NSOL15', 'ultimoConsecutivo' => 236,
                            ],
                            'pos' => [
                                'pin' => '20030', 'activa' => true, 'infoQr' => '.', 'entorno' => '1',
                                'prefijo' => 'OSOL', 'prefijoNc' => 'NOSOL', 'fechaDesde' => '2026-08-26',
                                'fechaHasta' => '2028-08-26', 'rangoDesde' => 1, 'rangoHasta' => 10000000,
                                'resolucion' => '18764114582327', 'softwareId' => '10a0bcec-6585-4f08-8b7c-4e47b3a0f394',
                                'claveTecnica' => '20030', 'ultimaNotaCredito' => 'NOSOL2', 'ultimoConsecutivo' => 5079,
                            ],
                            'borrador' => [
                                'activa' => true, 'infoQr' => 'QR', 'prefijo' => 'BOR', 'prefijoNc' => 'NCB',
                                'fechaDesde' => '2026-03-03', 'fechaHasta' => '2026-12-31', 'rangoDesde' => 1000,
                                'rangoHasta' => 99000000, 'resolucion' => '80000001', 'ultimaNotaCredito' => 'NCB20',
                                'ultimoConsecutivo' => 1222,
                            ],
                        ],
                    ],
                ])
            ),
        ]
    )]
    public function cajas()
    {
        return response()->json($this->configuracionService->cajas());
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/update/check',
        summary: 'Version/build/changelog disponible del POS de escritorio, con sus URLs de descarga',
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Datos de la ultima version publicada',
                content: new OA\JsonContent(example: [
                    'version' => '4.4.51',
                    'build' => 404051,
                    'changelog' => "- Tirillas de impresión Dinámicas desde ERP\n- Cambio de precio administrador en las cajas\n- Cierre de caja para usuarios que no son administradores.",
                    'forzar' => true,
                    'windows_url' => 'https://api-a.integros365.com/pos.exe',
                    'linux_url' => 'https://api-a.integros365.com/pos.deb',
                ])
            ),
            new OA\Response(response: 404, description: 'No se encontro el archivo de version'),
            new OA\Response(response: 500, description: 'El archivo de version tiene un JSON invalido'),
        ]
    )]
    public function verificarActualizacion()
    {
        return response()->json($this->configuracionService->verificarActualizacion());
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/configuracion/limpiarEventosWss',
        summary: 'Trunca la tabla de eventos pendientes del servidor WSS (llx_integrospos_wss_events)',
        security: [['DolApiKey' => []]],
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tabla truncada correctamente',
                content: new OA\JsonContent(example: ['ok' => true])
            ),
            new OA\Response(response: 401, description: 'Falta el header DOLAPIKEY o la clave no corresponde a ningun usuario activo'),
        ]
    )]
    public function limpiarEventosWss()
    {
        $this->configuracionService->truncarEventosWss();

        return response()->json(['ok' => true]);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/configuracion/limpiarAuditoriaAntigua',
        summary: 'Borra de llx_pos_auditoria los registros con mas de 15 dias de antiguedad',
        security: [['DolApiKey' => []]],
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registros eliminados',
                content: new OA\JsonContent(example: ['ok' => true, 'eliminados' => 128])
            ),
            new OA\Response(response: 401, description: 'Falta el header DOLAPIKEY o la clave no corresponde a ningun usuario activo'),
        ]
    )]
    public function limpiarAuditoriaAntigua()
    {
        $eliminados = $this->configuracionService->eliminarAuditoriaAntigua();

        return response()->json(['ok' => true, 'eliminados' => $eliminados]);
    }
}
