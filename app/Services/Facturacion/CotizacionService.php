<?php

namespace App\Services\Facturacion;

use App\Models\Factura;
use App\Models\FacturaDetalle;
use App\Models\FacturaExtrafields;
use App\Models\Usuario;
use App\Services\ServiceGlobal\ServiceGlobalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cotizacion (facture tipo borrador) contra los Stored Procedures
 * sp_pos_cotizacion_*. Cada metodo publico corresponde a un unico SP,
 * que ya resuelve toda la logica de negocio (Dolibarr/FactureIntegros
 * replicado en SQL); este servicio solo arma los parametros y expone
 * los OUT como arreglo.
 */
class CotizacionService
{
    private const TIPOS_FAC_VALIDOS = ['1', '2', '3'];

    public function __construct(
        private ServiceGlobalService $serviceGlobal,
    ) {}

    /**
     * Persiste facture_extrafields.bloqueo_usuario (fk_object=factura.rowid):
     * quien esta editando la cotizacion en este momento. idUsuario=0 pide
     * liberar (deja la columna en 0; extrafield Dolibarr int NOT NULL DEFAULT 0).
     *
     * - Bloquear (idUsuario>0): si ya esta bloqueada por OTRO usuario, se
     *   rechaza con 400 e info de quien la tiene. Si coincide con quien ya la
     *   tenia, se deja igual sin error (idempotente).
     * - Liberar (idUsuario=0): solo puede liberarla el mismo usuario que la
     *   bloqueo. Ese "quien pide liberar" es el usuario autenticado por
     *   DOLAPIKEY ($usuario), no viaja en el body porque idUsuario ya vale 0.
     *   Si la pide liberar alguien distinto al dueño del bloqueo, 400. Si ya
     *   estaba libre, no hay nada que validar.
     * - administrador=true en el body se salta esa validacion de dueño: libera
     *   la cotizacion sin importar quien la haya bloqueado.
     */
    public function bloqueoCotizacion(Request $request, Usuario $usuario): array
    {
        $idFactura = (int) $request->input('idFactura', 0);

        if ($idFactura <= 0) {
            abort(401, "El campo 'idFactura' es obligatorio.");
        }

        $ref = Factura::query()->where('rowid', $idFactura)->value('ref');

        if ($ref === null) {
            abort(401, 'No existe una factura con ese ID.');
        }

        if (!str_starts_with($ref, '(PROV')) {
            abort(401, 'Solo se puede bloquear/liberar cotizaciones (ref "(PROVxxx)").');
        }

        if ($request->input('idUsuario') === null) {
            abort(401, "El campo 'idUsuario' es obligatorio.");
        }

        $idUsuario = (int) $request->input('idUsuario');
        $administrador = $request->boolean('administrador');

        $extra = FacturaExtrafields::query()->where('fk_object', $idFactura)->first()
            ?? tap(new FacturaExtrafields(), fn ($e) => $e->fk_object = $idFactura);

        $bloqueadoPor = (int) ($extra->bloqueo_usuario ?? 0);

        if ($idUsuario > 0 && $bloqueadoPor > 0 && $bloqueadoPor !== $idUsuario) {
            $nombreBloqueo = $this->datosUsuarioBloqueo($bloqueadoPor)['nombre'] ?? 'otro usuario';

            abort(response()->json([
                'error' => sprintf('La cotización ya está siendo editada. Bloqueada por: %s.', $nombreBloqueo),
            ], 400));
        }

        if ($idUsuario === 0 && $bloqueadoPor > 0 && $bloqueadoPor !== (int) $usuario->rowid && ! $administrador) {
            $nombreBloqueo = $this->datosUsuarioBloqueo($bloqueadoPor)['nombre'] ?? 'otro usuario';

            abort(response()->json([
                'error' => sprintf('No puedes liberar la cotización porque no eres quien la bloqueó. Bloqueada por: %s.', $nombreBloqueo),
            ], 400));
        }

        $extra->bloqueo_usuario = $idUsuario > 0 ? $idUsuario : 0;
        $extra->save();

        return ['ok' => true];
    }

