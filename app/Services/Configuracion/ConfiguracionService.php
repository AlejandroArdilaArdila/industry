<?php

namespace App\Services\Configuracion;

use App\Models\ActividadEconomicaIca;
use App\Models\Almacen;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\CiudadFe;
use App\Models\Configuracion;
use App\Models\CuentaBancaria;
use App\Models\DomicilioTipo;
use App\Models\EventoWss;
use App\Models\ExtrafieldDefinicion;
use App\Models\ReglaRedondeo;
use App\Models\Turno;
use App\Models\Tva;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Usuario;
use App\Models\Uvt;
use App\Support\DolibarrCrypt;

class ConfiguracionService
{
    private const NOMBRES_CONFIG = [
        'MAIN_INFO_SOCIETE_NOM', 'MAIN_INFO_SOCIETE_ADDRESS', 'MAIN_INFO_SOCIETE_TOWN',
        'MAIN_INFO_SOCIETE_TEL', 'MAIN_INFO_SOCIETE_WEB', 'MAIN_INFO_SIREN', 'MAIN_INFO_SIRET',
        'MAIN_INFO_SOCIETE_LOGO_SMALL', 'MAIN_INFO_CLAS_TRIBUTARIA',
        'CLIENTE_PREDETERMINADO', 'TIEMPO_SEGUNDOS_ALERTAS',
        'FIDELIZACION', 'DOMICILIOS', 'SUCURSALES', 'SELECCIONAR_CAJA', 'VENTAS_SIN_STOCK', 'CREAR_PRODUCTOS', 'EDITAR_PRECIOS',
        'MOSTRAR_IMAGENES', 'ALTO_FILAS', 'CIERRE_CAJA_POS', 'CIERRE_CAJA_DETALLADO',
        'CREAR_CLIENTES', 'PRINT_LABELS', 'SEPARADOR_BUSQUEDA',
        'FACTURA_ELECTRONICA', 'FACTURA_POS', 'FACTURA_BORRADOR',
        'FACTURA_ELECTRONICA_COLOR', 'FACTURA_POS_COLOR', 'FACTURA_BORRADOR_COLOR',
        'POS_FACTURA_PREDETERMINADA', 'NIVEL_PRECIO_PIN', 'TIPO_APLICACION_PROMOCIONES',
        'POS_PEDIDOS_ESPERA', 'IMPRIMIR_RESUMEN_VENTA_EN_PEDIDO', 'NOTAS_DE_CREDITOS_TIPO_BORRADOR',
        'INTEGROSPOS_DATAFONO_URL_TOKEN', 'INTEGROSPOS_DATAFONO_URL_COMPRA',
        'INTEGROSPOS_DATAFONO_URL_CONSULTA', 'INTEGROSPOS_DATAFONO_USERNAME',
        'INTEGROSPOS_DATAFONO_PASSWORD', 'INTEGROSPOS_DATAFONO_CLIENT_ID',
        'INTEGROSPOS_DATAFONO_GRANT_TYPE', 'INTEGROSPOS_DATAFONO_CLIENT_SECRET',
        'INTEGROSPOS_DATAFONO_CODIGO_COMERCIO', 'INTEGROSPOS_DATAFONO_METODO_PAGO',
        'INTEGROSPOS_DATAFONO_TIEMPO_VALIDACION', 'LEER_CODIGO_BARRA_COMPLETO',
        'DETALLAR_PRODUCTOS_IMPRESION_FACTURA',
        'PRECIOS_REDONDEO_REGLAS_ACTIVO', 'PRECIOS_REDONDEO_CIFRAS',
    ];

