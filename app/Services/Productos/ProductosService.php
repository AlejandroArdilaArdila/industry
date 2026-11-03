<?php

namespace App\Services\Productos;

use App\Models\Configuracion;
use App\Models\PrecioCliente;
use App\Models\Producto;
use App\Models\ProductoBatch;
use App\Models\ProductoCodigoBarra;
use App\Models\ProductoExtrafields;
use App\Models\ProductoPrecio;
use App\Models\ProductoStock;
use App\Models\PromocionCategoria;
use App\Models\PromocionCliente;
use App\Models\PromocionProducto;
use App\Models\PromocionProductoRegalo;
use App\Models\SociedadCategoria;
use App\Models\TerminalPos;
use App\Models\Usuario;
use App\Services\Producto\PromocionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductosService
{
    private const NOMBRES_IMPUESTO_EXTRA = [
        1 => 'IBUA', 2 => 'ICL', 3 => 'ADV', 4 => 'ICUI', 5 => 'Al Consumo', 6 => 'Otros',
    ];

    private const PLANTILLA_CONTABLE_POR_IVA = [0 => 17, 5 => 16, 19 => 15];

    public function __construct(private PromocionService $promocionService)
    {
    }

    /**
     * Une createProductPos() y su variante de edicion en una sola funcion: si el
     * payload trae idProducto edita ese producto, si no lo trae crea uno nuevo.
     * Simplificaciones frente al original (legado Dolibarr):
     * - Se omiten peso/volumen: en el original esas variables ($peso, $unidadP,
     *   $volumen, $unidadV) estaban comentadas donde se definian pero seguian
     *   usandose en la rama de edicion, lo que ya era codigo muerto/roto ahi.
     * - Se omite el bloque de "integrosmargenes" (historial de cambios de precio):
     *   ese modulo no esta instalado en esta base de datos (ni existe la tabla
     *   integrosmargenes_product_margen), asi que esa rama nunca podia ejecutarse.
     * - getvalidateProduct() no estaba definida en el codigo entregado; aqui se
     *   asume su intencion evidente (solo se llama al crear, nunca al editar):
     *   rechazar referencia o codigo de barras ya registrados en otro producto.
     * - Product::updatePrice() de Dolibarr se reemplaza por una insercion directa
     *   en product_price con los mismos valores enteros que el original terminaba
     *   forzando de todos modos con los UPDATE crudos posteriores a la llamada.
     */
    public function guardarProducto(Request $request, Usuario $usuario): array
    {
        $datos = $request->all();

        $ref = trim((string) ($datos['ref'] ?? ''));
        if ($ref === '') {
            abort(401, 'La referencia del producto es obligatoria.');
        }

        $idProducto = (int) ($datos['idProducto'] ?? 0);
        $edita = $idProducto > 0;

        if (!$edita && Producto::query()->where('ref', $ref)->exists()) {
            abort(401, "Ya existe un producto con la referencia {$ref}.");
        }

        $etiqueta = trim((string) ($datos['etiqueta'] ?? ''));
        if ($etiqueta === '') {
            abort(401, 'La etiqueta del producto es obligatoria.');
        }

        if (!isset($datos['estadoVenta']) || $datos['estadoVenta'] === '') {
            abort(401, 'El estado de venta es obligatorio.');
        }

        if (!isset($datos['estadoCompra']) || $datos['estadoCompra'] === '') {
            abort(401, 'El estado de compra es obligatorio.');
        }

        if (!isset($datos['precioConIva']) || $datos['precioConIva'] === '') {
            abort(401, 'El precio del producto con IVA es obligatorio.');
        }

        if (!isset($datos['iva']) || $datos['iva'] === '') {
            abort(401, 'La tasa del IVA es obligatoria.');
        }

        $codigoBarrasArray = is_array($datos['codigoBarras'] ?? null)
            ? array_values(array_filter($datos['codigoBarras'], fn ($b) => $b !== null && $b !== ''))
            : [];

        if (!$edita && !empty($codigoBarrasArray)
            && ProductoCodigoBarra::query()->whereIn('barcode', $codigoBarrasArray)->exists()) {
            abort(401, 'Uno de los códigos de barras ingresados ya está registrado en otro producto.');
        }

        $iva = (float) $datos['iva'];
        $precioConIva = (float) $datos['precioConIva'];
        $precioMinRaw = $datos['precioMin'] ?? null;
        $tienePrecioMin = $precioMinRaw !== null && $precioMinRaw !== '';

        $precio = (int) round($precioConIva / (1 + $iva / 100));
        $precioMin = $tienePrecioMin ? (int) round(((float) $precioMinRaw) / (1 + $iva / 100)) : 0;
        $priceTtcInt = (int) round($precioConIva);
        $priceMinTtcInt = $tienePrecioMin ? (int) round((float) $precioMinRaw) : 0;
        $descripcion = $datos['descripcion'] ?? '';
        $plantillaContable = self::PLANTILLA_CONTABLE_POR_IVA[(int) $iva] ?? null;

        $multipreciosActivo = (Configuracion::valores(['PRODUIT_MULTIPRICES'])['PRODUIT_MULTIPRICES'] ?? null) == 1;
        $nivelPrecio = $multipreciosActivo ? 1 : 0;

        if ($edita) {
            $producto = Producto::query()->find($idProducto);

            if (!$producto) {
                abort(401, "El ID [{$idProducto}] No está asociado a ningun producto, validelo e intente nuevamente.");
            }
            
            $prodReferencia = Producto::query()->where('ref', $ref)->first();
            
            if ($prodReferencia && $prodReferencia->rowid !== $producto->rowid) {
                abort(401, "La referencias ingresada ya está registrada en otro producto..");
            }


            $editarPreciosActivo = (Configuracion::valores(['EDITAR_PRECIOS'])['EDITAR_PRECIOS'] ?? null) == 1;
            if ($precioConIva != (float) $producto->price_ttc && !$editarPreciosActivo) {
                abort(401, 'Esta terminal no tiene permisos para editar el precio del producto.');
            }

            DB::transaction(function () use (
                $producto, $ref, $etiqueta, $descripcion, $precio, $precioConIva, $iva,
                $datos, $codigoBarrasArray, $plantillaContable, $nivelPrecio, $precioMin,
                $priceTtcInt, $priceMinTtcInt
            ) {
                $producto->ref = $ref;
                $producto->label = $etiqueta;
                $producto->description = $descripcion;
                $producto->price = $precio;
                $producto->price_ttc = $precioConIva;
                $producto->tva_tx = $iva;
                $producto->tosell = $datos['estadoVenta'];
                $producto->tobuy = $datos['estadoCompra'];
                $producto->save();

                if (!empty($codigoBarrasArray)) {
                    ProductoCodigoBarra::query()->where('fk_product', $producto->rowid)->delete();

                    foreach ($codigoBarrasArray as $barcode) {
                        $nuevoBarcode = new ProductoCodigoBarra();
                        $nuevoBarcode->fk_product = $producto->rowid;
                        $nuevoBarcode->barcode = $barcode;
                        $nuevoBarcode->datec = now();
                        $nuevoBarcode->save();
                    }
                }

                ProductoExtrafields::query()
                    ->where('fk_object', $producto->rowid)
                    ->update(array_filter([
                        'producto_publicado' => 1,
                        'fk_plantilla_contable' => $plantillaContable,
                    ], fn ($v) => $v !== null));

                $nuevoPrecio = new ProductoPrecio();
                $nuevoPrecio->fk_product = $producto->rowid;
                $nuevoPrecio->date_price = now();
                $nuevoPrecio->price_level = $nivelPrecio;
                $nuevoPrecio->price = $precio;
                $nuevoPrecio->price_ttc = $priceTtcInt;
                $nuevoPrecio->price_min = $precioMin;
                $nuevoPrecio->price_min_ttc = $priceMinTtcInt;
                $nuevoPrecio->price_base_type = 'HT';
                $nuevoPrecio->tva_tx = $iva;
                $nuevoPrecio->save();

                Producto::query()->where('rowid', $producto->rowid)->update([
                    'price' => $precio,
                    'price_ttc' => $priceTtcInt,
                    'price_min' => $precioMin,
                    'price_min_ttc' => $priceMinTtcInt,
                ]);
            });

            return [
                'status' => 1,
                'msg' => 'Producto editado correctamente',
            ];
        }

        $crearProductosActivo = (Configuracion::valores(['CREAR_PRODUCTOS'])['CREAR_PRODUCTOS'] ?? null) == 1;
        if (!$crearProductosActivo) {
            abort(401, 'Esta terminal no tiene permisos para crear productos.');
        }

        $stockRaw = $datos['stock'] ?? '';
        if ($stockRaw !== '' && !is_numeric($stockRaw)) {
            abort(401, 'El stock ingresado no es numérico, por favor valídelo.');
        }

        $idProductoNuevo = DB::transaction(function () use (
            $ref, $etiqueta, $descripcion, $precio, $precioConIva, $iva,
            $datos, $codigoBarrasArray, $plantillaContable, $nivelPrecio, $precioMin,
            $priceTtcInt, $priceMinTtcInt, $stockRaw, $usuario
        ) {
            $producto = new Producto();
            $producto->ref = $ref;
            $producto->entity = 1;
            $producto->label = $etiqueta;
            $producto->description = $descripcion;
            $producto->price = $precio;
            $producto->price_ttc = $priceTtcInt;
            $producto->price_base_type = 'HT';
            $producto->tva_tx = $iva;
            $producto->localtax1_tx = $iva;
            $producto->tosell = $datos['estadoVenta'];
            $producto->tobuy = $datos['estadoCompra'];
            $producto->save();

            $extrafields = new ProductoExtrafields();
            $extrafields->fk_object = $producto->rowid;
            $extrafields->fk_plantilla_contable = $plantillaContable;
            $extrafields->save();

            foreach ($codigoBarrasArray as $barcode) {
                $nuevoBarcode = new ProductoCodigoBarra();
                $nuevoBarcode->fk_product = $producto->rowid;
                $nuevoBarcode->barcode = $barcode;
                $nuevoBarcode->datec = now();
                $nuevoBarcode->save();
            }

            $nuevoPrecio = new ProductoPrecio();
            $nuevoPrecio->fk_product = $producto->rowid;
            $nuevoPrecio->date_price = now();
            $nuevoPrecio->price_level = $nivelPrecio;
            $nuevoPrecio->price = $precio;
            $nuevoPrecio->price_ttc = $priceTtcInt;
            $nuevoPrecio->price_min = $precioMin;
            $nuevoPrecio->price_min_ttc = $priceMinTtcInt;
            $nuevoPrecio->price_base_type = 'HT';
            $nuevoPrecio->tva_tx = $iva;
            $nuevoPrecio->save();

            if ($stockRaw !== '' && (int) $stockRaw > 0) {
                $idTerminal = (int) ($usuario->terminal()->value('rowid') ?? 0);
                $idAlmacen = (int) (TerminalPos::query()->where('rowid', $idTerminal)->value('fk_warehouse') ?? 0);

                $nuevoStock = new ProductoStock();
                $nuevoStock->fk_product = $producto->rowid;
                $nuevoStock->fk_entrepot = $idAlmacen;
                $nuevoStock->reel = (int) $stockRaw;
                $nuevoStock->save();

                $lote = trim((string) ($datos['lote'] ?? ''));
                if ($lote !== '') {
                    $nuevoLote = new ProductoBatch();
                    $nuevoLote->fk_product_stock = $nuevoStock->rowid;
                    $nuevoLote->batch = $lote;
                    $nuevoLote->qty = (int) $stockRaw;
                    $nuevoLote->save();
                }
            }

            return $producto->rowid;
        });

        return [
            'status' => 1,
            'msg' => 'Producto agregado correctamente',
            'idProduct' => (int) $idProductoNuevo,
        ];
    }

    /**
     * Productos de una promocion en formato POS completo (precios, stock, lotes,
     * promociones), mas los clientes a los que aplica esa promocion.
     */
    public function productosPorPromocion(Request $request, int $idPromo, Usuario $usuario): array
    {
        
        if ($idPromo <= 0) {
            return ['error' => 'ID de promoción inválido'];
        }

        $clienteId = (int) $request->input('clienteId', 0);

        $productIds = PromocionProducto::query()
            ->where('fk_prom', $idPromo)
            ->distinct()
            ->pluck('fk_product')
            ->map(fn ($id) => (int) $id)
            ->all();

        $clientesPromocion = [
            'promocion' => $idPromo,
            'clientes' => $this->clientesDePromocion($idPromo),
        ];

        if (empty($productIds)) {
            return [
                'productos' => [],
                'clientes_promocion' => $clientesPromocion,
                'totalEncontrados' => 0,
                'totalNoEncontrados' => 0,
                'idsNoEncontrados' => [],
            ];
        }

        $resultado = $this->buscarProductosPorIds($productIds, $clienteId > 0 ? $clienteId : null, $usuario, $request->getHost());
        $resultado['clientes_promocion'] = $clientesPromocion;

        return $resultado;
    }

    /**
     * Clientes asignados directamente a la promocion + los que pertenecen a
     * alguna categoria asignada a la promocion.
     */
    private function clientesDePromocion(int $idPromo): array
    {
        $directos = PromocionCliente::query()->where('fk_prom', $idPromo)->pluck('fk_soc');

        $categoriaIds = PromocionCategoria::query()->where('fk_prom', $idPromo)->pluck('fk_category');

        $porCategoria = $categoriaIds->isNotEmpty()
            ? SociedadCategoria::query()->whereIn('fk_categorie', $categoriaIds)->pluck('fk_soc')
            : collect();

        return $directos->merge($porCategoria)
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Replica getsearchProductosPos(): productos por lista de IDs, con precio,
     * promociones, niveles de precio, impuestos extra, lotes de la bodega del
     * terminal del usuario, y packs.
     */
    private function buscarProductosPorIds(array $productIds, ?int $clienteId, Usuario $usuario, string $host): array
    {
        $idTerminal = (int) ($usuario->terminal()->value('rowid') ?? 0);
        $idAlmacen = (int) (TerminalPos::query()->where('rowid', $idTerminal)->value('fk_warehouse') ?? 0);

        $nivelCliente = null;
        if ($clienteId) {
            $nivel = (int) PrecioCliente::query()
                ->where('fk_soc', $clienteId)
                ->orderByDesc('rowid')
                ->value('price_level');

            if ($nivel > 0) {
                $nivelCliente = $nivel;
            }
        }

        $productos = Producto::query()
            ->whereIn('rowid', $productIds)
            ->select(['rowid', 'ref', 'label', 'description', 'price', 'price_ttc', 'price_min_ttc', 'tva_tx', 'localtax1_tx', 'stock', 'fk_product_type'])
            ->with([
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'descuento', 'venta_gramos', 'venta_precio', 'venta_cantidad', 'descuento_global'),
                'barcodes' => fn ($q) => $q->select('fk_product', 'barcode'),
                'packs' => fn ($q) => $q->select('fk_product', 'unidades', 'precio', 'precio_minimo')->orderBy('unidades'),
                'impuestosExtra' => fn ($q) => $q->where('estado', 1)->select('fk_product', 'fk_type', 'impuesto'),
                'precios' => fn ($q) => $q->select('fk_product', 'price_level', 'price', 'price_ttc', 'tva_tx')
                    ->where('price_level', '>', 0)
                    ->where('price', '>', 0)
                    ->orderByDesc('rowid'),
                'imagenPrincipal' => fn ($q) => $q->select('src_object_id', 'filename', 'filepath'),
            ])
            ->get();

        $lotesPorProducto = $this->lotesPorAlmacen($productIds, $idAlmacen);
        $promoMap = $this->promocionService->resolverParaProductos($productIds, $clienteId);

        $objProductos = [];
        $idsEncontrados = [];

        foreach ($productos as $producto) {
            $objProductos[] = $this->formatearProducto($producto, $nivelCliente, $idAlmacen, $lotesPorProducto, $promoMap, $host);
            $idsEncontrados[] = (int) $producto->rowid;
        }

        $idsNoEncontrados = array_values(array_diff($productIds, $idsEncontrados));

        return [
            'productos' => $objProductos,
            'idsNoEncontrados' => $idsNoEncontrados,
            'totalEncontrados' => count($objProductos),
            'totalNoEncontrados' => count($idsNoEncontrados),
        ];
    }

    private function lotesPorAlmacen(array $productIds, int $idAlmacen): array
    {
        return ProductoBatch::query()
            ->join('product_stock as ps', 'ps.rowid', '=', 'product_batch.fk_product_stock')
            ->whereIn('ps.fk_product', $productIds)
            ->where('ps.fk_entrepot', $idAlmacen)
            ->where('product_batch.qty', '>', 0)
            ->get(['ps.fk_product as fk_product', 'product_batch.batch', 'product_batch.qty'])
            ->groupBy('fk_product')
            ->map(fn ($lotes) => $lotes->map(fn ($l) => ['lote' => $l->batch, 'stock' => (int) $l->qty])->values()->all())
            ->all();
    }

    private function formatearProducto(Producto $producto, ?int $nivelCliente, int $idAlmacen, array $lotesPorProducto, array $promoMap, string $host): array
    {
        $extra = $producto->extrafields;
        $precioOriginal = (int) $producto->price;

        $descuento = 0;
        $descuentoRaw = $extra?->descuento;
        if (!is_null($descuentoRaw)) {
            if (str_contains((string) $descuentoRaw, '%')) {
                $descuentoPar = (int) trim(str_replace('%', '', $descuentoRaw));
                if ($descuentoPar > 100) {
                    $descuentoPar = 100;
                }
                $descuento = $precioOriginal * $descuentoPar / 100;
            } else {
                $descuento = (int) $descuentoRaw;
            }
        }

        $ivaPorcentaje = (int) $producto->tva_tx;
        $ivaFinal = ($precioOriginal * $ivaPorcentaje) / 100;
        $impuestos = $precioOriginal * (int) $producto->localtax1_tx / 100;

        if ($ivaPorcentaje > 0 && $descuento > 0) {
            $ivaDescuento = ($precioOriginal - $descuento) * $ivaPorcentaje / 100;
            $descuento += $ivaDescuento == 0 ? ceil($ivaFinal) : $ivaDescuento;
        }

        $totalP = (int) $producto->price_ttc + ceil($impuestos);
        $precio = $precioOriginal;

        $impuestosExtras = $producto->impuestosExtra->map(fn ($imp) => [
            'tipo' => self::NOMBRES_IMPUESTO_EXTRA[$imp->fk_type] ?? 'Otros',
            'valor' => (int) $imp->impuesto,
        ])->values()->all();

        $nivelesUnicos = $producto->precios->unique('price_level')->values();
        $nivelesProducto = $nivelCliente
            ? $nivelesUnicos->where('price_level', $nivelCliente)->values()
            : $nivelesUnicos;

        if ($nivelesProducto->isNotEmpty()) {
            $primerNivel = $nivelesProducto->first();
            $totalP = (int) $primerNivel->price_ttc;
            $impuestos = (int) $primerNivel->price * (int) $primerNivel->tva_tx / 100;
            $ivaFinal = (int) $primerNivel->price_ttc - (int) $primerNivel->price;
            $precio = (int) $primerNivel->price;
        }

        $nivelesSalida = $nivelesProducto->count() > 1
            ? $nivelesProducto->map(fn ($n) => [
                'nivel' => (int) $n->price_level,
                'precioSinIva' => (int) $n->price,
                'precioConIva' => (int) $n->price_ttc,
                'iva' => (int) $n->tva_tx,
            ])->values()->all()
            : [];

        $promo = $promoMap[$producto->rowid] ?? null;
        $precioTotalSinPromo = $totalP;
        if ($promo && (int) $promo['discount_type'] === 1) {
            $totalP = $this->promocionService->calcularPrecioConPromocion($totalP, $promo);
        }
        $camposPromo = $this->resolverCamposPromo($promo, $idAlmacen, $nivelCliente, $host);

        $codigoBarras = $producto->barcodes->pluck('barcode')->implode('|');

        $urlImagen = '';
        $imagen = $producto->imagenPrincipal;
        if ($imagen && $imagen->filename) {
            $urlImagen = $this->urlImagen($host, $imagen->filename, $imagen->filepath);
        }

        return [
            'rowid' => (int) $producto->rowid,
            'ref' => (string) $producto->ref,
            'label' => (string) $producto->label,
            'description' => html_entity_decode(ucfirst(strtolower((string) $producto->description))),
            'precio' => $precio,
            'iva' => (int) ceil($ivaFinal),
            'porcentajeIva' => $ivaPorcentaje,
            'impuestos' => (int) ceil($impuestos),
            'precio_total' => $this->redondearA50($totalP),
            'precio_total_sin_promo' => $this->redondearA50($precioTotalSinPromo),
            'precio_minimo' => (int) $producto->price_min_ttc,
            'descuento' => $camposPromo['descuento'] > 0 ? $camposPromo['descuento'] : ((int) $descuento ?: 0),
            'cantidad_descuento' => $camposPromo['cantidadDescuento'],
            'urlImagen' => $urlImagen,
            'ventaGramos' => (bool) ($extra?->venta_gramos ?? false),
            'stock' => (int) ($producto->stock ?? 0),
            'codigo_barras' => $codigoBarras,
            'lotes' => $lotesPorProducto[$producto->rowid] ?? [],
            'ventaPrecio' => (bool) ($extra?->venta_precio ?? false),
            'ventaCantidad' => (bool) ($extra?->venta_cantidad ?? false),
            'impuestosExtras' => $impuestosExtras,
            'nivelesPrecios' => $nivelesSalida,
            'obsequio' => $camposPromo['obsequio'],
            'descuentoGlobal' => (int) ($extra?->descuento_global ?? 0),
            'tipoProducto' => (int) $producto->fk_product_type,
            'packs' => $producto->packs->map(fn ($p) => [
                'cantidad' => (int) $p->unidades,
                'precio' => (float) $p->precio,
                'minimo' => (float) $p->precio_minimo,
            ])->values()->all(),
        ];
    }

    /**
     * Puerto de Promociones::resolverCamposPromo.
     */
    private function resolverCamposPromo(?array $promo, int $idAlmacen, ?int $nivelCliente, string $host): array
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
            $productoObsequio = $this->construirProductoObsequio((int) $promo['fk_product'], $idAlmacen, $nivelCliente, $host);

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

            if ($ppRowid > 0) {
                $idsRegalo = PromocionProductoRegalo::query()->where('fk_pp', $ppRowid)->pluck('fk_product');

                foreach ($idsRegalo as $idRegalo) {
                    $productoRegalo = $this->construirProductoObsequio((int) $idRegalo, $idAlmacen, $nivelCliente, $host);

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
        }

        return [
            'descuento' => $descuento,
            'cantidadDescuento' => $cantidadDescuento,
            'obsequio' => $obsequio,
        ];
    }

    /**
     * Puerto de Promociones::buildObsequioProducto. A diferencia del producto
     * principal, aqui el stock y los lotes SI se calculan por la bodega activa.
     */
    private function construirProductoObsequio(int $productoId, int $idAlmacen, ?int $nivelCliente, string $host): ?array
    {
        if ($productoId <= 0) {
            return null;
        }

        $producto = Producto::query()
            ->where('rowid', $productoId)
            ->select(['rowid', 'ref', 'label', 'description', 'price', 'price_ttc', 'price_min', 'tva_tx', 'localtax1_tx'])
            ->with([
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'venta_gramos', 'venta_precio', 'venta_cantidad', 'fk_plantilla_contable'),
                'barcodes' => fn ($q) => $q->select('fk_product', 'barcode'),
                'impuestosExtra' => fn ($q) => $q->where('estado', 1)->select('fk_product', 'fk_type', 'impuesto'),
                'precios' => fn ($q) => $q->select('fk_product', 'price_level', 'price', 'price_ttc', 'tva_tx')
                    ->where('price_level', '>', 0)
                    ->where('price', '>', 0)
                    ->orderByDesc('rowid'),
                'imagenPrincipal' => fn ($q) => $q->select('src_object_id', 'filename', 'filepath'),
            ])
            ->first();

        if (!$producto) {
            return null;
        }

        $extra = $producto->extrafields;
        $precioOriginal = (int) $producto->price;

        $ivaFinal = $precioOriginal * (int) $producto->tva_tx / 100;
        $impuestos = $precioOriginal * (int) $producto->localtax1_tx / 100;
        $totalP = (int) $producto->price_ttc + ceil($impuestos);
        $precioSinIva = $precioOriginal;

        $nivelesUnicos = $producto->precios->unique('price_level')->values();
        $nivelesProducto = $nivelCliente
            ? $nivelesUnicos->where('price_level', $nivelCliente)->values()
            : $nivelesUnicos;

        if ($nivelesProducto->isNotEmpty()) {
            $primerNivel = $nivelesProducto->first();
            $totalP = (int) $primerNivel->price_ttc;
            $impuestos = (int) $primerNivel->price * (int) $primerNivel->tva_tx / 100;
            $ivaFinal = (int) $primerNivel->price_ttc - (int) $primerNivel->price;
            $precioSinIva = (int) $primerNivel->price;
        }

        $codigoBarras = $producto->barcodes->pluck('barcode')->first();
        $codigoBarras = $codigoBarras !== null && $codigoBarras !== '' ? $codigoBarras : $producto->ref;

        $urlImagen = '';
        $imagen = $producto->imagenPrincipal;
        if ($imagen && $imagen->filename) {
            $urlImagen = $this->urlImagen($host, $imagen->filename, $imagen->filepath);
        }

        $lotes = ProductoBatch::query()
            ->join('product_stock as ps', 'ps.rowid', '=', 'product_batch.fk_product_stock')
            ->where('ps.fk_product', $productoId)
            ->where('ps.fk_entrepot', $idAlmacen)
            ->where('product_batch.qty', '>', 0)
            ->get(['product_batch.batch', 'product_batch.qty'])
            ->map(fn ($l) => ['lote' => $l->batch, 'stock' => (int) $l->qty])
            ->values()
            ->all();

        $stockAlmacen = (int) (ProductoStock::query()
            ->where('fk_product', $productoId)
            ->where('fk_entrepot', $idAlmacen)
            ->value('reel') ?? 0);

        $impuestosExtras = $producto->impuestosExtra->map(fn ($imp) => [
            'tipo' => self::NOMBRES_IMPUESTO_EXTRA[$imp->fk_type] ?? 'Otros',
            'valor' => (int) $imp->impuesto,
        ])->values()->all();

        return [
            'rowid' => (int) $producto->rowid,
            'ref' => $producto->ref,
            'label' => $producto->label,
            'description' => html_entity_decode(ucfirst(strtolower((string) $producto->description))),
            'precio' => $precioSinIva,
            'iva' => (int) ceil($ivaFinal),
            'impuestos' => (int) ceil($impuestos),
            'precio_total' => $this->redondearA50($totalP),
            'precio_total_sin_promo' => $this->redondearA50($totalP),
            'precio_minimo' => (int) $producto->price_min,
            'descuento' => 0,
            'cantidad_descuento' => 0,
            'urlImagen' => $urlImagen,
            'ventaGramos' => (bool) ($extra?->venta_gramos ?? false),
            'stock' => $stockAlmacen,
            'codigo_barras' => $codigoBarras,
            'lotes' => $lotes,
            'ventaPrecio' => (bool) ($extra?->venta_precio ?? false),
            'ventaCantidad' => (bool) ($extra?->venta_cantidad ?? false),
            'impuestosExtras' => $impuestosExtras,
            'nivelesPrecios' => [],
            'obsequio' => [],
            'fk_plantilla' => (int) ($extra?->fk_plantilla_contable ?? 0),
        ];
    }

    private function urlImagen(string $host, string $filename, ?string $filepath): string
    {
        /*$nombreArchivo = str_replace(' ', '+', $filename);
        $partes = explode('/', (string) $filepath);
        $carpeta = str_replace(' ', '+', $partes[1] ?? '');

        return "https://{$host}/viewimage.php?modulepart=product&file=/{$carpeta}/{$nombreArchivo}&publictakepos=1";*/

        $resultado = preg_replace('#^[^/]+#', '', (string) $filepath);
        return $urlImagen = "https://{$host}/viewimage.php?modulepart=product&file={$resultado}&publictakepos=1";
    }

    private function redondearA50(float $valor): int
    {
        return (int) (round($valor / 50) * 50);
    }
}