    /** @return array{idUsuario: int, nombre: ?string, login: ?string} */
    private function datosUsuarioBloqueo(int $idUsuario): array
    {
        $usuarioBloqueo = Usuario::query()->where('rowid', $idUsuario)->first(['rowid', 'login', 'firstname', 'lastname']);
        $nombre = $usuarioBloqueo
            ? (trim(($usuarioBloqueo->firstname ?? '').' '.($usuarioBloqueo->lastname ?? '')) ?: $usuarioBloqueo->login)
            : null;

        return [
            'idUsuario' => $idUsuario,
            'nombre' => $nombre,
            'login' => $usuarioBloqueo->login ?? null,
        ];
    }

    /**
     * Resuelve la ref (ej. "(PROV115446)") de la cotizacion provisional
     * dueña de ese ref_local, para que el cliente pueda confirmar que ya
     * quedo guardada del lado del servidor sin tener que traer el detalle
     * completo.
     */
    public function refPorRefLocal(Request $request): array
    {
        $refLocal = trim((string) $request->input('refLocal', ''));

        if ($refLocal === '') {
            abort(401, "El campo 'refLocal' es obligatorio.");
        }

        $idFactura = FacturaExtrafields::query()->where('ref_local', $refLocal)->value('fk_object');

        if ($idFactura === null) {
            abort(404, 'No existe una cotización con ese refLocal.');
        }

        $ref = Factura::query()->where('rowid', $idFactura)->value('ref');

        if ($ref === null || !str_starts_with($ref, '(PROV')) {
            abort(404, 'No existe una cotización provisional con ese refLocal.');
        }

        return ['idFactura' => (int) $idFactura, 'refLocal' => $refLocal, 'ref' => $ref];
    }

    /**
     * Consulta de solo lectura (sin SP, sin efectos secundarios) para que el
     * cliente valide el estado de una cotizacion justo antes de confirmarla:
     * si esta bloqueada por otro usuario, si ya fue pasada a pedido, o si ya
     * dejo de ser provisional (ref ya no tiene "PROV", alguien mas ya la
     * confirmo de verdad). Mismas reglas de bloqueo que ya usa
     * sp_pos_cotizacion_validar, replicadas aca en PHP sin llamar al SP.
     */
    public function validarEstado(Request $request): array
    {
        $idFactura = (int) $request->input('idFactura', 0);
        $idUsuario = (int) $request->input('idUsuario', 0);

        if ($idFactura <= 0) {
            abort(401, "El campo 'idFactura' es obligatorio.");
        }

        $factura = Factura::query()->where('rowid', $idFactura)->first(['rowid', 'ref']);

        if (! $factura) {
            abort(response()->json(['error' => 'Factura no encontrada'], 404));
        }

        $extra = FacturaExtrafields::query()->where('fk_object', $idFactura)->first(['bloqueo_usuario', 'en_pedido']);

        $bloqueoUsuario = (int) ($extra->bloqueo_usuario ?? 0);
        $bloqueoUsuarioLogin = $bloqueoUsuario > 0 ? $this->datosUsuarioBloqueo($bloqueoUsuario)['login'] : null;

        return [
            'bloqueada' => $bloqueoUsuario !== 0 && $bloqueoUsuario !== $idUsuario,
            'bloqueoUsuario' => $bloqueoUsuario,
            'bloqueoUsuarioLogin' => $bloqueoUsuarioLogin,
            'enPedido' => (bool) ($extra->en_pedido ?? false),
            'esProvisional' => str_contains($factura->ref, 'PROV'),
            'ref' => $factura->ref,
        ];
    }

    public function eliminarItem(Request $request): array
    {
        [$fkFacture, $refLocal] = $this->resolverFactura($request);

        $fkProduct = $request->input('fk_product');

        if (! $fkProduct) {
            abort(401, "El campo 'fk_product' es obligatorio.");
        }

        return $this->serviceGlobal->ejecutarProcedimientoConResultado(
            'sp_pos_cotizacion_eliminar_item',
            [$fkFacture, $refLocal, (int) $fkProduct]
        );
    }

