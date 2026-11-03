<?php

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Route;
    use App\Models\DolibarrUser;
    use App\Http\Controllers\Auth\AuthController;

    /*
    |--------------------------------------------------------------------------
    | Rutas públicas (sin autenticación)
    |--------------------------------------------------------------------------
    | Solo lo imprescindible para obtener/renovar el token.
    */
    
    #INICIO RUTAS AUTENTICACION
    Route::post('/custom/integrospos/login.php', [AuthController::class, 'login']);
    Route::post('/custom/integrospos/refresh', [AuthController::class, 'refresh']);
    Route::post('/custom/integrospos/validateApiKey', 'App\Http\Controllers\Usuarios\UsuariosController@validateApiKey');
    Route::post('/custom/integrospos/logout', [AuthController::class, 'logout']);
    Route::get('/api.php/integrospos/facturepos/dian/actualizarTransaccion/{idFactura}', 'App\Http\Controllers\Facturacion\FacturacionController@actualizarTransaccionDian');
    Route::get('/api.php/integrospos/facturepos/dian/validarDescripciones/{idFactura?}', 'App\Http\Controllers\Facturacion\FacturacionController@validarDescripcionesJsonDian');
    #FIN RUTAS AUTENTICACION

    #INICIO RUTAS Integrosone (publicas)
    Route::post('/custom/integrosone/proveedores/login', 'App\Http\Controllers\Integrosone\IntegrosoneController@loginProveedor')->middleware('throttle:10,1');
    // Valida con la DOLAPIKEY del proveedor (llx_integros_usuario_proveedor), no con el guard apikey de llx_user.
    Route::get('/api.php/integrosone/proveedores/{idProveedor}/productos', 'App\Http\Controllers\Integrosone\IntegrosoneController@productosProveedor')->whereNumber('idProveedor')->middleware('throttle:60,1');
    Route::get('/api.php/integrosone/proveedores/{idProveedor}/categorias', 'App\Http\Controllers\Integrosone\IntegrosoneController@categoriasProveedor')->whereNumber('idProveedor')->middleware('throttle:60,1');
    Route::post('/api.php/integrosone/proveedores/{idProveedor}/preordenes', 'App\Http\Controllers\Integrosone\IntegrosoneController@crearPreordenProveedor')->whereNumber('idProveedor')->middleware('throttle:30,1');
    // URL abierta (sin DOLAPIKEY): la valida el propio servicio via fk_proveedor guardado en la preorden.
    Route::get('/api.php/integrosone/proveedores/{idProveedor}/preordenes/pdf', 'App\Http\Controllers\Integrosone\IntegrosoneController@preordenPdf')->whereNumber('idProveedor')->middleware('throttle:30,1');
    // Acepta DOLAPIKEY de vendedor (llx_user) o de proveedor: la valida el propio controlador, no un guard del grupo de abajo.
    Route::get('/api.php/integrosone/app/clientesCompletos', 'App\Http\Controllers\Clientes\ClientesController@clientesCompletos')->middleware('throttle:60,1');
    Route::get('/api.php/integrosone/configuracionUsuario', 'App\Http\Controllers\Configuracion\ConfiguracionUsuarioController@obtener')->middleware('throttle:60,1');
    #FIN RUTAS Integrosone (publicas)

    #INICIO RUTAS Correo
    // Idem: acepta DOLAPIKEY de vendedor o de proveedor, la valida el propio controlador.
    Route::post('/api.php/integrosapk/app/enviarMensajePedido', 'App\Http\Controllers\Correo\CorreoController@enviarMensajePedido')->middleware('throttle:30,1');
    #FIN RUTAS Correo

    #INICIO RUTAS Imagenes
    Route::get('/api.php/integrospos/imagepath/getImagePath', 'App\Http\Controllers\Imagenes\ImagenesController@getImagePath');
    #FIN RUTAS Imagenes

    #INICIO RUTAS Healthcheck
    Route::get('/api.php/integrospos/pos/ping', 'App\Http\Controllers\Usuarios\UsuariosController@ping');
    #FIN RUTAS Healthcheck
    
    
    /*
    |--------------------------------------------------------------------------
    | Rutas de sesión (requieren access_token Sanctum)
    |--------------------------------------------------------------------------
    | Estas rutas operan sobre el token de sesión en sí (consultarlo/revocarlo),
    | por lo que exigen Authorization: Bearer <access_token>.
    */
    Route::middleware('auth:sanctum')->group(function () {

        #INICIO RUTAS AUTENTICACION
        Route::get('/custom/integrospos/me', [AuthController::class, 'me']);
        #FIN RUTAS AUTENTICACION

    });

    /*
    |--------------------------------------------------------------------------
    | Rutas protegidas por APIKEY
    |--------------------------------------------------------------------------
    | Toda ruta declarada dentro de este grupo exige el header:
    |     DOLAPIKEY: <APIKEY>
    | donde <APIKEY> es el valor devuelto por el login. Agrega aquí cualquier
    | endpoint nuevo que deba consumirse con la APIKEY.
    */
    Route::middleware('auth:apikey')->group(function () {

        Route::get('/test-dolibarr', function () {
            $usuarios = DolibarrUser::limit(10)->get();
            return $usuarios;
        });

        #INICIO RUTAS FACTURAS
            Route::post('/api.php/integrospos/facturepos/facturesPerUser', 'App\Http\Controllers\Facturacion\FacturacionController@listarFacturasApp');
            Route::post('/api.php/integrospos/facturepos/facturesPerProduct', 'App\Http\Controllers\Facturacion\FacturacionController@listarFacturasApp');
            Route::post('/api.php/integrospos/facturepos/detalleFactura', 'App\Http\Controllers\Facturacion\FacturacionController@detalleFactura');
            Route::post('/api.php/integrospos/pos/ticket/payment', 'App\Http\Controllers\Facturacion\FacturacionController@validarFacturaPos');
            Route::post('/api.php/integrospos/facturepos/actualizarEnPedido', 'App\Http\Controllers\Facturacion\FacturacionController@actualizarEnPedido');

        #FIN RUTAS FACTURAS

        #INICIO NOTAS DE CREEDITOS
            Route::post('/api.php/integrospos/facturepos/cancelarfactura', 'App\Http\Controllers\Facturacion\NotaCreditoController@crearApp');

        #FIN NOTAS DE CREEDITOS

        
        #INICIO RUTAS Cotizaciones

        Route::post('/api.php/integrospos/pos/factures/list', 'App\Http\Controllers\Facturacion\FacturacionController@listarFacturasApp');
        Route::delete('/api.php/integrospos/pos/factures/item/{factura}/{item}', 'App\Http\Controllers\Facturacion\CotizacionController@eliminarItem');
        Route::delete('/api.php/integrospos/pos/factures', 'App\Http\Controllers\Facturacion\CotizacionController@eliminarFactura');
        Route::post('/api.php/integrospos/facturepos/cotizacion/list', 'App\Http\Controllers\Facturacion\CotizacionController@listar');
        Route::post('/api.php/integrospos/facturepos/cotizacion/detalle', 'App\Http\Controllers\Facturacion\CotizacionController@detalle');
        Route::post('/api.php/integrospos/facturepos/cotizacion/refPorRefLocal', 'App\Http\Controllers\Facturacion\CotizacionController@refPorRefLocal');
        Route::post('/api.php/integrospos/facturepos/cotizacion/validarEstado', 'App\Http\Controllers\Facturacion\CotizacionController@validarEstado');
        Route::post('/api.php/integrospos/pos/factures/auditoria', 'App\Http\Controllers\Facturacion\CotizacionController@auditoria');
        Route::post('/api.php/integrospos/facturepos/bloqueoCotizacion', 'App\Http\Controllers\Facturacion\CotizacionController@bloqueoCotizacion');


        #FIN RUTAS Cotizaciones

        #INICIO RUTAS PRODUCTOS
        Route::post('/api.php/integrospos/products/searchProducto', 'App\Http\Controllers\Producto\ProductoController@listarProductosApp');
        Route::post('/api.php/integrospos/products/getProductosByPromo/{idPromo}', 'App\Http\Controllers\Productos\ProductosController@getProductosByPromo');
        Route::post('/api.php/integrospos/products/postCreateProduct', 'App\Http\Controllers\Productos\ProductosController@createProductPos');
        Route::post('/api.php/integrospos/products/validateProduct', 'App\Http\Controllers\Producto\ProductoController@listarProductosApp');
        Route::get('/api.php/integrospos/products/codigosOperativos', 'App\Http\Controllers\CodigosOperativos\CodigoOperativoController@getCodigosOperativos');
        #FIN RUTAS PRODUCTOS

        #INICIO RUTAS Carrusel
        Route::get('/api.php/integrospos/carrusel/activas', 'App\Http\Controllers\Carrusel\CarruselController@activas');
        #FIN RUTAS Carrusel

        #INICIO RUTAS Categorias
        Route::get('/api.php/integrosone/productos/categorias', 'App\Http\Controllers\Categorias\CategoriaController@getCategorias');
        Route::get('/api.php/integrosone/productos/clientes', 'App\Http\Controllers\Producto\ProductoController@getClienteProductos');
        #FIN RUTAS Categorias
        #FIN RUTAS FACTURAS

        #INICIO RUTAS Usuarios
        Route::get('/api.php/integrospos/user/getUsersPos', 'App\Http\Controllers\Usuarios\UsuariosController@listarUsuariosApp');
        Route::get('/api.php/integrosone/user/getUsersOne', 'App\Http\Controllers\Usuarios\UsuariosController@listarUsuariosONE');
        Route::post('/api.php/integrospos/pos/autorizacion', 'App\Http\Controllers\Usuarios\UsuariosController@autorizacion');
        Route::post('/api.php/integrospos/terminales/integrosTokenClose', 'App\Http\Controllers\Usuarios\UsuariosController@logout');
        #FIN RUTAS Usuarios

        #INICIO RUTAS Mensajes
        Route::post('/api.php/integrosone/mensajes', 'App\Http\Controllers\Mensajes\MensajeController@getMensajes');
        #FIN RUTAS Mensajes

        #INICIO RUTAS Logs
        Route::post('/api.php/integrospos/logs/registrarErrorEndpoint', 'App\Http\Controllers\Usuarios\LogErrorEndpointController@registrarErrorEndpoint');
        Route::post('/api.php/integrospos/pos/auditoria', 'App\Http\Controllers\Usuarios\LogErrorEndpointController@registrarAuditoria');
        #FIN RUTAS Logs

        #INICIO RUTAS Configuracion
        Route::get('/api.php/integrospos/pos/configuracion', 'App\Http\Controllers\Configuracion\ConfiguracionController@listarConfiguracionApp');
        Route::get('/api.php/integrospos/customers/posapi/departamentos', 'App\Http\Controllers\Configuracion\ConfiguracionController@departamentosYCiudades');
        Route::get('/api.php/integrospos/pos/update/check', 'App\Http\Controllers\Configuracion\ConfiguracionController@verificarActualizacion');
        Route::get('/api.php/integrospos/pos/getCategorieIva', 'App\Http\Controllers\Configuracion\ConfiguracionController@categorieIva');
        Route::post('/api.php/integrospos/pos/configuracion/limpiarEventosWss', 'App\Http\Controllers\Configuracion\ConfiguracionController@limpiarEventosWss');
        Route::post('/api.php/integrospos/pos/configuracion/limpiarAuditoriaAntigua', 'App\Http\Controllers\Configuracion\ConfiguracionController@limpiarAuditoriaAntigua');
        Route::get('/api.php/integrospos/pos/actividadesEconomicasIca', 'App\Http\Controllers\Configuracion\ConfiguracionController@actividadesEconomicasIca');
        Route::get('/api.php/integrospos/pos/almacenes/jerarquia', 'App\Http\Controllers\Configuracion\ConfiguracionController@almacenesJerarquia');
        Route::get('/api.php/integrospos/pos/cajas', 'App\Http\Controllers\Configuracion\ConfiguracionController@cajas');
        #FIN RUTAS Configuracion

        #INICIO RUTAS Clientes
        Route::post('/api.php/integrospos/customers/clients', 'App\Http\Controllers\Clientes\ClientesController@listarClientesApp');
        Route::post('/api.php/integrospos/customers/createClientPos', 'App\Http\Controllers\Clientes\ClientesController@guardarClientePos');
        Route::post('/api.php/integrospos/customers/updateClientPos', 'App\Http\Controllers\Clientes\ClientesController@guardarClientePos');
        Route::post('/api.php/integrospos/pos/client/pendingInvoices', 'App\Http\Controllers\Clientes\ClientesController@getClientPendingInvoices');
        Route::post('/api.php/integrospos/puntosfidelizacion/redimirPuntos', 'App\Http\Controllers\Clientes\ClientesController@redimirPuntos');
        Route::post('/api.php/integrospos/puntosfidelizacion/productoPuntos', 'App\Http\Controllers\Clientes\ClientesController@productoPuntos');
        Route::get('/api.php/integrosone/negociacion/proveedores', 'App\Http\Controllers\Clientes\ClientesController@getProveedores');
        Route::get('/api.php/integrosone/productos/clientes/{id}', 'App\Http\Controllers\Clientes\ClientesController@getProductosCliente');
        // Alias del path legacy de Dolibarr (modulo integrosapk): el cliente todavia le pega a esta URL.
        Route::get('/api.php/integrosapk/productos/clientes/{id}', 'App\Http\Controllers\Clientes\ClientesController@getProductosCliente');
        #FIN RUTAS Clientes

        #INICIO RUTAS Domicilio
        Route::post('/api.php/integrospos/pos/ticket/domicilio', 'App\Http\Controllers\Domicilio\domicilioController@postPedidoDomicilio');
        Route::post('/api.php/integrospos/domicilios/getDomiciliosPos', 'App\Http\Controllers\Domicilio\domicilioController@getDomiciliosPos');
        #FIN RUTAS Domicilio

        #INICIO RUTAS TicketTemplate
        Route::post('/api.php/integrospos/tickettemplate/tipos', 'App\Http\Controllers\TicketTemplate\TicketTemplateController@tiposTicket');
        Route::post('/api.php/integrospos/tickettemplate/get', 'App\Http\Controllers\TicketTemplate\TicketTemplateController@configuracionTicket');
        Route::post('/api.php/integrospos/tickettemplate/variables', 'App\Http\Controllers\TicketTemplate\TicketTemplateController@variablesTicket');
        Route::post('/api.php/integrospos/tickettemplate/secciones', 'App\Http\Controllers\TicketTemplate\TicketTemplateController@seccionesTicket');
        Route::get('/api.php/integrospos/tickettemplate/{id}', 'App\Http\Controllers\TicketTemplate\TicketTemplateController@plantillaTicket');
        #FIN RUTAS TicketTemplate

        #INICIO RUTAS Sorteos
        Route::get('/api.php/integrospos/sorteos/getSorteos', 'App\Http\Controllers\Sorteos\SorteosController@getSorteos');
        Route::post('/api.php/integrospos/sorteos/insertarBoletosDirecto', 'App\Http\Controllers\Sorteos\SorteosController@insertarBoletosDirectoPos');
        #FIN RUTAS Sorteos

        #INICIO RUTAS Entradasalidas
        Route::post('/api.php/integrospos/entradasalidas/serviciosCorresponsal', 'App\Http\Controllers\Entradasalidas\EntradasalidasController@serviciosCorresponsal');
        Route::post('/api.php/integrospos/entradasalidas/create', 'App\Http\Controllers\Entradasalidas\EntradasalidasController@createApp');
        Route::post('/api.php/integros/contabilidad/crearReciboCajaPOS', 'App\Http\Controllers\Entradasalidas\EntradasalidasController@crearReciboCaja');
        #FIN RUTAS Entradasalidas

        #INICIO RUTAS CashControl
        Route::post('/api.php/integrospos/cashcontrol/aperturaCaja', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@entradaCaja');
        Route::post('/api.php/integrospos/cashcontrol/getCierreCaja', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@getCierreCaja');
        Route::post('/api.php/integrospos/cashcontrol/cajasHoy', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@cajasHoy');
        Route::post('/api.php/integrospos/cashcontrol/movimientos', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@movimientos');
        Route::post('/api.php/integrospos/cashcontrol/cajaObservacion', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@cajaObservacion');
        Route::post('/api.php/integrospos/cashcontrol/cierreCaja', 'App\Http\Controllers\AperturaCierre\AperturaCierreController@cierreCaja');
        #FIN RUTAS CashControl

        #INICIO RUTAS TomaFisica
        Route::post('/api.php/integros/tomafisica/guardarLinea', 'App\Http\Controllers\TomaFisica\TomaFisicaController@guardarLinea');
        Route::post('/api.php/integros/tomafisica/inicializarToma', 'App\Http\Controllers\TomaFisica\TomaFisicaController@inicializarToma');
        Route::post('/api.php/integros/tomafisica/cerrarToma', 'App\Http\Controllers\TomaFisica\TomaFisicaController@cerrarToma');
        Route::post('/api.php/integros/tomafisica/temporalTomaFisica', 'App\Http\Controllers\TomaFisica\TomaFisicaController@temporalTomaFisica');
        Route::post('/api.php/integros/tomafisica/tomaActivaUsuario', 'App\Http\Controllers\TomaFisica\TomaFisicaController@tomaActivaUsuario');
        Route::post('/api.php/integros/tomafisica/exportPdfToma', 'App\Http\Controllers\TomaFisica\TomaFisicaController@exportPdfToma');
        #FIN RUTAS TomaFisica

        #INICIO RUTAS InformeDinamico
        Route::get('/api.php/integrospos/consultadinamica/consultas', 'App\Http\Controllers\InformeDinamico\InformeDinamicoController@consultas');
        Route::get('/api.php/integrospos/consultadinamica/consultas/{rowid}', 'App\Http\Controllers\InformeDinamico\InformeDinamicoController@consulta');
        Route::post('/api.php/integrospos/consultadinamica/consultas/{rowid}/ejecutar', 'App\Http\Controllers\InformeDinamico\InformeDinamicoController@ejecutar');
        Route::post('/api.php/integrospos/consultadinamica/consultas/ejecutar', 'App\Http\Controllers\InformeDinamico\InformeDinamicoController@ejecutarPorBody');
        #FIN RUTAS InformeDinamico

        #INICIO RUTAS Dashboard
        Route::get('/api.php/informes/dashboarddinamico/dashboards', 'App\Http\Controllers\Dashboard\DashboardController@dashboards');
        Route::post('/api.php/informes/dashboarddinamico/dashboards/datos', 'App\Http\Controllers\Dashboard\DashboardController@datos');
        Route::get('/api.php/informes/dashboarddinamico/dashboards/{id}', 'App\Http\Controllers\Dashboard\DashboardController@detalle');
        #FIN RUTAS Dashboard

        #INICIO RUTAS OrdenVenta
        Route::post('/api.php/integrospos/ordenventa/crear', 'App\Http\Controllers\OrdenVenta\OrdenVentaController@crear');
        Route::post('/api.php/integrospos/preorden/crear', 'App\Http\Controllers\OrdenVenta\PreordenController@crear');
        #FIN RUTAS OrdenVenta

        Route::post('/api.php/integrospos/terminales/integrosTokenClose', [AuthController::class, 'logout']);
        Route::post('/api.php/integrospos/terminales/integrosTokenOpen', [AuthController::class, 'tokenOpen']);

        #INICIO RUTAS Backup (respaldo offline-first del POS, en paralelo al sync de negocio existente)
        Route::post('/api.php/integrospos/pos/backup/pedido', 'App\Http\Controllers\Facturacion\FacturacionController@backupPedido');
        Route::post('/pedidos/erp/validar-masivo', 'App\Http\Controllers\Facturacion\FacturacionController@validarPedidosMasivo');
        Route::post('/api.php/integrospos/pos/backup/notaCredito', 'App\Http\Controllers\Facturacion\NotaCreditoController@backupNotaCredito');
        Route::post('/api.php/integrospos/pos/backup/entradaSalida', 'App\Http\Controllers\Entradasalidas\EntradasalidasController@backupEntradaSalida');
        #FIN RUTAS Backup

    });

    /*
    |--------------------------------------------------------------------------
    | Rutas protegidas por APIKEY
    |--------------------------------------------------------------------------
    | Toda ruta declarada dentro de este grupo exige el header:
    |     DOLAPIKEY: <APIKEY>
    | donde <APIKEY> es el valor devuelto por el login. Agrega aquí cualquier
    | endpoint nuevo que deba consumirse con la APIKEY.
    */
    
