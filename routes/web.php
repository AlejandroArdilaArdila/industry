<?php

use App\Http\Controllers\Panel\Categorias\CategoriaFichaController;
use App\Http\Controllers\Panel\Configuracion\ConfiguracionController;
use App\Http\Controllers\Panel\Categorias\CategoriasGridController;
use App\Http\Controllers\Panel\Categorias\CategoriasPageController;
use App\Http\Controllers\Panel\PanelAuthController;
use App\Http\Controllers\Panel\ActividadesController;
use App\Http\Controllers\Panel\MicrosoftController;
use App\Http\Controllers\Panel\PanelDashboardController;
use App\Http\Controllers\Panel\TareasController;
use App\Http\Controllers\Panel\TrmController;
use App\Http\Controllers\Panel\Facturas\FacturasGridController;
use App\Http\Controllers\Panel\Facturas\FacturasPageController;
use App\Http\Controllers\Panel\Facturas\FacturasProveedorGridController;
use App\Http\Controllers\Panel\Facturas\FacturasProveedorPageController;
use App\Http\Controllers\Panel\Cotizaciones\CotizacionesController;
use App\Http\Controllers\Panel\Localizacion\LocalizacionController;
use App\Http\Controllers\Panel\Perfil\PerfilController;
use App\Http\Controllers\Panel\Productos\ProductoFichaController;
use App\Http\Controllers\Panel\Productos\ProductosEstadisticasController;
use App\Http\Controllers\Panel\Productos\ProductosGridController;
use App\Http\Controllers\Panel\Productos\ProductosPageController;
use App\Http\Controllers\Panel\Societe\TerceroFichaController;
use App\Http\Controllers\Panel\Solicitudes\SolicitudAsistenteController;
use App\Http\Controllers\Panel\Solicitudes\SolicitudFichaController;
use App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController;
use App\Http\Controllers\Panel\Solicitudes\SolicitudesPageController;
use App\Http\Controllers\Panel\Solicitudes\SolicitudesTableroController;
use App\Http\Controllers\Panel\Societe\TerceroIaController;
use App\Http\Controllers\Panel\Societe\TercerosEstadisticasController;
use App\Http\Controllers\Panel\Societe\TercerosGridController;
use App\Http\Controllers\Panel\Societe\TercerosPageController;
use App\Http\Controllers\Panel\Usuarios\UsuarioEditController;
use App\Http\Controllers\Panel\Usuarios\UsuariosGridController;
use App\Http\Controllers\Panel\Usuarios\UsuariosPageController;
use Illuminate\Support\Facades\Route;

if (! config('app.panel_enabled')) {
    Route::get('/', function () {
        return view('welcome');
    });

    return;
}

Route::post('/microsoft/webhook', [MicrosoftController::class, 'webhook'])->name('microsoft.webhook');