    /**
     * Desde 2026-07-06 el SP ya no usa parametros OUT: status/message vienen
     * directo en la fila que devuelve el propio CALL (mismo patron que
     * sp_pos_crear_ticket_v1, ver ejecutarProcedimientoConResultado).
     */
    public function eliminarFactura(Request $request, Usuario $usuario): array
    {
        $fkFacture = (int) $request->input('fk_facture');

        try {
            $resultado = $this->serviceGlobal->ejecutarProcedimientoConResultado(
                'sp_pos_cotizacion_eliminar_factura',
                [$fkFacture]
            );
        } catch (\Throwable $e) {
            abort(response()->json(['status' => 0, 'message' => $e->getMessage()], 500));
        }

        if ((int) ($resultado['status'] ?? 0) !== 1) {
            abort(response()->json($resultado, 400));
        }

        return $resultado;
    }

    /**
     * Listado liviano de cotizaciones guardadas (borradores: ref (PROVxxx)
     * y fk_statut=0), para la grilla "Cotizaciones guardadas" - sin items,
     * sin metodo de pago, sin domicilio. Filtra opcionalmente por cliente
     * (fk_soc) o por una factura puntual (facid). Si se pasa facid y no
     * aparece entre las cotizaciones (fue eliminada), responde con el
     * formato "estado: eliminada" en vez de la lista normal.
     */
    public function listar(Request $request): array
    {
        $fkSoc = (int) $request->input('fk_soc', 0);
        $limit = (int) $request->input('limit', 0);
        $facid = (int) $request->input('facid', 0);

        // Un solo SELECT con JOIN (sociedad, extrafields y los usuarios de bloqueo/
        // modificacion) en vez de que Eloquent dispare una consulta aparte por cada
        // relacion (with 'sociedad'/'extrafields') mas otra consulta manual para
        // resolver los logins: mismo resultado, una sola ida a la base de datos.
        // sociedad es INNER JOIN porque facture.fk_soc es NOT NULL con foreign key
        // hacia societe (siempre existe). facture_extrafields y los user quedan como
        // LEFT JOIN: no hay foreign key que garantice que toda factura tenga fila
        // en extrafields ni que bloqueo_usuario/fk_user_modif apunten a un usuario
        // valido. fk_user_modif + tms (ambos nativos de Dolibarr, se actualizan solos
        // en cada UPDATE de facture) son la ultima modificacion y quien la hizo.
        $cotizaciones = DB::connection('mysql')
            ->table('facture as f')
            ->join('societe as s', 's.rowid', '=', 'f.fk_soc')
            ->leftJoin('facture_extrafields as fe', 'fe.fk_object', '=', 'f.rowid')
            ->leftJoin('user as ub', 'ub.rowid', '=', 'fe.bloqueo_usuario')
            ->leftJoin('user as um', 'um.rowid', '=', 'f.fk_user_modif')
            ->leftJoin('user as ua', 'ua.rowid', '=', 'f.fk_user_author')
            ->where('f.ref', 'like', '(PROV%')
            ->where('f.fk_statut', 0)
            ->where('f.type', 0)
            ->when($fkSoc !== 0, fn ($q) => $q->where('f.fk_soc', $fkSoc))
            ->when($facid !== 0, fn ($q) => $q->where('f.rowid', $facid))
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->orderByDesc('f.rowid')
            ->get([
                'f.rowid as rowid',
                'f.ref as ref',
                'f.datec as fecha',
                'f.total_ttc as total',
                'f.note_private as nota_privada',
                'f.tms as ultima_modificacion',
                's.nom as nombre_cliente',
                's.siren as documento_cliente',
                'fe.ref_local as ref_local',
                'fe.en_pedido as en_pedido',
                'fe.bloqueo_usuario as bloqueo_usuario',
                'ub.login as bloqueo_usuario_login',
                'um.login as modifico_login',
                'um.firstname as modifico_firstname',
                'um.lastname as modifico_lastname',
                'ua.login as autor_login',
                'ua.firstname as autor_firstname',
                'ua.lastname as autor_lastname',
            ]);

        if ($facid !== 0 && $cotizaciones->isEmpty()) {
            return [
                'factures' => [[
                    'id' => $facid,
                    'estado' => 'eliminada',
                    'eliminado_por' => null,
                    'fecha_eliminacion' => null,
                    'items' => [],
                ]],
            ];
        }

        $cotizacionesArr = $cotizaciones->map(fn ($factura) => [
            'rowid' => (int) $factura->rowid,
            'ref' => $factura->ref,
            'refLocal' => $factura->ref_local,
            'enPedido' => $factura->en_pedido,
            'bloqueoUsuario' => (int) ($factura->bloqueo_usuario ?? 0),
            'bloqueoUsuarioLogin' => $factura->bloqueo_usuario_login,
            'nombreCliente' => $factura->nombre_cliente,
            'documentoCliente' => $factura->documento_cliente,
            'notaPrivada' => $factura->nota_privada ?? '',
            'fecha' => $factura->fecha,
            'total' => (int) $factura->total,
            'ultimaModificacion' => $factura->ultima_modificacion,
            // fk_user_modif solo queda seteado si la cotizacion ya se edito al
            // menos una vez (sp_pos_crear_ticket_v1 lo setea en cada guardado);
            // en una cotizacion recien creada y nunca editada, cae al autor.
            'usuarioModifico' => $factura->modifico_login
                ? (trim(($factura->modifico_firstname ?? '').' '.($factura->modifico_lastname ?? '')) ?: $factura->modifico_login)
                : ($factura->autor_login
                    ? (trim(($factura->autor_firstname ?? '').' '.($factura->autor_lastname ?? '')) ?: $factura->autor_login)
                    : null),
        ])->values()->all();

        return [
            'rows' => count($cotizacionesArr),
            'cotizaciones' => $cotizacionesArr,
        ];
    }