    /**
     * Configuracion global del POS: nada especifico de un usuario en
     * particular (eso vive en UsuariosService::getUsersPos() -  terminal,
     * almacen, cliente predeterminado, permisos, estado de caja, etc.).
     */
    public function getConfiguracion(): array
    {
        $valores = Configuracion::valores(self::NOMBRES_CONFIG);

        $ciudad = CiudadFe::query()
            ->where('ciudad', 'like', '%'.($valores['MAIN_INFO_SOCIETE_TOWN'] ?? '').'%')
            ->first(['rowid', 'code', 'ciudad', 'depto']);

        $almacenes = Almacen::query()
            ->where('statut', 1)
            ->get(['rowid', 'ref'])
            ->map(fn ($a) => ['rowid' => (int) $a->rowid, 'almacen' => $a->ref])
            ->all();

        $idsCategoriasPos = $this->categoriasPos();
        $labelsPorCategoria = Categoria::query()
            ->whereIn('rowid', $idsCategoriasPos)
            ->pluck('label', 'rowid');

        // Raiz "SUCURSAL" de las categorias de cuentas bancarias (type=5 en Dolibarr),
        // para no confundirla con una categoria homonima de productos/almacenes.
        $idsRaizSucursal = Categoria::query()
            ->where('type', 5)
            ->whereRaw('UPPER(TRIM(label)) = ?', ['SUCURSAL'])
            ->pluck('rowid')
            ->map(fn ($id) => (int) $id)
            ->all();
        $idsHijosSucursal = array_values(array_diff(
            array_map('intval', Categoria::idsConDescendientes($idsRaizSucursal)),
            $idsRaizSucursal
        ));

        $idsCategoriasCargar = array_merge($idsCategoriasPos, $idsHijosSucursal);

        $metodos = CuentaBancaria::query()
            ->whereHas('categorias', function ($q) use ($idsCategoriasPos) {
                $q->whereIn('categorie.rowid', $idsCategoriasPos);
            })
            ->with(['categorias' => function ($q) use ($idsCategoriasCargar) {
                $q->whereIn('categorie.rowid', $idsCategoriasCargar)->select('categorie.rowid', 'categorie.label', 'categorie.fk_parent');
            }])
            ->orderByRaw("(ref = 'LIQ') DESC")
            ->orderBy('ref')
            ->get(['rowid', 'ref', 'label'])
            ->map(function ($m) use ($labelsPorCategoria, $idsCategoriasPos, $idsRaizSucursal, $idsHijosSucursal) {
                // Se excluye el arbol SUCURSAL por si cuelga de "POS": no es la categoria del metodo.
                $cat = $m->categorias->first(fn ($c) => in_array((int) $c->rowid, $idsCategoriasPos, true)
                    && ! in_array((int) $c->rowid, $idsRaizSucursal, true)
                    && ! in_array((int) $c->rowid, $idsHijosSucursal, true));
                // Si la categoria asignada tiene padre (ej. "Efectivo" bajo "POS"), esa es
                // la subcategoria y el padre es la categoria. Si no tiene padre (asignada
                // directo a la raiz "POS"), esa es la categoria y no hay subcategoria.
                $tienePadre = $cat && (int) $cat->fk_parent > 0;
                $categoria = $tienePadre ? ($labelsPorCategoria[$cat->fk_parent] ?? '') : ($cat->label ?? '');
                // "POS" es la raiz de todo este listado (ya viene pre-filtrado a POS), asi
                // que mostrarla como categoria no aporta nada - se oculta igual que antes.
                if ($categoria === 'POS') {
                    $categoria = '';
                }

                // "SUCURSAL" es un arbol de categorias aparte (ej. "Sucursal >> Almacen 1"),
                // usado para asociar la cuenta a un almacen. Solo se llena si la cuenta tiene
                // una etiqueta hija de esa raiz; si no, queda vacio.
                $catSucursal = $m->categorias->first(fn ($c) => in_array((int) $c->rowid, $idsHijosSucursal, true));

                return [
                    'id' => (int) $m->rowid,
                    'code' => $m->ref,
                    'metodo' => $m->label,
                    'categoria' => ($m->categorias->first()?->label=='POS')?'':$m->categorias->first()?->label,
                    'SUCURSAL' => $catSucursal->label ?? '',
                ];
            })
            ->all();

        $domicilios = $this->domicilios();

        $turnos = $this->turnos();

        $uvt = $this->uvtVigente();

        return [
            'empresa' => $valores['MAIN_INFO_SOCIETE_NOM'] ?? '',
            'nit' => $valores['MAIN_INFO_SIREN'] ?? '',
            'tipoIdentificacion' => $valores['MAIN_INFO_SIRET'] ?? '',
            'uvt' => $uvt ? (float) $uvt->valor : 0,
            'anioUvt' => $uvt->anio ?? null,
            'idClasificacionTributaria' => (int) ($valores['MAIN_INFO_CLAS_TRIBUTARIA'] ?? 0),
            'exclusionesRetencionVenta' => $this->exclusionesRetencionVenta((int) ($valores['MAIN_INFO_CLAS_TRIBUTARIA'] ?? 0)),
            'conceptosReteiva' => $this->conceptosReteiva(),
            'tarifasRetefuente' => $this->tarifasRetefuente(),
            'code' => $ciudad->code ?? 0,
            'idCiudad' => (int) ($ciudad->rowid ?? 0),
            'url' => $valores['MAIN_INFO_SOCIETE_WEB'] ?? '',
            'ciudad' => ($ciudad->ciudad ?? 'Ciudad no registrada').'('.($ciudad->depto ?? 'Departamento no hallado').')',
            'logo' => '/viewimage.php?modulepart=mycompany&file=logos/thumbs/'.($valores['MAIN_INFO_SOCIETE_LOGO_SMALL'] ?? ''),
            'direccion' => $valores['MAIN_INFO_SOCIETE_ADDRESS'] ?? '',
            'telefono' => $valores['MAIN_INFO_SOCIETE_TEL'] ?? '',
            'mensajeAlerta' => '',
            'tiempoSegundosAlertas' => (int) ($valores['TIEMPO_SEGUNDOS_ALERTAS'] ?? 0),
            'fidelizacion' => ($valores['FIDELIZACION'] ?? null) == 1,
            'domiciliosHabilitado' => ($valores['DOMICILIOS'] ?? null) == 1,
            'sucursalesHabilitado' => ($valores['SUCURSALES'] ?? null) == 1,
            'seleccionarCaja' => ($valores['SELECCIONAR_CAJA'] ?? null) == 1,
            'domicilios' => $domicilios,
            'turnos' => $turnos,
            'controlStock' => ($valores['VENTAS_SIN_STOCK'] ?? null) != 1,
            'crearproductos' => ($valores['CREAR_PRODUCTOS'] ?? null) == 1,
            'editarprecios' => ($valores['EDITAR_PRECIOS'] ?? null) == 1,
            'almacenes' => $almacenes,
            'metodos' => $metodos,
            'mostrarImagenes' => ($valores['MOSTRAR_IMAGENES'] ?? null) == 1,
            'altoFilas' => (int) ($valores['ALTO_FILAS'] ?? 0),
            'cierreCajaPos' => ($valores['CIERRE_CAJA_POS'] ?? null) == 1,
            'cierreCajaDetallado' => ($valores['CIERRE_CAJA_DETALLADO'] ?? null) == 1,
            'crearClientes' => ($valores['CREAR_CLIENTES'] ?? null) == 1,
            'printLabels' => ($valores['PRINT_LABELS'] ?? null) == 1,
            'separadorBusqueda' => ($valores['SEPARADOR_BUSQUEDA'] ?? '') !== '' ? $valores['SEPARADOR_BUSQUEDA'] : 'No configurado',
            'facturaElectronica' => ($valores['FACTURA_ELECTRONICA'] ?? null) == 1,
            'facturaPos' => ($valores['FACTURA_POS'] ?? null) == 1,
            'facturaBorrador' => ($valores['FACTURA_BORRADOR'] ?? null) == 1,
            'facturaElectronicaColor' => ! empty($valores['FACTURA_ELECTRONICA']) && ! empty($valores['FACTURA_ELECTRONICA_COLOR']) ? $valores['FACTURA_ELECTRONICA_COLOR'] : '#ffffff',
            'facturaPosColor' => ! empty($valores['FACTURA_POS']) && ! empty($valores['FACTURA_POS_COLOR']) ? $valores['FACTURA_POS_COLOR'] : '#ffffff',
            'facturaBorradorColor' => ! empty($valores['FACTURA_BORRADOR']) && ! empty($valores['FACTURA_BORRADOR_COLOR']) ? $valores['FACTURA_BORRADOR_COLOR'] : '#ffffff',
            'facturaPredeterminada' => ($valores['POS_FACTURA_PREDETERMINADA'] ?? '') !== '' ? $valores['POS_FACTURA_PREDETERMINADA'] : 'No configurado',
            'nivelPrecioPin' => ($valores['NIVEL_PRECIO_PIN'] ?? '') !== '' ? $valores['NIVEL_PRECIO_PIN'] : 'No configurado',
            'tipoAplicacionPromociones' => ($valores['TIPO_APLICACION_PROMOCIONES'] ?? '') !== '' ? $valores['TIPO_APLICACION_PROMOCIONES'] : 'No configurado',
            'pedidosEspera' => (int) ($valores['POS_PEDIDOS_ESPERA'] ?? 3),
            'imprimirResumenVentaEnPedido' => ($valores['IMPRIMIR_RESUMEN_VENTA_EN_PEDIDO'] ?? null) == 1,
            'notasDeCreditosTipoBorrador' => ($valores['NOTAS_DE_CREDITOS_TIPO_BORRADOR'] ?? null) == 1,
            'leerCodigoBarraCompleto' => ($valores['LEER_CODIGO_BARRA_COMPLETO'] ?? null) == 1,
            'detallarProductosImpresionFactura' => ($valores['DETALLAR_PRODUCTOS_IMPRESION_FACTURA'] ?? null) == 1,
            'reglaRedondeo' => $this->reglaRedondeo($valores),
            'datafonoConfig' => [[
                'urlToken' => $valores['INTEGROSPOS_DATAFONO_URL_TOKEN'] ?? '',
                'urlCompra' => $valores['INTEGROSPOS_DATAFONO_URL_COMPRA'] ?? '',
                'urlConsulta' => $valores['INTEGROSPOS_DATAFONO_URL_CONSULTA'] ?? '',
                'username' => $valores['INTEGROSPOS_DATAFONO_USERNAME'] ?? '',
                'password' => DolibarrCrypt::decrypt($valores['INTEGROSPOS_DATAFONO_PASSWORD'] ?? '') ?? '',
                'client_id' => $valores['INTEGROSPOS_DATAFONO_CLIENT_ID'] ?? '',
                'grant_type' => $valores['INTEGROSPOS_DATAFONO_GRANT_TYPE'] ?? '',
                'client_secret' => DolibarrCrypt::decrypt($valores['INTEGROSPOS_DATAFONO_CLIENT_SECRET'] ?? '') ?? '',
                'codigoDeComercio' => $valores['INTEGROSPOS_DATAFONO_CODIGO_COMERCIO'] ?? '',
                'metodoPago' => $valores['INTEGROSPOS_DATAFONO_METODO_PAGO'] ?? '',
                'tiempoValidacion' => (int) ($valores['INTEGROSPOS_DATAFONO_TIEMPO_VALIDACION'] ?? 0),
            ]],
        ];
    }