Route::middleware('guest:web')->group(function () {
    Route::get('/', [PanelAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [PanelAuthController::class, 'login'])->name('login.submit');
});

Route::prefix('panel')->name('panel.')->middleware('auth:web')->group(function () {
    Route::get('/', [PanelDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [PanelDashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/dashboard/etapas', [PanelDashboardController::class, 'etapas'])->name('dashboard.etapas');
    Route::get('/dashboard/etapas/{etapa}/documentos', [PanelDashboardController::class, 'documentosEtapa'])->where('etapa', 'solicitud|cotizacion|orden|entrega')->name('dashboard.etapa.documentos');
    Route::get('/dashboard/colores', [PanelDashboardController::class, 'colores'])->name('dashboard.colores');
    Route::put('/dashboard/colores', [PanelDashboardController::class, 'guardarColores'])->name('dashboard.colores.guardar');
    Route::get('/dashboard/proceso', [PanelDashboardController::class, 'proceso'])->name('dashboard.proceso');
    Route::get('/dashboard/proceso/{etapa}/documentos', [PanelDashboardController::class, 'procesoDocumentos'])->where('etapa', '[a-z]+')->name('dashboard.proceso.documentos');
    Route::get('/dashboard/leyenda', [PanelDashboardController::class, 'leyenda'])->name('dashboard.leyenda');

    // La URL de redirección registrada en Azure (MS_REDIRECT_URI) es esta.
    Route::get('/integraciones/microsoft/callback', [MicrosoftController::class, 'callback'])->name('microsoft.callback');

    Route::prefix('microsoft')->name('microsoft.')->group(function () {
        Route::get('/estado', [MicrosoftController::class, 'estado'])->name('estado');
        Route::get('/eventos', [MicrosoftController::class, 'eventos'])->name('eventos');
        Route::delete('/evento', [MicrosoftController::class, 'eliminarEvento'])->name('evento.eliminar');
        Route::post('/evento/rechazar', [MicrosoftController::class, 'rechazarEvento'])->name('evento.rechazar');
        Route::get('/conectar', [MicrosoftController::class, 'conectar'])->name('conectar');
        Route::put('/preferencias', [MicrosoftController::class, 'preferencias'])->name('preferencias');
        Route::post('/sincronizar', [MicrosoftController::class, 'sincronizar'])->name('sincronizar');
        Route::delete('/', [MicrosoftController::class, 'desconectar'])->name('desconectar');
    });

    Route::prefix('tareas')->name('tareas.')->group(function () {
        Route::get('/formulario', [TareasController::class, 'formulario'])->name('formulario');
        Route::get('/elementos', [TareasController::class, 'elementos'])->name('elementos');
        Route::post('/interpretar', [TareasController::class, 'interpretar'])->name('interpretar');
        Route::post('/', [TareasController::class, 'store'])->name('store');
        Route::get('/{tarea}', [TareasController::class, 'show'])->whereNumber('tarea')->name('show');
        Route::delete('/{tarea}', [TareasController::class, 'destroy'])->whereNumber('tarea')->name('destroy');
        Route::post('/{tarea}/rechazar', [TareasController::class, 'rechazar'])->whereNumber('tarea')->name('rechazar');
        Route::put('/{tarea}/estado', [TareasController::class, 'estado'])->whereNumber('tarea')->name('estado');
        Route::put('/{tarea}/posponer', [TareasController::class, 'posponer'])->whereNumber('tarea')->name('posponer');
    });

    Route::prefix('actividades')->name('actividades.')->group(function () {
        Route::get('/', [ActividadesController::class, 'index'])->name('index');
        Route::get('/documentos', [ActividadesController::class, 'documentos'])->name('documentos');
        Route::get('/panel', [ActividadesController::class, 'panel'])->name('panel');
        Route::get('/cliente-meses', [ActividadesController::class, 'clienteMeses'])->name('cliente-meses');
        Route::post('/', [ActividadesController::class, 'store'])->name('store');
        Route::put('/{evento}/estado', [ActividadesController::class, 'estado'])->whereNumber('evento')->name('estado');
    });

    Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
        Route::get('/', [SolicitudesPageController::class, 'index'])->name('index');
        Route::post('/data', [SolicitudesGridController::class, 'data'])->name('data');
        Route::get('/estadisticas', [SolicitudesPageController::class, 'estadisticas'])->name('estadisticas');
        Route::get('/asistente/clientes', [SolicitudAsistenteController::class, 'clientes'])->name('asistente.clientes');
        Route::get('/asistente/cliente/{cliente}', [SolicitudAsistenteController::class, 'cliente'])->whereNumber('cliente')->name('asistente.cliente');
        Route::get('/asistente/cliente/{cliente}/efectividad', [SolicitudAsistenteController::class, 'efectividad'])->whereNumber('cliente')->name('asistente.cliente.efectividad');
        Route::get('/asistente/cliente/{cliente}/piezas', [SolicitudAsistenteController::class, 'piezasCliente'])->whereNumber('cliente')->name('asistente.cliente.piezas');
        Route::post('/asistente/cliente/{cliente}/coincidencias', [SolicitudAsistenteController::class, 'coincidencias'])->whereNumber('cliente')->name('asistente.cliente.coincidencias');
        Route::post('/asistente/historial', [SolicitudAsistenteController::class, 'historial'])->name('asistente.historial');
        Route::get('/asistente/correo/contexto', [SolicitudAsistenteController::class, 'correoContexto'])->name('asistente.correo.contexto');
        Route::post('/asistente/correo/redactar', [SolicitudAsistenteController::class, 'correoRedactar'])->name('asistente.correo.redactar');
        Route::post('/asistente/interpretar', [SolicitudAsistenteController::class, 'interpretar'])->name('asistente.interpretar');
        Route::post('/asistente/analizar', [SolicitudAsistenteController::class, 'analizar'])->name('asistente.analizar');
        Route::get('/asistente/prompt', [SolicitudAsistenteController::class, 'prompt'])->name('asistente.prompt');
        Route::put('/asistente/prompt', [SolicitudAsistenteController::class, 'guardarPrompt'])->name('asistente.prompt.guardar');
        Route::get('/tablero/motivos', [SolicitudesTableroController::class, 'motivos'])->name('tablero.motivos');
        Route::put('/tablero/columnas', [SolicitudesTableroController::class, 'guardarColumnas'])->name('tablero.columnas.guardar');
        Route::get('/tablero/{columna}', [SolicitudesTableroController::class, 'columna'])->where('columna', '[a-z]+')->name('tablero.columna');
        Route::post('/{solicitud}/mover', [SolicitudesTableroController::class, 'mover'])->whereNumber('solicitud')->name('mover');
        Route::post('/export/xlsx', [SolicitudesGridController::class, 'exportXlsx'])->name('export.xlsx');
        Route::post('/export/csv', [SolicitudesGridController::class, 'exportCsv'])->name('export.csv');

        Route::get('/nueva/ficha', [SolicitudFichaController::class, 'nuevo'])->name('nueva');
        Route::post('/', [SolicitudFichaController::class, 'store'])->name('store');
        Route::get('/contactos', [SolicitudFichaController::class, 'contactos'])->name('contactos');
        Route::get('/productos', [SolicitudFichaController::class, 'productos'])->name('productos');
        Route::get('/cliente-resumen', [SolicitudFichaController::class, 'clienteResumen'])->name('cliente-resumen');
        Route::get('/cliente-meses', [SolicitudFichaController::class, 'clienteMeses'])->name('cliente-meses');
        Route::post('/productos/inteligencia', [SolicitudFichaController::class, 'inteligencia'])->name('inteligencia');
        Route::get('/{solicitud}/ficha', [SolicitudFichaController::class, 'show'])->whereNumber('solicitud')->name('ficha');
        Route::put('/{solicitud}', [SolicitudFichaController::class, 'update'])->whereNumber('solicitud')->name('update');
        Route::get('/{solicitud}/seguimiento', [SolicitudFichaController::class, 'seguimientoContexto'])->whereNumber('solicitud')->name('seguimiento.contexto');
        Route::post('/{solicitud}/seguimiento', [SolicitudFichaController::class, 'seguimientoGuardar'])->whereNumber('solicitud')->name('seguimiento.guardar');
        Route::post('/{solicitud}/seguimiento/ia', [SolicitudFichaController::class, 'seguimientoIa'])->whereNumber('solicitud')->name('seguimiento.ia');
        Route::post('/{solicitud}/correo-ia', [SolicitudFichaController::class, 'redactarCorreo'])->whereNumber('solicitud')->name('correo-ia');
        Route::post('/{solicitud}/cerrar', [SolicitudFichaController::class, 'cerrar'])->whereNumber('solicitud')->name('cerrar');
        Route::post('/{solicitud}/reabrir', [SolicitudFichaController::class, 'reabrir'])->whereNumber('solicitud')->name('reabrir');
    });

    Route::prefix('cotizaciones')->name('cotizaciones.')->group(function () {
        Route::get('/', [CotizacionesController::class, 'index'])->name('index');
        Route::get('/tablero/{columna}', [CotizacionesController::class, 'columna'])->where('columna', '[a-z]+')->name('tablero.columna');
    });

    Route::prefix('localizacion')->name('localizacion.')->group(function () {
        Route::get('/', [LocalizacionController::class, 'index'])->name('index');
        Route::get('/listado', [LocalizacionController::class, 'listado'])->name('listado');
        Route::get('/tablero/{columna}', [LocalizacionController::class, 'columna'])->where('columna', '[a-z]+')->name('tablero.columna');
        Route::get('/contexto', [LocalizacionController::class, 'contexto'])->name('contexto');
        Route::post('/sugeridos', [LocalizacionController::class, 'sugeridos'])->name('sugeridos');
        Route::get('/proveedores', [LocalizacionController::class, 'buscar'])->name('proveedores');
        Route::get('/proveedores/{proveedor}', [LocalizacionController::class, 'proveedor'])->whereNumber('proveedor')->name('proveedor');
        Route::get('/prompt', [LocalizacionController::class, 'prompt'])->name('prompt');
        Route::put('/prompt', [LocalizacionController::class, 'guardarPrompt'])->name('prompt.guardar');
        Route::get('/{solicitud}', [LocalizacionController::class, 'solicitudDetalle'])->whereNumber('solicitud')->name('solicitud');
        Route::post('/{solicitud}/redactar', [LocalizacionController::class, 'redactar'])->whereNumber('solicitud')->name('redactar');
        Route::post('/{solicitud}/enviar', [LocalizacionController::class, 'enviar'])->whereNumber('solicitud')->name('enviar');
    });

    Route::prefix('trm')->name('trm.')->group(function () {
        Route::get('/', [TrmController::class, 'index'])->name('index');
        Route::get('/historial', [TrmController::class, 'historial'])->name('historial');
        Route::put('/config', [TrmController::class, 'update'])->name('update');
        Route::post('/cargar', [TrmController::class, 'cargar'])->name('cargar');
    });
    Route::post('/logout', [PanelAuthController::class, 'logout'])->name('logout');

    Route::prefix('societe')->name('societe.')->group(function () {
        Route::get('/terceros', [TercerosPageController::class, 'index'])->name('terceros.index');
        Route::post('/terceros/data', [TercerosGridController::class, 'data'])->name('terceros.data');
        Route::get('/terceros/estadisticas', TercerosEstadisticasController::class)->name('terceros.estadisticas');
        Route::post('/terceros/export/xlsx', [TercerosGridController::class, 'exportXlsx'])->name('terceros.export.xlsx');
        Route::post('/terceros/export/csv', [TercerosGridController::class, 'exportCsv'])->name('terceros.export.csv');
        Route::get('/terceros/nuevo/ficha', [TerceroFichaController::class, 'nuevo'])->name('terceros.nuevo');
        Route::post('/terceros', [TerceroFichaController::class, 'store'])->name('terceros.store');
        Route::get('/terceros/ia/inicio', [TerceroIaController::class, 'inicio'])->name('terceros.ia.inicio');
        Route::post('/terceros/ia/mensaje', [TerceroIaController::class, 'mensaje'])->name('terceros.ia.mensaje');
        Route::post('/terceros/ia/rut', [TerceroIaController::class, 'rut'])->name('terceros.ia.rut');
        Route::post('/terceros/ia/crear', [TerceroIaController::class, 'crear'])->name('terceros.ia.crear');
        Route::get('/terceros/{tercero}/ficha', [TerceroFichaController::class, 'show'])->whereNumber('tercero')->name('terceros.ficha');
        Route::put('/terceros/{tercero}', [TerceroFichaController::class, 'update'])->whereNumber('tercero')->name('terceros.update');
    });

    Route::prefix('usuarios')->name('usuarios.')->group(function () {
        Route::get('/', [UsuariosPageController::class, 'index'])->name('index');
        Route::post('/data', [UsuariosGridController::class, 'data'])->name('data');
        Route::post('/export/xlsx', [UsuariosGridController::class, 'exportXlsx'])->name('export.xlsx');
        Route::post('/export/csv', [UsuariosGridController::class, 'exportCsv'])->name('export.csv');

        Route::get('/{usuario}/editar', [UsuarioEditController::class, 'edit'])->name('edit');
        Route::get('/{usuario}/ficha', [UsuarioEditController::class, 'show'])->name('ficha');
        Route::put('/{usuario}', [UsuarioEditController::class, 'update'])->name('update');
        Route::put('/{usuario}/password', [UsuarioEditController::class, 'updatePassword'])->name('password');
        Route::put('/{usuario}/grupos', [UsuarioEditController::class, 'updateGrupos'])->name('grupos');
        Route::put('/{usuario}/permisos', [UsuarioEditController::class, 'updatePermisos'])->name('permisos');
    });

    Route::prefix('configuracion')->name('configuracion.')->group(function () {
        Route::get('/', [ConfiguracionController::class, 'index'])->name('index');
        Route::put('/panel', [ConfiguracionController::class, 'guardarPanel'])->name('panel');
        Route::put('/proceso', [ConfiguracionController::class, 'guardarProceso'])->name('proceso');
        Route::put('/ia', [ConfiguracionController::class, 'guardarIa'])->name('ia');
        Route::post('/ia/probar', [ConfiguracionController::class, 'probarIa'])->name('ia.probar');
        Route::put('/ia/solicitudes', [ConfiguracionController::class, 'guardarPromptsSolicitud'])->name('ia.solicitudes');
        Route::put('/ia/terceros', [ConfiguracionController::class, 'guardarTerceros'])->name('ia.terceros');
        Route::delete('/ia/terceros', [ConfiguracionController::class, 'restablecerTerceros'])->name('ia.terceros.restablecer');
    });

    Route::prefix('perfil')->name('perfil.')->group(function () {
        Route::get('/', [PerfilController::class, 'edit'])->name('edit');
        Route::put('/', [PerfilController::class, 'update'])->name('update');
        Route::put('/password', [PerfilController::class, 'updatePassword'])->name('password');
    });

    Route::prefix('productos')->name('productos.')->group(function () {
        Route::get('/', [ProductosPageController::class, 'index'])->name('index');
        Route::post('/data', [ProductosGridController::class, 'data'])->name('data');
        Route::get('/estadisticas', ProductosEstadisticasController::class)->name('estadisticas');
        Route::post('/export/xlsx', [ProductosGridController::class, 'exportXlsx'])->name('export.xlsx');
        Route::post('/export/csv', [ProductosGridController::class, 'exportCsv'])->name('export.csv');

        Route::get('/nuevo/ficha', [ProductoFichaController::class, 'nuevo'])->name('nuevo');
        Route::post('/', [ProductoFichaController::class, 'store'])->name('store');
        Route::get('/{producto}/ficha', [ProductoFichaController::class, 'show'])->whereNumber('producto')->name('ficha');
        Route::put('/{producto}', [ProductoFichaController::class, 'update'])->whereNumber('producto')->name('update');
    });

    Route::get('/servicios', [ProductosPageController::class, 'servicios'])->name('servicios.index');

    Route::prefix('categorias')->name('categorias.')->group(function () {
        Route::get('/', [CategoriasPageController::class, 'index'])->name('index');
        Route::post('/data', [CategoriasGridController::class, 'data'])->name('data');
        Route::post('/export/xlsx', [CategoriasGridController::class, 'exportXlsx'])->name('export.xlsx');
        Route::post('/export/csv', [CategoriasGridController::class, 'exportCsv'])->name('export.csv');

        Route::get('/nuevo/ficha', [CategoriaFichaController::class, 'nuevo'])->name('nuevo');
        Route::post('/', [CategoriaFichaController::class, 'store'])->name('store');
        Route::get('/{categoria}/ficha', [CategoriaFichaController::class, 'show'])->whereNumber('categoria')->name('ficha');
        Route::put('/{categoria}', [CategoriaFichaController::class, 'update'])->whereNumber('categoria')->name('update');
        Route::delete('/{categoria}', [CategoriaFichaController::class, 'destroy'])->whereNumber('categoria')->name('destroy');
    });

    Route::prefix('facturas')->name('facturas.')->group(function () {
        Route::get('/', [FacturasPageController::class, 'index'])->name('index');
        Route::post('/data', [FacturasGridController::class, 'data'])->name('data');
        Route::post('/export/xlsx', [FacturasGridController::class, 'exportXlsx'])->name('export.xlsx');
        Route::post('/export/csv', [FacturasGridController::class, 'exportCsv'])->name('export.csv');

        Route::prefix('proveedores')->name('proveedores.')->group(function () {
            Route::get('/', [FacturasProveedorPageController::class, 'index'])->name('index');
            Route::post('/data', [FacturasProveedorGridController::class, 'data'])->name('data');
            Route::post('/export/xlsx', [FacturasProveedorGridController::class, 'exportXlsx'])->name('export.xlsx');
            Route::post('/export/csv', [FacturasProveedorGridController::class, 'exportCsv'])->name('export.csv');
        });
    });
});