    /**
     * Detalle (items) de una cotizacion guardada, para la pantalla de
     * "detalle del pre-pedido".
     */
    public function detalle(Request $request): array
    {
        $idFactura = (int) $request->input('idFacture', 0);

        if ($idFactura <= 0) {
            abort(401, "El campo 'idFacture' es obligatorio.");
        }

        $factura = Factura::query()
            ->where('rowid', $idFactura)
            ->select(['rowid', 'ref', 'datec', 'total_ttc', 'fk_soc', 'note_private'])
            ->with([
                'sociedad' => fn ($q) => $q->select('rowid', 'nom', 'siren'),
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'ref_local'),
            ])
            ->first();

        if (! $factura) {
            abort(401, 'No existe una cotización con ese ID.');
        }

        $items = FacturaDetalle::query()
            ->where('fk_facture', $idFactura)
            ->select(['rowid', 'fk_product', 'qty', 'subprice', 'subprice_ttc', 'total_ttc', 'total_tva', 'remise_percent', 'tva_tx', 'fecha_creacion'])
            ->with([
                'producto' => fn ($q) => $q->select('rowid', 'ref', 'label'),
                'producto.precios' => fn ($q) => $q->where('price_level', 1)
                    ->select('rowid', 'fk_product', 'date_price', 'price', 'price_ttc', 'tva_tx', 'fk_user_author')
                    ->orderBy('date_price', 'desc'),
                'producto.precios.autor' => fn ($q) => $q->select('rowid', 'firstname', 'lastname', 'login'),
            ])
            ->orderBy('rowid')
            ->get()
            ->map(function ($linea) {
                $precioLista1 = $linea->producto?->precios?->first();

                return [
                    'idLinea' => (int) $linea->rowid,
                    'fk_product' => (int) $linea->fk_product,
                    'referencia' => $linea->producto?->ref ?? '',
                    'articulo' => $linea->producto?->label ?? '',
                    'cantidad' => (float) $linea->qty,
                    // Unitarios (por 1 unidad, sin multiplicar por cantidad):
                    // precio = subprice (unitario sin IVA). iva = total_tva / qty
                    // (el IVA REAL guardado en la linea, dividido entre cantidad)
                    // en vez de subprice_ttc - subprice: con lineas estaticas
                    // (ver sp_pos_crear_lineas_factura_bulk) esos dos valores son
                    // los que mando el cliente, y su resta no necesariamente
                    // coincide con el IVA real de la linea completa (redondeo,
                    // descuentos aplicados de otra forma) - total_tva SI es el
                    // valor que efectivamente se guardo y se uso para el total.
                    'precio' => $linea->subprice,
                    'iva' => $linea->qty > 0 ? ($linea->total_tva / $linea->qty) : 0,
                    'porIva' => (float) $linea->tva_tx,
                    // total = linea completa (cantidad x precio, con IVA incluido).
                    'total' => $linea->total_ttc,
                    // Descuento aplicado en la linea (%).
                    'descuento' => $linea->remise_percent,
                    // Fecha en que se agrego la linea (facturedet.fecha_creacion),
                    // mismo formato que 'fecha' del encabezado (Y-m-d H:i:s).
                    'fechaCreacion' => $linea->fecha_creacion,
                    // Precio vigente del producto en la lista de precio 1 (independiente
                    // de lo que quedo grabado en la linea al momento de cotizar).
                    'precioActual' => $precioLista1 ? $precioLista1->price : null,
                    'ivaActual' => $precioLista1 ? ($precioLista1->price_ttc - $precioLista1->price) : null,
                    'porIvaActual' => $precioLista1 ?  $precioLista1->tva_tx : null,
                    'precioActualConIva' => $precioLista1 ? $precioLista1->price_ttc : null,
                    'fechaPrecioActual' => $precioLista1?->date_price
                        ? (new \DateTime($precioLista1->date_price))->format('d/m/Y H:i')
                        : null,
                    'usuarioPrecioActual' => $precioLista1?->autor
                        ? (trim($precioLista1->autor->firstname.' '.$precioLista1->autor->lastname) ?: $precioLista1->autor->login)
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'rowid' => (int) $factura->rowid,
            'ref' => $factura->ref,
            'refLocal' => $factura->extrafields?->ref_local,
            'fecha' => $factura->datec,
            'nombreCliente' => $factura->sociedad?->nom,
            'documentoCliente' => $factura->sociedad?->siren,
            'notaPrivada' => $factura->note_private ?? '',
            'totalProductos' => count($items),
            'total' => (int) $factura->total_ttc,
            'items' => $items,
        ];
    }

    /**
     * Historial de auditoria de una cotizacion. La columna
     * facture_extrafields.auditoria ya no se consulta ni se guarda, asi que
     * esto siempre responde vacio - se deja el endpoint (200, auditoria:[])
     * para no romper al cliente, que ya interpreta cualquier status != 200
     * como "sin conexión".
     */
    public function auditoria(Request $request): array
    {
        $facid = (int) $request->input('facid', 0);

        if ($facid <= 0) {
            abort(401, "El campo 'facid' es obligatorio.");
        }

        if (! Factura::query()->where('rowid', $facid)->exists()) {
            abort(response()->json(['auditoria' => []], 404));
        }

        return ['auditoria' => []];
    }

    /**
     * fk_facture/ref_local son intercambiables en varios SPs de cotizacion:
     * si no llega fk_facture (o llega 0), se exige ref_local para que el
     * SP resuelva la factura por esa via.
     *
     * @return array{0: int, 1: ?string}
     */
    private function resolverFactura(Request $request): array
    {
        $fkFacture = (int) $request->input('fk_facture', 0);
        $refLocal = $request->filled('ref_local') ? (string) $request->input('ref_local') : null;

        if ($fkFacture === 0 && ! $refLocal) {
            abort(401, "Debe indicar 'fk_facture' o 'ref_local'.");
        }

        return [$fkFacture, $refLocal];
    }
}