    /**
     * Grupos de retencion (ICA/IVA/Fuente) excluidos en VENTAS para la clasificacion
     * tributaria de la propia empresa (integros_exclusiones_grupo_cuenta, ambito='empresa',
     * aplica_venta=1 - ver custom/integros/lib/movimientosContables.lib.php::getGruposCuentaExcluidos()).
     * Las reglas con ambito='tercero' no se incluyen aqui: hoy ninguna tiene aplica_venta=1
     * (solo restringen compras), asi que la clasificacion del cliente no entra en esta cuenta.
     * Si alguna vez se agrega una regla de tercero con aplica_venta=1, esto dejaria de ser
     * exacto y habria que resolverla por cliente en vez de en esta configuracion global.
     */
    private function exclusionesRetencionVenta(int $clasifTribEmpresa): array
    {
        $gruposExcluidos = DB::table('integros_exclusiones_grupo_cuenta')
            ->where('active', 1)
            ->where('aplica_venta', 1)
            ->where('ambito', 'empresa')
            ->where('fk_clasificacion_tributaria', $clasifTribEmpresa)
            ->pluck('fk_grupo_cuenta')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'ica' => in_array(9, $gruposExcluidos, true),
            'iva' => in_array(10, $gruposExcluidos, true),
            'fuente' => in_array(11, $gruposExcluidos, true),
        ];
    }

    /**
     * Tarifas de Retencion en la Fuente por tipo_contribuyente del CLIENTE, para
     * ventas (tipo_doc=2), modo INTEGROS_MODO_RETENCION='general' (el que corre hoy -
     * ver custom/integros/lib/productos.lib.php::getRetencionProducto()). Sale de
     * integros_plantillas_contables_cuentas + cuentas_contables (fk_grupo_cuenta=11).
     * Es 100% data-driven: no se asume ninguna tarifa fija por tipo_contribuyente,
     * se expone lo que de verdad este configurado en la plantilla contable.
     */
    private function tarifasRetefuente(): array
    {
        return DB::table('integros_plantillas_contables_cuentas as plc')
            ->join('cuentas_contables as cc', 'cc.codigo', '=', 'plc.cuenta')
            ->where('cc.fk_grupo_cuenta', 11)
            ->where('plc.tipo_doc', 2)
            ->select('plc.tipo_contribuyente', 'cc.porcentaje', 'cc.base')
            ->distinct()
            ->orderBy('plc.tipo_contribuyente')
            ->get()
            ->map(fn ($r) => [
                'tipoContribuyente' => (int) $r->tipo_contribuyente,
                'porcentaje' => (float) $r->porcentaje * 100,
                'baseUvt' => (float) $r->base,
            ])
            ->all();
    }

    /**
     * Valor del UVT del año actual (llx_c_uvt.valor/anio). Si no hay registro
     * para el año actual, cae al del año anterior.
     */
    private function uvtVigente(): ?Uvt
    {
        $anioActual = (int) date('Y');

        return Uvt::query()->where('anio', $anioActual)->first(['valor', 'anio'])
            ?? Uvt::query()->where('anio', $anioActual - 1)->first(['valor', 'anio']);
    }

    /**
     * Departamentos y ciudades (con su codigo DIAN) para formularios de domicilio.
     */
    public function departamentosYCiudades(): array
    {
        $departamentos = CiudadFe::query()
            ->where('active', 1)
            ->select('code_depto', 'depto')
            ->distinct()
            ->orderBy('depto')
            ->get()
            ->map(fn ($d) => [
                'code_depto' => (string) $d->code_depto,
                'depto' => (string) $d->depto,
            ])
            ->all();

        $ciudades = CiudadFe::query()
            ->where('active', 1)
            ->select('rowid', 'code_depto', 'code', 'ciudad', 'zip')
            ->orderBy('ciudad')
            ->get()
            ->map(fn ($c) => [
                'rowid' => (string) $c->rowid,
                'code_depto' => (string) $c->code_depto,
                'code' => (string) $c->code,
                'ciudad' => (string) $c->ciudad,
                'zip' => (string) $c->zip,
            ])
            ->all();

        return ['departamentos' => $departamentos, 'ciudades' => $ciudades];
    }

    /**
     * Replica getCategorieIva(): catalogos de categorias de producto (POS),
     * tarifas de IVA/impoconsumo de Colombia, y unidades de peso/volumen.
     * No hay capa de traduccion ($langs) en este proyecto, por lo que las
     * unidades usan directamente su short_label (kg, g, L, m3, etc.).
     */
    public function getCategorieIva(): array
    {
        $categorias = [];

        $paramCategoria = ExtrafieldDefinicion::query()
            ->where('name', 'like', 'categoria')
            ->value('param');

        if ($paramCategoria) {
            $parametros = @unserialize($paramCategoria);

            foreach ($parametros['options'] ?? [] as $clave => $opcion) {
                $categorias[] = [
                    'valor' => (string) $clave,
                    'label' => $opcion,
                ];
            }
        }

        $ivas = Tva::query()
            ->where('fk_pays', 70)
            ->where('active', 1)
            ->get(['code', 'taux', 'localtax1', 'localtax1_type'])
            ->map(fn ($iva) => [
                'valor' => (string) ((int) $iva->localtax1_type === 1 ? $iva->localtax1 : $iva->taux),
                'label' => $iva->code,
            ])
            ->all();

        $peso = UnidadMedida::query()
            ->where('unit_type', 'weight')
            ->get(['scale', 'short_label'])
            ->map(fn ($u) => ['valor' => (string) $u->scale, 'label' => $u->short_label])
            ->all();

        $volumen = UnidadMedida::query()
            ->where('unit_type', 'volume')
            ->get(['scale', 'short_label'])
            ->map(fn ($u) => ['valor' => (string) $u->scale, 'label' => $u->short_label])
            ->all();

        return [
            'ivas' => $ivas,
            'categorias' => $categorias,
            'peso' => $peso,
            'volumen' => $volumen,
        ];
    }

    /**
     * Jerarquia de almacenes (llx_entrepot): las "sucursales" son los almacenes
     * padre (fk_parent nulo/0) y cada una trae sus almacenes hijos. Mismo
     * criterio que getFormCatalogo() en integrospos/class/api_terminales.class.php
     * (Dolibarr), replicado aqui para el cliente de escritorio (api-c).
     */
    public function almacenesJerarquia(): array
    {
        $almacenes = Almacen::query()
            ->where('statut', 1)
            ->orderBy('ref')
            ->get(['rowid', 'ref', 'fk_parent']);

        $hijosPorPadre = $almacenes
            ->filter(fn ($a) => (int) $a->fk_parent > 0)
            ->groupBy(fn ($a) => (int) $a->fk_parent);

        return $almacenes
            ->filter(fn ($a) => (int) $a->fk_parent <= 0)
            ->map(fn ($sucursal) => [
                'id' => (int) $sucursal->rowid,
                'nombre' => $sucursal->ref,
                'hijos' => ($hijosPorPadre->get((int) $sucursal->rowid) ?? collect())
                    ->map(fn ($hijo) => ['id' => (int) $hijo->rowid, 'nombre' => $hijo->ref])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Catalogo de conceptos de retencion de IVA (llx_retenciones_conf, tipo=2),
     * usado para resolver porcentaje/umbral a partir del fk_reteiva asignado a un
     * cliente (societe_extrafields.fk_reteiva). base_imponible se recalcula en vivo
     * con el UVT vigente, igual que getRetenciones() en custom/integros/lib/productos.lib.php
     * (el valor guardado en la columna de la misma tabla puede estar desactualizado).
     */
    private function conceptosReteiva(): array
    {
        $uvt = $this->uvtVigente();
        $valorUvt = $uvt ? (float) $uvt->valor : 0;

        return DB::table('retenciones_conf')
            ->where('tipo', 2)
            ->orderBy('rowid')
            ->get(['rowid', 'concepto', 'porcentaje', 'base_uvt'])
            ->map(fn ($r) => [
                'id' => (int) $r->rowid,
                'concepto' => $r->concepto,
                'porcentaje' => (float) $r->porcentaje,
                'baseUvt' => (int) $r->base_uvt,
                'baseImponible' => (int) $r->base_uvt * $valorUvt,
            ])
            ->all();
    }

    /**
     * Catalogo de actividades economicas ICA (llx_c_actividad_economica_ica),
     * usado para resolver tarifa/base imponible a partir de la agrupacion
     * ligada al codigo CIIU de un cliente (llx_c_codigos_ciiu.agrupacion).
     *
     * base_imponible se recalcula en vivo contra el UVT vigente: la columna
     * guarda un CONTEO DE UVT (a pesar del nombre), igual que hace el ERP en
     * getRetencionIcaTercero() (custom/integros/lib/productos.lib.php) - usar
     * el valor crudo de la columna como si fueran pesos daria un umbral 50 mil
     * veces mas chico de lo real.
     */
    public function actividadesEconomicasIca(): array
    {
        $uvt = $this->uvtVigente();
        $valorUvt = $uvt ? (float) $uvt->valor : 0;

        return ActividadEconomicaIca::query()
            ->where('active', 1)
            ->orderBy('agrupacion')
            ->get(['agrupacion', 'tarifa', 'base_imponible'])
            ->map(fn ($a) => [
                'agrupacion' => (int) $a->agrupacion,
                'tarifa' => (float) $a->tarifa,
                'baseUvt' => (float) $a->base_imponible,
                'base_imponible' => (float) $a->base_imponible * $valorUvt,
            ])
            ->all();
    }

    /**
     * Listado de cajas (llx_integrospos_cajas) con sus almacenes asignados
     * (llx_integrospos_cajas_almacen), resolucion DIAN y datafono, en el mismo
     * formato que UsuariosService::getUsersPos() arma por cajero para el
     * bloque 'sucursales'.
     */
    public function cajas(): array
    {
        $cajas = Caja::query()
            ->where('activo', 1)
            ->orderBy('name')
            ->select(['rowid', 'name', 'fk_warehouse_sucursal', 'datafono_codigo_terminal', 'datafono_id_cajero', 'datafono_numero_caja', 'fk_resolucion_factura', 'fk_resolucion_pos', 'fk_resolucion_borrador'])
            ->with(['almacenes' => function ($q) {
                $q->select('rowid', 'fk_caja', 'fk_warehouse');
            }])
            ->with(['almacenes.almacen' => function ($q) {
                $q->select('rowid', 'ref');
            }])
            ->with(['sucursal' => function ($q) {
                $q->select('rowid', 'ref');
            }])
            ->get();

        $resolucionesPorTipo = $this->resolucionesAsignadasPorCajas($cajas);

        return $cajas->map(fn ($caja) => [
            'rowid' => (int) $caja->rowid,
            'caja' => $caja->name,
            'sucursales' => [[
                'idSucursal' => (int) $caja->fk_warehouse_sucursal,
                'nombreSucursal' => $caja->sucursal->ref ?? '',
            ]],
            'almacenes' => $caja->almacenes->map(fn ($a) => [
                'rowid' => (int) $a->fk_warehouse,
                'almacen' => $a->almacen->ref ?? '',
            ])->values()->all(),
            'datafonoTerminal' => [
                'codigoDeTerminal' => $caja->datafono_codigo_terminal ?? '',
                'idCajero' => $caja->datafono_id_cajero ?? '',
                'numeroDeCaja' => $caja->datafono_numero_caja ?? '',
            ],
            'resolucion' => [
                'factura' => $resolucionesPorTipo[1][(int) $caja->fk_resolucion_factura] ?? null,
                'pos' => $resolucionesPorTipo[2][(int) $caja->fk_resolucion_pos] ?? null,
                'borrador' => $resolucionesPorTipo[3][(int) $caja->fk_resolucion_borrador] ?? null,
            ],
        ])->values()->all();
    }

    /**
     * Igual que UsuariosService::resolucionesAsignadas(), pero a partir de una
     * coleccion de Caja en vez de usuarios: trae de una sola vez (sin N+1 por
     * caja) los datos de las resoluciones DIAN asignadas en
     * integrospos_cajas.fk_resolucion_{factura,pos,borrador}. Cada tipo de
     * documento vive en su propia tabla (facturacione_config_invoice[_pos|
     * _borrador], mismo mapeo que ResolucionFactura::TABLAS_POR_TIPO). Solo lo
     * que esta explicitamente asignado en la caja - sin caer a la resolucion
     * activa global si no hay nada asignado.
     *
     * @return array<int, array<int, array<string, mixed>>> [tipoFac => [rowid => datos]]
     */
    private function resolucionesAsignadasPorCajas($cajas): array
    {
        $tablasPorTipo = [
            1 => 'facturacione_config_invoice',
            2 => 'facturacione_config_invoice_pos',
            3 => 'facturacione_config_invoice_borrador',
        ];

        $columnaPorTipo = [
            1 => 'fk_resolucion_factura',
            2 => 'fk_resolucion_pos',
            3 => 'fk_resolucion_borrador',
        ];

        $resultado = [];

        foreach ($tablasPorTipo as $tipoFac => $tabla) {
            $columna = $columnaPorTipo[$tipoFac];
            $ids = $cajas->pluck($columna)->filter(fn ($id) => (int) $id > 0)->unique()->values();

            $resultado[$tipoFac] = [];

            if ($ids->isEmpty()) {
                continue;
            }

            $columnas = ['rowid', 'prefijo', 'resolucion', 'tipo_factura', 'de', 'a', 'fecha_desde', 'fecha_hasta', 'estado'];

            if ($tipoFac === 3) {
                $columnas[] = 'info_qr';
            }

            $datosTecnicos = $this->datosTecnicosDian($tipoFac);
            $prefijosNcPorResolucion = $this->prefijosNotaCreditoPorResolucion($tipoFac);

            foreach (DB::connection('mysql')->table($tabla)->whereIn('rowid', $ids)->get($columnas) as $fila) {
                $resultado[$tipoFac][(int) $fila->rowid] = $this->formatearResolucion($fila, $prefijosNcPorResolucion) + $datosTecnicos;
            }
        }

        return $resultado;
    }

    /**
     * Prefijo de nota credito para cada numero de resolucion activo, segun el
     * tipo de documento (1=factura -> facturacione_config_creditnote,
     * 2=pos -> ..._pos, 3=borrador -> ..._borrador). La nota credito comparte
     * el mismo numero de resolucion DIAN que su factura, pero vive en su
     * propia tabla con su propio prefijo.
     *
     * @return array<string, array{prefijo: string, de: int}> [resolucion => datos]
     */
    private function prefijosNotaCreditoPorResolucion(int $tipoFac): array
    {
        $tablasCreditNotaPorTipo = [
            1 => 'facturacione_config_creditnote',
            2 => 'facturacione_config_creditnote_pos',
            3 => 'facturacione_config_creditnote_borrador',
        ];

        // tipo_factura (1=normal, 2=contingencia/provisional, etc.) es una
        // clasificacion independiente del canal (factura/pos/borrador, que ya
        // separa la tabla): una fila de NC solo sirve para una factura de su
        // MISMO tipo_factura, nunca de otro, aunque compartan resolucion (por
        // ejemplo la resolucion comodin "0"). Por eso se agrupa por tipo_factura
        // antes de indexar por resolucion.
        return DB::connection('mysql')->table($tablasCreditNotaPorTipo[$tipoFac])
            ->where('estado', 1)
            ->get(['resolucion', 'prefijo', 'de', 'tipo_factura'])
            ->groupBy('tipo_factura')
            ->map(fn ($filas) => $filas->keyBy('resolucion')
                ->map(fn ($fila) => ['prefijo' => $fila->prefijo, 'de' => (int) $fila->de])
                ->all())
            ->all();
    }

    private function formatearResolucion(object $fila, array $prefijosNcPorResolucion = []): array
    {
        // Solo se busca entre las NC del mismo tipo_factura que esta factura
        // (ver comentario en prefijosNotaCreditoPorResolucion). Dentro de ese
        // subconjunto, si no hay una NC con el mismo numero de resolucion
        // (caso normal), cae a la resolucion "0" como comodin generico - misma
        // logica sea que la resolucion de la factura venga asignada por usuario
        // (UsuariosService) o por caja (ConfiguracionService).
        $prefijosNcDeEsteTipo = $prefijosNcPorResolucion[(int) $fila->tipo_factura] ?? [];
        $notaCredito = $prefijosNcDeEsteTipo[$fila->resolucion] ?? $prefijosNcDeEsteTipo['0'] ?? null;
        $prefijoNc = $notaCredito['prefijo'] ?? null;

        return [
            'prefijo' => $fila->prefijo,
            'resolucion' => (string) $fila->resolucion,
            'rangoDesde' => (int) $fila->de,
            'rangoHasta' => (int) $fila->a,
            'fechaDesde' => $fila->fecha_desde,
            'fechaHasta' => $fila->fecha_hasta,
            'activa' => (int) $fila->estado === 1,
            'infoQr' => $fila->info_qr ?? ".",
            'ultimoConsecutivo' => $this->ultimoConsecutivo($fila->prefijo, (int) $fila->de),
            'prefijoNc' => $prefijoNc,
            'ultimaNotaCredito' => $prefijoNc !== null ? $this->ultimaNotaCredito($prefijoNc, $notaCredito['de']) : null,
        ];
    }

    /**
     * Parametros tecnicos DIAN (llx_const, mismos nombres que usa
     * sp_pos_dian_armar_json_factura_interna para calcular el CUFE/CUDE):
     * softwareId, pin y claveTecnica identifican el software ante la DIAN;
     * entorno indica produccion (1) o habilitacion/pruebas (2). tipo_fac=3
     * (borrador) no usa CUFE real (es un valor aleatorio de relleno), no
     * aplica.
     */
    private function datosTecnicosDian(int $tipoFac): array
    {
        if ($tipoFac === 1) {
            $valores = Configuracion::valores(['FACTURACIONE_MYPARAM1', 'FACTURACIONE_MYPARAM3', 'FACTURACIONE_MYPARAM4', 'FACTURACIONE_MYPARAM5']);

            return [
                'softwareId' => $valores['FACTURACIONE_MYPARAM1'] ?? null,
                'claveTecnica' => $valores['FACTURACIONE_MYPARAM3'] ?? null,
                'pin' => $valores['FACTURACIONE_MYPARAM4'] ?? null,
                'entorno' => $valores['FACTURACIONE_MYPARAM5'] ?? null,
            ];
        }

        if ($tipoFac === 2) {
            $valores = Configuracion::valores(['POS_IDSOFTWARE', 'POS_PIN', 'POS_ENTORNO']);
            $pin = $valores['POS_PIN'] ?? null;

            return [
                'softwareId' => $valores['POS_IDSOFTWARE'] ?? null,
                'claveTecnica' => $pin,
                'pin' => $pin,
                'entorno' => $valores['POS_ENTORNO'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Mismo calculo que sp_pos_numerar_factura para "v_ultimo": el numero mas
     * alto ya usado en llx_facture.ref con ese prefijo (ignorando borradores
     * "(PROVxxx)" y refs cuyo sufijo no es puramente numerico). Si todavia no
     * se ha facturado nada en el rango, devuelve rangoDesde-1 (el proximo
     * consecutivo a asignar seria rangoDesde), igual que hace el SP.
     */
    private function ultimoConsecutivo(string $prefijo, int $rangoDesde): int
    {
        $longitudPrefijo = strlen($prefijo);

        $fila = DB::connection('mysql')->selectOne('
            SELECT CAST(SUBSTRING(ref, ?) AS UNSIGNED) AS ultimo
            FROM llx_facture
            WHERE ref COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, \'%\')
              AND ref COLLATE utf8mb4_unicode_ci NOT LIKE \'%PROV%\'
              AND SUBSTRING(ref, ?, 1) REGEXP \'^[0-9]$\'
            ORDER BY CAST(SUBSTRING(ref, ?) AS UNSIGNED) DESC
            LIMIT 1
        ', [$longitudPrefijo + 1, $prefijo, $longitudPrefijo + 1, $longitudPrefijo + 1]);

        $ultimo = (int) ($fila->ultimo ?? 0);

        return $ultimo < $rangoDesde ? $rangoDesde - 1 : $ultimo;
    }

    /**
     * Ref de la ultima nota credito emitida (llx_facture.type=2) con ese
     * prefijo. Si todavia no se ha emitido ninguna, devuelve prefijo+rangoDesde
     * (la primera del rango de la resolucion), igual criterio que
     * ultimoConsecutivo() usa para "nada facturado todavia".
     */
    private function ultimaNotaCredito(string $prefijoNc, int $rangoDesde): string
    {
        $longitudPrefijo = strlen($prefijoNc);

        $fila = DB::connection('mysql')->selectOne('
            SELECT ref
            FROM llx_facture
            WHERE type = 2
              AND ref COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, \'%\')
              AND ref COLLATE utf8mb4_unicode_ci NOT LIKE \'%PROV%\'
              AND SUBSTRING(ref, ?, 1) REGEXP \'^[0-9]$\'
            ORDER BY CAST(SUBSTRING(ref, ?) AS UNSIGNED) DESC
            LIMIT 1
        ', [$prefijoNc, $longitudPrefijo + 1, $longitudPrefijo + 1]);

        return $fila->ref ?? $prefijoNc.$rangoDesde;
    }

    /**
     * Replica UpdateCheck(): version/build/changelog del POS de escritorio,
     * leidos de llx_const (constantes POS_UPDATE_* guardadas desde la pestaña
     * "Actualizacion Pos" del admin de Dolibarr) mas las URLs de descarga.
     */
    public function verificarActualizacion(): array
    {
        $valores = Configuracion::valores([
            'POS_UPDATE_VERSION',
            'POS_UPDATE_BUILD',
            'POS_UPDATE_CHANGELOG',
            'POS_UPDATE_FORZAR',
        ]);

        if (empty($valores['POS_UPDATE_VERSION']) || empty($valores['POS_UPDATE_BUILD'])) {
            abort(404, 'No hay una actualización configurada');
        }

        $baseUrl = rtrim(config('app.url'), '/');

        return [
            'version' => (string) $valores['POS_UPDATE_VERSION'],
            'build' => (int) $valores['POS_UPDATE_BUILD'],
            'changelog' => (string) ($valores['POS_UPDATE_CHANGELOG'] ?? ''),
            'forzar' => (bool) ($valores['POS_UPDATE_FORZAR'] ?? false),
            'windows_url' => $baseUrl.'/pos.exe',
            'linux_url' => $baseUrl.'/pos.deb',
        ];
    }

    public function truncarEventosWss(): void
    {
        EventoWss::truncate();
    }

    /**
     * Borra de llx_pos_auditoria los registros con fecha_evento de mas de
     * 15 dias - mantenimiento/limpieza, no tiene relacion con la auditoria
     * de cotizaciones (facture_extrafields.auditoria, eliminada esta misma
     * sesion) ni con LogErrorEndpointService::registrarAuditoria().
     *
     * pos_auditoria.entidad_id NO es facture.rowid - es el ref_local que
     * genera el cliente (mismo valor que facture_extrafields.ref_local),
     * asi que la relacion hacia la factura es por ese campo. Si esa factura
     * todavia esta en borrador (ref '(PROV...)', o sea es una cotizacion sin
     * confirmar) el registro de auditoria NO se borra aunque tenga mas de
     * 15 dias - se mantiene mientras la cotizacion siga viva. Delete con
     * JOIN (no Eloquent): borrar por relacion no es expresable con el query
     * builder de forma directa.
     */
    public function eliminarAuditoriaAntigua(): int
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        return DB::connection('mysql')->delete("
            DELETE pa FROM {$prefijo}pos_auditoria pa
            WHERE pa.fecha_evento < ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM {$prefijo}facture_extrafields fe
                  INNER JOIN {$prefijo}facture f ON f.rowid = fe.fk_object
                  WHERE fe.ref_local = pa.entidad_id
                    AND f.ref LIKE '(PROV%'
              )
        ", [now()->subDays(15)]);
    }

    /**
     * Ids de la categoria 'POS' y todas sus categorias hijas (recursivo),
     * usadas para filtrar las cuentas bancarias que cuentan como metodos de pago.
     */
    private function categoriasPos(): array
    {
        $ids = Categoria::query()->where('label', 'POS')->pluck('rowid')->all();

        $pendientes = $ids;

        while (! empty($pendientes)) {
            $hijas = Categoria::query()->whereIn('fk_parent', $pendientes)->pluck('rowid')->all();
            $pendientes = [];

            foreach ($hijas as $idHija) {
                if (! in_array($idHija, $ids, true)) {
                    $ids[] = $idHija;
                    $pendientes[] = $idHija;
                }
            }
        }

        return $ids;
    }

    /**
     * Tipos de domicilio activos con los datos del producto asociado
     * y, si existe, la plantilla de tirilla configurada para ese producto.
     */
    private function domicilios(): array
    {
        $tipos = DomicilioTipo::query()
            ->where('active', 1)
            ->with(['producto' => function ($q) {
                $q->select(['rowid', 'ref', 'label', 'price', 'price_ttc', 'tva_tx'])
                    ->with(['extrafields' => function ($q2) {
                        $q2->select(['rowid', 'fk_object', 'fk_plantilla_contable'])
                            ->with(['plantilla' => function ($q3) {
                                $q3->select(['rowid', 'nombre', 'label', 'config', 'columnas', 'variables']);
                            }]);
                    }]);
            }])
            ->orderBy('rowid')
            ->get(['rowid', 'tipo', 'descripcion', 'fk_product']);

        return $tipos->map(function ($tipoDomicilio) {
            $producto = $tipoDomicilio->producto;
            $extrafields = $producto?->extrafields;
            $plantilla = $extrafields?->plantilla;

            $entry = [
                'rowid' => (int) $tipoDomicilio->rowid,
                'tipo' => $tipoDomicilio->tipo,
                'descripcion' => $tipoDomicilio->descripcion,
                'idprod' => (int) $tipoDomicilio->fk_product,
                'referencia' => $producto?->ref,
                'label' => $producto?->label,
                'precio' => (float) ($producto?->price ?? 0),
                'precioTTC' => (float) ($producto?->price_ttc ?? 0),
                'iva' => (float) ($producto?->tva_tx ?? 0),
                'tieneIva' => ((float) ($producto?->tva_tx ?? 0)) > 0,
                'fk_plantilla' => (int) ($extrafields?->fk_plantilla_contable ?? 0),
            ];

            if ($plantilla) {
                $entry['plantilla'] = [
                    'rowid' => (int) $plantilla->rowid,
                    'nombre' => $plantilla->nombre,
                    'label' => $plantilla->label,
                    'config' => $plantilla->config,
                    'columnas' => (int) $plantilla->columnas,
                    'variables' => $plantilla->variables,
                ];
            }

            return $entry;
        })->values()->all();
    }

    /**
     * Reglas de redondeo del precio de venta (modulo precios). Misma forma que el payload
     * del evento WSS 'ReglaRedondeoCambiada' - replica preciosRedondeoCargarConfig() de
     * custom/precios/lib/redondeo.lib.php (cifras fuera de 1-3 = 2). No se envia
     * PRECIOS_REDONDEO_APLICAR_EN: el POS solo hace facturas de venta y 'factura' siempre aplica.
     */
    private function reglaRedondeo(array $valores): array
    {
        $cifras = (int) ($valores['PRECIOS_REDONDEO_CIFRAS'] ?? 2);
        if (! in_array($cifras, [1, 2, 3], true)) {
            $cifras = 2;
        }

        // La tabla la crea el modulo precios: si esa version aun no esta instalada, no hay reglas
        if (! Schema::hasTable((new ReglaRedondeo)->getTable())) {
            return ['activo' => false, 'cifras' => $cifras, 'base' => 10 ** $cifras, 'reglas' => []];
        }

        $reglas = ReglaRedondeo::query()
            ->where('entity', 1)
            ->orderBy('desde')
            ->orderBy('orden')
            ->get(['desde', 'hasta', 'termina_en'])
            ->map(fn ($r) => [
                'desde' => (int) $r->desde,
                'hasta' => (int) $r->hasta,
                'terminaEn' => (int) $r->termina_en,
            ])
            ->all();

        return [
            'activo' => ((int) ($valores['PRECIOS_REDONDEO_REGLAS_ACTIVO'] ?? 0)) === 1,
            'cifras' => $cifras,
            'base' => 10 ** $cifras,
            'reglas' => $reglas,
        ];
    }

    /**
     * Turnos (franjas horarias) activos, ordenados por hora de inicio.
     */
    private function turnos(): array
    {
        return Turno::query()
            ->where('activo', 1)
            ->orderBy('hora_inicio')
            ->get(['rowid', 'nombre', 'hora_inicio', 'hora_fin'])
            ->map(fn ($t) => [
                'rowid' => (int) $t->rowid,
                'nombre' => $t->nombre,
                'horaInicio' => $t->hora_inicio,
                'horaFin' => $t->hora_fin,
            ])
            ->all();
    }

}
