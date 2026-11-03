<?php

namespace App\Services\Producto;

use App\Models\PrecioCliente;
use App\Models\Producto;
use App\Models\ProductoCliente;
use App\Models\PromocionProductoRegalo;
use App\Models\UnidadMedida;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductoService
{
    private const TIPOS_IMPUESTO_EXTRA = [
        1 => 'IBUA',
        2 => 'ICL',
        3 => 'ADV',
        4 => 'ICUI',
        5 => 'Al Consumo',
        6 => 'Otros',
    ];

    public function __construct(private PromocionService $promocionService)
    {
    }

    public function listarApp(Request $request)
    {
        $byRefODesc     = trim((string) $request->input('byRefODesc', ''));
        $ref            = trim((string) $request->input('ref', ''));
        $codigoBarras   = trim((string) $request->input('codigoBarras', ''));
        $byCodigoBarras = trim((string) $request->input('byCodigoBarras', ''));
        $puntos         = (int) $request->input('puntos', 0);
        $clienteId      = (int) $request->input('clienteId', 0);
        $ventaSinStock  = $request->boolean('ventaSinStock');

        $limite = $request->filled('limit') ? max(1, (int) $request->input('limit')) : null;

        $nivelCliente = null;
        if ($clienteId !== 0) {
            $nivel = PrecioCliente::query()
                ->where('fk_soc', $clienteId)
                ->orderByDesc('rowid')
                ->value('price_level');

            if ((int) $nivel > 0) {
                $nivelCliente = (int) $nivel;
            }
        }

        $rows = $this->buscarProductos($byCodigoBarras, $byRefODesc, $ref, $codigoBarras, $limite);

        // Ultimo recurso: si el codigo de barras no coincide con ningun producto
        // (ref / product_barcode), se busca como codigo de barras de un pack
        // (product_packs.ref).
        if (empty($rows) && ($byCodigoBarras !== '' || ($ref === '' && $codigoBarras !== ''))) {
            $rows = $this->buscarProductos($byCodigoBarras, $byRefODesc, $ref, $codigoBarras, $limite, true);
        }

        if ($byCodigoBarras !== '' && count($rows) > 1) {
            return [];
        }

        if (empty($rows)) {
            return [];
        }

        $host = $request->getHost();

        $idsProducto = array_column($rows, 'rowid');

        $promoMap = $this->promocionService->resolverParaProductos(
            $idsProducto,
            $clienteId ?: null
        );

        [$regalosPorPp, $obsequioProductos] = $this->cargarRegalosTipo3($promoMap, $nivelCliente);
        //$imagenesPorProducto = $this->imagenesPorProducto($idsProducto);

        $unidadesMedida = UnidadMedida::query()->get(['unit_type', 'scale', 'short_label']);

        $objProductos = [];

        foreach ($rows as $row) {
            $precioOriginal = $row->price;

            $descuento = 0;
            $descuentoRaw = $row->descuento_raw;
            if (!is_null($descuentoRaw)) {
                if (str_contains($descuentoRaw, '%')) {
                    $descuentoPar = (int) trim(str_replace('%', '', $descuentoRaw));
                    if ($descuentoPar > 100) {
                        $descuentoPar = 100;
                    }
                    $descuento = $precioOriginal * $descuentoPar / 100;
                } else {
                    $descuento = $descuentoRaw;
                }
            }

            $ivaPorcentaje = (int) $row->tva_tx;
            $ivaFinal = ($precioOriginal * $ivaPorcentaje) / 100;
            $impuestos = $precioOriginal * (int) $row->localtax1_tx / 100;

            if ($ivaPorcentaje > 0 && $descuento > 0) {
                $ivaDescuento = ($precioOriginal - $descuento) * $ivaPorcentaje / 100;
                $descuento += $ivaDescuento == 0 ? ceil($ivaFinal) : $ivaDescuento;
            }

            $totalP = (int) $row->price_ttc + ceil($impuestos);

            $precio = $puntos === 1 ? (int) ($row->cantidad_puntos ?? 0) : $precioOriginal;

            if ($precio <= 0 && (int) $row->fk_product_type !== 1) {
                continue;
            }

            $impuestosExtraRaw = $this->decodificarJson($row->impuestos_json);
            $impuestosExtras = collect($impuestosExtraRaw)->map(fn ($imp) => [
                'tipo' => self::TIPOS_IMPUESTO_EXTRA[$imp['fk_type']] ?? 'Otros',
                'valor' => (int) $imp['impuesto'],
            ])->values()->all();

            // JSON_ARRAYAGG no garantiza el orden de la subconsulta que agrega (el SQL
            // ya dedupe 1 fila por nivel via ROW_NUMBER, pero el orden entre niveles hay
            // que fijarlo aqui): el nivel actualizado mas recientemente (mayor rowid)
            // debe quedar primero, porque $nivelesProducto->first() lo usa como default.
            $preciosRaw = collect($this->decodificarJson($row->precios_json))->sortByDesc('rowid')->values()->all();
            $nivelesUnicos = collect($preciosRaw)->unique('price_level')->values();
            $nivelesProducto = $nivelCliente
                ? $nivelesUnicos->where('price_level', $nivelCliente)->values()
                : $nivelesUnicos;

            if ($nivelesProducto->isNotEmpty()) {
                // Sin cliente (o cliente sin nivel asignado): el precio base
                // siempre sale del nivel 1 si el producto lo tiene configurado,
                // no del nivel actualizado mas recientemente.
                $primerNivel = $nivelCliente
                    ? $nivelesProducto->first()
                    : ($nivelesProducto->firstWhere('price_level', 1) ?? $nivelesProducto->first());
                $totalP = (int) $primerNivel['price_ttc'];
                $impuestos =  $primerNivel['price'] * $primerNivel['tva_tx'] / 100;
                $ivaFinal =  $primerNivel['price_ttc'] -  $primerNivel['price'];
                $ivaPorcentaje =  $primerNivel['tva_tx'];
                $precio = $primerNivel['price'];
            }

            $nivelesSalida = $nivelesProducto->count() > 1
                ? $nivelesProducto->map(fn ($n) => [
                    'nivel' => (int) $n['price_level'],
                    'precioSinIva' =>  $n['price'],
                    'precioConIva' => $n['price_ttc'],
                    'iva' =>  $n['tva_tx'],
                ])->values()->all()
                : [];

            $promo = $promoMap[$row->rowid] ?? null;
            $precioTotalSinPromo = $totalP;
            if ($promo && (int) $promo['discount_type'] === 1) {
                $totalP = $this->promocionService->calcularPrecioConPromocion($totalP, $promo);
            }

            $barcodesArr = $this->decodificarJson($row->barcodes_json);
            //$imagenArr = $imagenesPorProducto[$row->rowid] ?? [];
            $categoriasArr = $this->decodificarJson($row->categorias_json);
            // Mismo problema de orden que precios_json: se fija aqui, no se confia en JSON_ARRAYAGG.
            $packsArr = collect($this->decodificarJson($row->packs_json))->sortBy('unidades')->values()->all();
            $proveedorArr = $this->decodificarJson($row->proveedor_json);
            $stockArr = $this->decodificarStock($row->stock_json);

            $datosProductoActual = [
                'rowid' => (int) $row->rowid,
                'ref' => (string) $row->ref,
                'label' => (string) $row->label,
                'description' => (string) $row->description,
                'price' => (float) $row->price,
                'price_ttc' => (float) $row->price_ttc,
                'price_min' => (float) $row->price_min,
                'tva_tx' => (float) $row->tva_tx,
                'localtax1_tx' => (float) $row->localtax1_tx,
                'stock' => $stockArr,
                'venta_gramos' => (bool) $row->venta_gramos,
                'venta_precio' => (bool) $row->venta_precio,
                'venta_cantidad' => (bool) $row->venta_cantidad,
                'fk_plantilla_contable' => (int) ($row->fk_plantilla_contable ?? 0),
                'barcodes' => $barcodesArr,
                'impuestosExtra' => $impuestosExtraRaw,
                'precios' => $preciosRaw,
                'imagen_principal' => (string) ($row->imagen_principal ?? ''),
                /*'imagen' => $imagenArr,*/
            ];

            $camposPromo = $this->resolverCamposPromo($promo, $nivelCliente, $datosProductoActual, $obsequioProductos, $regalosPorPp, $host);

            $codigoBarrasSalida = implode('|', $barcodesArr);

            $precioVentaPum = $row->precio_venta_pum ?? $row->price_ttc;
            $pum = $this->calcularPum($row, $unidadesMedida, (float) $precioVentaPum);

            $urlImagen = '';
            /*if (!empty($imagenArr['filename'])) {
                $nombreArchivo = str_replace(' ', '+', $imagenArr['filename']);
                $partes = explode('/', $imagenArr['filepath'] ?? '');
                $carpeta = str_replace(' ', '+', $partes[1] ?? '');
                $urlImagen = "https://{$host}/viewimage.php?modulepart=product&file=/{$carpeta}/{$nombreArchivo}&publictakepos=1";
            }*/
            if($row->imagen_principal!=""){
                $resultado = preg_replace('#^[^/]+#', '', (string) $row->imagen_principal);
                $urlImagen = "https://{$host}/viewimage.php?modulepart=product&file={$resultado}&publictakepos=1";
            }

            $objProductos[] = [
                'rowid' => (int) $row->rowid,
                'ref' => (string) $row->ref,
                'label' => (string) $row->label,
                'description' => html_entity_decode(ucfirst(strtolower((string) $row->description))),
                'precio' => $precio,
                'iva' =>  ceil($ivaFinal),
                'porcentajeIva' => $ivaPorcentaje,
                'impuestos' => (int) ceil($impuestos),
                'precio_total' => $totalP,
                'precio_total_sin_promo' => $precioTotalSinPromo,
                'precio_minimo' =>  $row->price_min_ttc,
                'descuento' => $camposPromo['descuento'] > 0 ? $camposPromo['descuento'] : (int) $descuento,
                'cantidad_descuento' => $camposPromo['cantidadDescuento'],
                'urlImagen' => $urlImagen,
                'ventaGramos' => (bool) $row->venta_gramos,
                'stock' => $stockArr,
                'codigo_barras' => $codigoBarrasSalida,
                'lotes' => [],
                'ventaPrecio' => (bool) $row->venta_precio,
                'ventaCantidad' => (bool) $row->venta_cantidad,
                'impuestosExtras' => $impuestosExtras,
                'nivelesPrecios' => $nivelesSalida,
                'obsequio' => $camposPromo['obsequio'],
                'descuentoGlobal' => (int) ($row->descuento_global ?? 0),
                'tipoProducto' => (int) $row->fk_product_type,
                'packs' => collect($packsArr)->map(fn ($p) => [
                    'ref' => $p['ref'] ?? null,
                    'cantidad' => (int) $p['unidades'],
                    'precio' => (float) $p['precio'],
                    'minimo' => (float) $p['precio_minimo'],
                ])->values()->all(),
                'fk_plantilla' => (int) ($row->fk_plantilla_contable ?? 0),
                'resumen_cierre_caja' => is_null($row->resumen_cierre_caja) ? null : (int) $row->resumen_cierre_caja,
                // Redimible por puntos de fidelizacion (producto_puntos = 1, ver
                // api_products.lib.php) y el costo en puntos (cantidad_puntos) -
                // el mismo dato que ya se usa para reemplazar 'precio' cuando la
                // busqueda viene en modo puntos=1, ahora expuesto siempre.
                'redimible' => (int) ($row->producto_puntos ?? 0) === 1,
                'puntos' => (int) ($row->cantidad_puntos ?? 0),
                'idcategorias' => collect($categoriasArr)->map(fn ($id) => (int) $id)->values()->all(),
                'precio_compra' => (float) ($row->cost_price ?? 0),
                'proveedor' => [
                    'id' => (int) ($proveedorArr['fk_soc'] ?? 0),
                    'nombre' => (string) ($proveedorArr['nombre'] ?? ''),
                ],
                'estadoVenta' => (int) $row->tosell,
                'estadoCompra' => (int) $row->tobuy,
                'almacen' => (int) $row->fk_default_warehouse,
                'pum' => $pum,
                'baseUvt' => (float) ($row->base_retencion_venta ?? 0),
                'retencionUvt' => (float) ($row->tarifa_retencion_venta ?? 0),
            ];
        }

        return $objProductos;
    }

    /**
     * Consulta unica (JOIN + subconsultas JSON_ARRAYAGG/JSON_OBJECT) que trae el
     * producto y todas sus relaciones (barcodes, packs, precios por nivel,
     * impuestos extra, categorias, proveedor, imagen) en una sola fila,
     * evitando las N consultas por relacion que hacia el with() de Eloquent.
     *
     * Los filtros replican exactamente los que tenia el query builder:
     * byCodigoBarras (ref o barcode exacto) tiene prioridad sobre byRefODesc
     * (ref o label parcial); igual entre ref (exacto) y codigoBarras (exacto,
     * antes era un where('barcodes', ...) roto porque 'barcodes' no es una
     * columna de `product` sino una relacion - aqui se corrige para que
     * realmente filtre por codigo de barras, que es la intencion evidente).
     *
     * El stock ('stock_json') es un JSON array con la existencia por almacen
     * (idAlmacen/cantidad) tomado de product_stock, como subconsulta
     * correlacionada dentro de esta misma consulta - no una consulta aparte.
     */
    private function buscarProductos(string $byCodigoBarras, string $byRefODesc, string $ref, string $codigoBarras, ?int $limite, bool $porPack = false): array
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $condiciones = ['pe.producto_publicado = 1'];
        $bindings = [];

        if ($byCodigoBarras !== '' && $porPack) {
            $condiciones[] = "EXISTS (SELECT 1 FROM {$prefijo}product_packs pk2 WHERE pk2.fk_product = p.rowid AND pk2.ref = ?)";
            $bindings[] = $byCodigoBarras;
        } elseif ($byCodigoBarras !== '') {
            $condiciones[] = "(p.ref = ? OR EXISTS (SELECT 1 FROM {$prefijo}product_barcode pb2 WHERE pb2.fk_product = p.rowid AND pb2.barcode = ?))";
            $bindings[] = $byCodigoBarras;
            $bindings[] = $byCodigoBarras;
        } elseif ($byRefODesc !== '') {
            $condiciones[] = '(p.ref LIKE ? OR p.label LIKE ?)';
            $bindings[] = "%{$byRefODesc}%";
            $bindings[] = "%{$byRefODesc}%";
        }

        if ($ref !== '') {
            $condiciones[] = 'p.ref = ?';
            $bindings[] = $ref;
        } elseif ($codigoBarras !== '' && $porPack) {
            $condiciones[] = "EXISTS (SELECT 1 FROM {$prefijo}product_packs pk3 WHERE pk3.fk_product = p.rowid AND pk3.ref = ?)";
            $bindings[] = $codigoBarras;
        } elseif ($codigoBarras !== '') {
            $condiciones[] = "EXISTS (SELECT 1 FROM {$prefijo}product_barcode pb3 WHERE pb3.fk_product = p.rowid AND pb3.barcode = ?)";
            $bindings[] = $codigoBarras;
        }

        // Fase 1: solo resuelve que rowid entran, barato (sin las subconsultas de
        // enriquecimiento). `product_extrafields.producto_publicado` no tiene indice
        // y casi todo el catalogo esta publicado, asi que este WHERE ya fuerza un
        // scan + filesort de toda la tabla igual que hacia whereHas() en Eloquent -
        // pero si el SELECT de enriquecimiento (9 subconsultas) fuera parte de esta
        // misma consulta, MySQL las evalua para TODO ese resultado antes de aplicar
        // el LIMIT (visto con EXPLAIN: "Using temporary; Using filesort"), no solo
        // para las filas que se van a devolver. Separarlo en dos fases es lo que deja
        // esas 9 subconsultas corriendo solo sobre los rowid ya filtrados+limitados.
        $sqlIds = "
            SELECT p.rowid
            FROM {$prefijo}product p
            INNER JOIN {$prefijo}product_extrafields pe ON pe.fk_object = p.rowid
            WHERE ".implode(' AND ', $condiciones)."
            ORDER BY p.rowid ASC
        ".($limite !== null ? 'LIMIT ?' : '');

        if ($limite !== null) {
            $bindings[] = $limite;
        }

        $rowids = array_column(DB::connection('mysql')->select($sqlIds, $bindings), 'rowid');

        if (empty($rowids)) {
            return [];
        }

        // Fase 2: enriquecimiento, ya acotado por PRIMARY KEY IN (...) sobre el
        // puñado de productos de la fase 1 (no contra todo el catalogo publicado).
        $marcadoresIds = implode(', ', array_fill(0, count($rowids), '?'));

        $sql = "
            SELECT
                p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.price_min, p.price_min_ttc,
                p.tva_tx, p.localtax1_tx, p.fk_product_type, p.cost_price,
                p.tosell, p.tobuy, p.fk_default_warehouse,
                p.weight, p.weight_units, p.length, p.length_units, p.width, p.width_units,
                p.height, p.height_units, p.surface, p.surface_units, p.volume, p.volume_units,
                pe.Descuento AS descuento_raw, pe.cantidad_puntos, pe.producto_puntos, pe.venta_gramos, pe.venta_precio,
                pe.venta_cantidad, pe.descuento_global, pe.fk_plantilla_contable, pe.imagen_principal,
                pe.resumen_cierre_caja, pe.base_retencion_venta, pe.tarifa_retencion_venta,
                (SELECT ipp.precio_venta FROM {$prefijo}integros_productos_precios ipp
                    WHERE ipp.id_producto = p.rowid AND ipp.estado != 4
                    ORDER BY ipp.rowid DESC LIMIT 1) AS precio_venta_pum,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('idAlmacen', ps.fk_entrepot, 'almacen', e.ref, 'cantidad', ps.reel))
                    FROM {$prefijo}product_stock ps
                    LEFT JOIN {$prefijo}entrepot e ON e.rowid = ps.fk_entrepot
                    WHERE ps.fk_product = p.rowid) AS stock_json,
                (SELECT JSON_ARRAYAGG(barcode) FROM {$prefijo}product_barcode WHERE fk_product = p.rowid) AS barcodes_json,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('ref', ref, 'unidades', unidades, 'precio', precio, 'precio_minimo', precio_minimo))
                    FROM {$prefijo}product_packs WHERE fk_product = p.rowid ORDER BY unidades) AS packs_json,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('fk_type', fk_type, 'impuesto', impuesto))
                    FROM {$prefijo}integros_product_impuestos_extra WHERE fk_product = p.rowid AND estado = 1) AS impuestos_json,
                (SELECT JSON_ARRAYAGG(fk_categorie) FROM {$prefijo}categorie_product WHERE fk_product = p.rowid) AS categorias_json,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('price_level', price_level, 'price', price, 'price_ttc', price_ttc, 'tva_tx', tva_tx, 'rowid', rowid))
                    FROM (
                        SELECT price_level, price, price_ttc, tva_tx, rowid,
                               ROW_NUMBER() OVER (PARTITION BY price_level ORDER BY rowid DESC) AS rn
                        FROM {$prefijo}product_price
                        WHERE fk_product = p.rowid AND price_level > 0 AND price > 0
                    ) t WHERE rn = 1) AS precios_json,
                (SELECT JSON_OBJECT('fk_soc', pfp.fk_soc, 'nombre', s.nom)
                    FROM {$prefijo}product_fournisseur_price pfp
                    LEFT JOIN {$prefijo}societe s ON s.rowid = pfp.fk_soc
                    WHERE pfp.fk_product = p.rowid ORDER BY pfp.rowid ASC LIMIT 1) AS proveedor_json
            FROM {$prefijo}product p
            INNER JOIN {$prefijo}product_extrafields pe ON pe.fk_object = p.rowid
            WHERE p.rowid IN ({$marcadoresIds})
            ORDER BY p.rowid ASC
        ";

        return DB::connection('mysql')->select($sql, $rowids);
    }

    /**
     * `ecm_files` (imagen principal) no tiene indice en (src_object_type,
     * src_object_id): una subconsulta correlacionada por fila hace un table
     * scan completo por cada producto (~4s para 300 productos). Se trae en un
     * unico query con WHERE...IN, igual que lo hacia el with() de Eloquent,
     * y se resuelve el "primero por rowid" en PHP.
     *
     * @return array<int, array{filename: ?string, filepath: ?string}>
     */
    private function imagenesPorProducto(array $rowids): array
    {
        if (empty($rowids)) {
            return [];
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();
        $marcadores = implode(', ', array_fill(0, count($rowids), '?'));

        $filas = DB::connection('mysql')->select("
            SELECT src_object_id, filename, filepath
            FROM {$prefijo}ecm_files
            WHERE src_object_type = 'product' AND src_object_id IN ({$marcadores})
            ORDER BY rowid ASC
        ", $rowids);

        $porProducto = [];
        foreach ($filas as $fila) {
            $porProducto[$fila->src_object_id] ??= ['filename' => $fila->filename, 'filepath' => $fila->filepath];
        }

        return $porProducto;
    }

    /**
     * Para las promos tipo 3 (compra X lleva Y) trae por lote los productos regalo
     * (tabla integros_promociones_products_regalos) y sus datos de producto, sin
     * hacer ninguna consulta dentro del foreach principal.
     */
    private function cargarRegalosTipo3(array $promoMap, ?int $nivelCliente): array
    {
        $ppRowidsTipo3 = collect($promoMap)
            ->filter(fn ($p) => $p && (int) $p['discount_type'] === 3)
            ->pluck('pp_rowid')
            ->unique()
            ->values()
            ->all();

        if (empty($ppRowidsTipo3)) {
            return [collect(), collect()];
        }

        $regalosPorPp = PromocionProductoRegalo::query()
            ->whereIn('fk_pp', $ppRowidsTipo3)
            ->select('fk_pp', 'fk_product')
            ->get()
            ->groupBy('fk_pp');

        $idsObsequio = $regalosPorPp->flatten(1)->pluck('fk_product')->unique()->values()->all();

        if (empty($idsObsequio)) {
            return [$regalosPorPp, collect()];
        }

        $prefijo = (new Producto)->getConnection()->getTablePrefix();

        $obsequioProductos = Producto::query()
            ->whereIn('rowid', $idsObsequio)
            ->select(['rowid', 'ref', 'label', 'description', 'price', 'price_ttc', 'price_min', 'tva_tx', 'localtax1_tx'])
            ->selectRaw("
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('idAlmacen', ps.fk_entrepot, 'almacen', e.ref, 'cantidad', ps.reel))
                    FROM {$prefijo}product_stock ps
                    LEFT JOIN {$prefijo}entrepot e ON e.rowid = ps.fk_entrepot
                    WHERE ps.fk_product = {$prefijo}product.rowid) AS stock_json
            ")
            ->with([
                'extrafields' => fn ($q) => $q->select('fk_object', 'venta_gramos', 'venta_precio', 'venta_cantidad', 'fk_plantilla_contable', 'imagen_principal'),
                'barcodes' => fn ($q) => $q->select('fk_product', 'barcode'),
                'impuestosExtra' => fn ($q) => $q->where('estado', 1)->select('fk_product', 'fk_type', 'impuesto'),
                'precios' => fn ($q) => $q->select('fk_product', 'price_level', 'price', 'price_ttc', 'tva_tx')
                    ->where('price_level', '>', 0)
                    ->where('price', '>', 0)
                    ->orderByDesc('rowid'),
                'imagenPrincipal' => fn ($q) => $q->select('src_object_id', 'filename', 'filepath'),
            ])
            ->get()
            ->map(fn ($producto) => $this->normalizarProductoEloquent($producto))
            ->keyBy('rowid');

        return [$regalosPorPp, $obsequioProductos];
    }

    /**
     * Convierte un Producto Eloquent (con sus relaciones ya cargadas) al mismo
     * arreglo plano que arma buscarProductos() para las filas por SQL directo,
     * asi formatearObsequio() puede recibir indistintamente el producto actual
     * del listado (SQL directo) o un producto regalo de promocion (Eloquent).
     */
    private function normalizarProductoEloquent(Producto $producto): array
    {
        $extra = $producto->extrafields;
        $imagen = $producto->imagenPrincipal;

        return [
            'rowid' => (int) $producto->rowid,
            'ref' => (string) $producto->ref,
            'label' => (string) $producto->label,
            'description' => (string) $producto->description,
            'price' => (float) $producto->price,
            'price_ttc' => (float) $producto->price_ttc,
            'price_min' => (float) $producto->price_min,
            'tva_tx' => (float) $producto->tva_tx,
            'localtax1_tx' => (float) $producto->localtax1_tx,
            'stock' => $this->decodificarStock($producto->stock_json),
            'venta_gramos' => (bool) ($extra?->venta_gramos ?? false),
            'venta_precio' => (bool) ($extra?->venta_precio ?? false),
            'venta_cantidad' => (bool) ($extra?->venta_cantidad ?? false),
            'fk_plantilla_contable' => (int) ($extra?->fk_plantilla_contable ?? 0),
            'imagen_principal' => (string) ($extra?->imagen_principal ?? ''),
            'barcodes' => $producto->barcodes->pluck('barcode')->values()->all(),
            'impuestosExtra' => $producto->impuestosExtra->map(fn ($i) => [
                'fk_type' => (int) $i->fk_type,
                'impuesto' => (float) $i->impuesto,
            ])->values()->all(),
            'precios' => $producto->precios->map(fn ($p) => [
                'price_level' => (int) $p->price_level,
                'price' => (float) $p->price,
                'price_ttc' => (float) $p->price_ttc,
                'tva_tx' => (float) $p->tva_tx,
            ])->values()->all(),
            'imagen' => $imagen ? ['filename' => $imagen->filename, 'filepath' => $imagen->filepath] : [],
        ];
    }

    /**
     * Puerto de Promociones::resolverCamposPromo. El parámetro $precioOriginal de la
     * versión legacy no se usa en ninguna rama de esa función, por eso no se recibe aquí.
     */
    private function resolverCamposPromo(?array $promo, ?int $nivelCliente, array $productoActual, Collection $obsequioProductos, Collection $regalosPorPp, string $host): array
    {
        $descuento = 0;
        $cantidadDescuento = 0;
        $obsequio = [];

        if (empty($promo)) {
            return compact('descuento', 'cantidadDescuento', 'obsequio');
        }

        $discountType = (int) $promo['discount_type'];

        if ($discountType === 1) {
            $descuento = (int) $promo['discount'];
        } elseif ($discountType === 2) {
            $cantidadDescuento = (int) $promo['buy_quantity'];
            $productoObsequio = $this->formatearObsequio($productoActual, $nivelCliente, $host);

            if ($productoObsequio) {
                $dtp = (int) $promo['discount_type_promotion'];
                if ($dtp === 2) {
                    $productoObsequio['cantidad_descuento'] = (int) $promo['discount'];
                    $productoObsequio['descuento'] = 0;
                    $productoObsequio['precio_total_sin_promo'] = $productoObsequio['precio_total'];
                    $productoObsequio['precio_total'] = 0;
                } elseif ($dtp === 1) {
                    $pct = (int) $promo['discount'];
                    $productoObsequio['descuento'] = $pct;
                    $productoObsequio['cantidad_descuento'] = 0;
                    $productoObsequio['precio_total_sin_promo'] = $productoObsequio['precio_total'];
                    $productoObsequio['precio_total'] = $this->redondearA50((int) ($productoObsequio['precio_total'] * (1 - $pct / 100)));
                }
                $productoObsequio['fk_prom'] = $promo['promo_id'];
                $obsequio = [$productoObsequio];
            }
        } elseif ($discountType === 3) {
            $cantidadDescuento = (int) $promo['cantidad_compra'];
            $ppRowid = (int) $promo['pp_rowid'];

            foreach ($regalosPorPp->get($ppRowid, collect()) as $regalo) {
                $productoRegaloArr = $obsequioProductos->get((int) $regalo->fk_product);
                if (!$productoRegaloArr) {
                    continue;
                }

                $productoRegalo = $this->formatearObsequio($productoRegaloArr, $nivelCliente, $host);
                if ($productoRegalo) {
                    $productoRegalo['cantidad_descuento'] = (int) $promo['cantidad_regalo'];
                    $productoRegalo['descuento'] = 0;
                    $productoRegalo['precio_total_sin_promo'] = $productoRegalo['precio_total'];
                    $productoRegalo['precio_total'] = 0;
                    $productoRegalo['fk_prom'] = $promo['promo_id'];
                    $obsequio[] = $productoRegalo;
                }
            }
        }

        return [
            'descuento' => $descuento,
            'cantidadDescuento' => $cantidadDescuento,
            'obsequio' => $obsequio,
        ];
    }

    /**
     * Puerto de Promociones::buildObsequioProducto. Sin contexto de bodega (ver
     * decisión de simplificación), usa el stock agregado del producto y no arma lotes.
     *
     * $producto es el arreglo plano normalizado (ver buscarProductos() y
     * normalizarProductoEloquent()), no un modelo Eloquent.
     */
    private function formatearObsequio(array $producto, ?int $nivelCliente, string $host): ?array
    {
        if (empty($producto['rowid'])) {
            return null;
        }

        $precioOriginal = (int) $producto['price'];

        $ivaFinal = $precioOriginal * (int) $producto['tva_tx'] / 100;
        $impuestos = $precioOriginal * (int) $producto['localtax1_tx'] / 100;
        $totalP = (int) $producto['price_ttc'] + ceil($impuestos);
        $precioSinIva = $precioOriginal;

        $nivelesUnicos = collect($producto['precios'] ?? [])->unique('price_level')->values();
        $nivelesProducto = $nivelCliente
            ? $nivelesUnicos->where('price_level', $nivelCliente)->values()
            : $nivelesUnicos;

        if ($nivelesProducto->isNotEmpty()) {
            $primerNivel = $nivelesProducto->first();
            $totalP = (int) $primerNivel['price_ttc'];
            $impuestos = (int) $primerNivel['price'] * (int) $primerNivel['tva_tx'] / 100;
            $ivaFinal = (int) $primerNivel['price_ttc'] - (int) $primerNivel['price'];
            $precioSinIva = (int) $primerNivel['price'];
        }

        $barcodes = $producto['barcodes'] ?? [];
        $codigoBarras = $barcodes[0] ?? null;
        $codigoBarras = $codigoBarras !== null && $codigoBarras !== '' ? $codigoBarras : $producto['ref'];

        $urlImagen = '';
        /*$imagen = $producto['imagen'] ?? [];
        if (!empty($imagen['filename'])) {
            $nombreArchivo = str_replace(' ', '+', $imagen['filename']);
            $partes = explode('/', $imagen['filepath'] ?? '');
            $carpeta = str_replace(' ', '+', $partes[1] ?? '');
            $urlImagen = "https://{$host}/viewimage.php?modulepart=product&file=/{$carpeta}/{$nombreArchivo}&publictakepos=1";
        }*/
        if(($producto['imagen_principal'] ?? '') != ""){
            $resultado = preg_replace('#^[^/]+#', '', (string) $producto['imagen_principal']);
            $urlImagen = "https://{$host}/viewimage.php?modulepart=product&file={$resultado}&publictakepos=1";
        }

        $impuestosExtras = collect($producto['impuestosExtra'] ?? [])->map(fn ($imp) => [
            'tipo' => self::TIPOS_IMPUESTO_EXTRA[$imp['fk_type']] ?? 'Otros',
            'valor' => (int) $imp['impuesto'],
        ])->values()->all();

        return [
            'rowid' => (int) $producto['rowid'],
            'ref' => (string) $producto['ref'],
            'label' => (string) $producto['label'],
            'description' => html_entity_decode(ucfirst(strtolower((string) $producto['description']))),
            'precio' => $precioSinIva,
            'iva' => (int) ceil($ivaFinal),
            'impuestos' => (int) ceil($impuestos),
            'precio_total' => $this->redondearA50($totalP),
            'precio_total_sin_promo' => $this->redondearA50($totalP),
            'precio_minimo' => (int) $producto['price_min'],
            'descuento' => 0,
            'cantidad_descuento' => 0,
            'urlImagen' => $urlImagen,
            'ventaGramos' => (bool) ($producto['venta_gramos'] ?? false),
            'stock' => $producto['stock'] ?? [],
            'codigo_barras' => $codigoBarras,
            'lotes' => [],
            'ventaPrecio' => (bool) ($producto['venta_precio'] ?? false),
            'ventaCantidad' => (bool) ($producto['venta_cantidad'] ?? false),
            'impuestosExtras' => $impuestosExtras,
            'nivelesPrecios' => [],
            'obsequio' => [],
            'fk_plantilla' => (int) ($producto['fk_plantilla_contable'] ?? 0),
        ];
    }

    /**
     * Puerto de precioPUM() (api_precios.lib.php): precio por unidad de medida,
     * tomando la primera dimension fisica no nula del producto en el orden
     * weight -> length -> width -> height -> surface -> volume, dividida entre
     * el precio de venta vigente. La unidad mostrada sale de c_units, matcheando
     * unit_type (nombre del campo de dimension) y scale (contra el *_units del
     * producto, que guarda la escala, no un fk a c_units.rowid).
     */
    private function calcularPum(object $row, Collection $unidadesMedida, float $precioVenta): string
    {
        $dimensiones = [
            'weight' => 'weight_units',
            'length' => 'length_units',
            'width' => 'width_units',
            'height' => 'height_units',
            'surface' => 'surface_units',
            'volume' => 'volume_units',
        ];

        $unitType = '';
        $unitComparable = null;
        $dimensionValue = 0.0;

        foreach ($dimensiones as $campo => $campoUnidad) {
            $valor = $row->{$campo};
            if ($valor !== null && $valor !== '' && (float) $valor != 0) {
                $unitType = $campo;
                $unitComparable = $row->{$campoUnidad};
                $dimensionValue = (float) $valor;
                break;
            }
        }

        if ($unitType === '') {
            return '';
        }

        $unidad = $unidadesMedida->first(fn ($u) => $u->unit_type == $unitType && $unitComparable == $u->scale);

        if (!$unidad) {
            return '';
        }

        return 'PUM $'.number_format($precioVenta / $dimensionValue, 2, ',', '.').' '.$unidad->short_label;
    }

    private function redondearA50(float $valor): int
    {
        return (int) (round($valor / 50) * 50);
    }

    private function decodificarJson(?string $json): array
    {
        return $json === null ? [] : (json_decode($json, true) ?? []);
    }

    /**
     * @return array<int, array{idAlmacen: int, almacen: string, cantidad: int}>
     */
    private function decodificarStock(?string $json): array
    {
        return collect($this->decodificarJson($json))
            ->map(fn ($s) => ['idAlmacen' => (int) $s['idAlmacen'], 'almacen' => (string) ($s['almacen'] ?? ''), 'cantidad' => (int) $s['cantidad']])
            ->values()
            ->all();
    }

    /**
     * Productos asignados a un cliente (integros_producto_cliente). Si
     * idcliente=0, trae todos los clientes que tienen productos asignados,
     * agrupados con sus ids de producto. Replica getClienteProductos()
     * (api_productos.class.php, modulo integrosapk).
     */
    public function clienteProductos(Request $request): array
    {
        $idCliente = (int) $request->input('idcliente', 0);

        if ($idCliente === 0) {
            return $this->todosClientesConProductos();
        }

        $productos = ProductoCliente::query()
            ->where('fk_soc', $idCliente)
            ->with(['producto' => fn ($q) => $q->select('rowid', 'barcode', 'stock')])
            ->orderBy('nombre')
            ->get(['rowid', 'fk_soc', 'fk_product', 'referencia', 'nombre', 'cantidad_tipica', 'fecha_creacion']);

        $productosArr = $productos->map(fn ($pc) => [
            'rowid' => (int) $pc->rowid,
            'fk_soc' => (int) $pc->fk_soc,
            'fk_product' => (int) $pc->fk_product,
            'referencia' => $pc->referencia ?: '',
            'nombre' => $pc->nombre ?: '',
            'barcode' => $pc->producto?->barcode ?: '',
            'cantidad_tipica' => (float) $pc->cantidad_tipica,
            'stock_actual' => (float) ($pc->producto?->stock ?? 0),
            'fecha_creacion' => $pc->fecha_creacion,
        ])->values()->all();

        return [
            'cliente_id' => $idCliente,
            'total' => count($productosArr),
            'productos' => $productosArr,
            'ids' => array_column($productosArr, 'fk_product'),
        ];
    }

    /**
     * Todos los clientes con productos asignados, agrupados con sus ids de
     * producto. Replica _getTodosClientesProductos() del mismo endpoint.
     */
    private function todosClientesConProductos(): array
    {
        $filas = ProductoCliente::query()
            ->with(['sociedad' => fn ($q) => $q->select('rowid', 'nom')])
            ->orderBy('nombre')
            ->get(['fk_soc', 'fk_product', 'nombre']);

        $clientes = $filas->groupBy('fk_soc')
            ->map(fn ($grupo, $fkSoc) => [
                'cliente_id' => (int) $fkSoc,
                'cliente_nombre' => $grupo->first()->sociedad?->nom ?? '',
                'ids' => $grupo->pluck('fk_product')->map(fn ($id) => (int) $id)->values()->all(),
            ])
            ->sortBy('cliente_nombre')
            ->values();

        return [
            'total_clientes' => $clientes->count(),
            'clientes' => $clientes->all(),
        ];
    }
}
