<?php

namespace App\Services\TomaFisica;

use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Replica doGuardarLinea()/guardarLinea() (api_tomafisica.class.php, modulo
 * integros): guarda (upsert) una linea de toma fisica en curso del usuario
 * (fk_tomafisica=0), o la elimina si 'tomafisica' viene vacio/null.
 *
 * A diferencia del resto de la API, este endpoint no aborta con HTTP 401
 * ante fallas de negocio (toma pendiente, producto ya contado): responde
 * 200 con {success:false, message}, igual que el legacy. Solo un error
 * real de base de datos propaga una excepcion. Ninguno de los endpoints de
 * este servicio valida permisos (a diferencia del legacy, que exige
 * rights->integros->tomafisica->*).
 */
class TomaFisicaService
{
    public function guardarLinea(Request $request, Usuario $usuario): array
    {
        $idProducto = (int) $request->input('idproducto', 0);
        $idAlmacen = (int) $request->input('idalmacen', 0);
        $toma = $request->input('tomafisica');

        if ($idProducto <= 0 || $idAlmacen <= 0) {
            return ['success' => false, 'message' => 'Producto o almacén inválido.'];
        }

        $userId = (int) $usuario->rowid;
        [$existencia, $precio] = $this->existenciaYPrecio($idProducto, $idAlmacen);

        $numToma = $this->numeroToma($request);

        if ($toma === null || $toma === '') {
            DB::connection('mysql')->table('integros_tomafisica_det')
                ->where('idproducto', $idProducto)
                ->where('idalmacen', $idAlmacen)
                ->where('fk_user', $userId)
                ->where('fk_tomafisica', 0)
                ->where('numero_toma', $numToma)
                ->delete();

            return [
                'success' => true,
                'deleted' => true,
                'existencia' => $existencia,
                'toma_otra' => $this->tomaOtraProducto($idProducto, $idAlmacen, $userId, $numToma),
            ];
        }

        $toma = (float) str_replace(',', '.', (string) $toma);

        if ($toma < 0) {
            return ['success' => false, 'message' => 'La toma física no puede ser negativa.'];
        }

        // Un usuario no puede tener dos tomas pendientes con distinto numero
        $pendiente = $this->otraTomaPendiente($idAlmacen, $userId, $numToma);
        if ($pendiente > 0) {
            return ['success' => false, 'message' => "Tienes la Toma {$pendiente} pendiente en este almacén. Ciérrala o inicialízala antes de contar en la Toma {$numToma}."];
        }

        // Dos usuarios no pueden contar el mismo producto en el mismo numero de toma
        $otroUsuario = $this->productoContadoPorOtro($idProducto, $idAlmacen, $userId, $numToma);
        if ($otroUsuario !== '') {
            return ['success' => false, 'message' => "Este producto ya fue contado en la Toma {$numToma} por {$otroUsuario}. Usa el otro número de toma."];
        }

        $diferencia = $existencia - $toma;
        $ahora = now()->format('Y-m-d H:i:s');
        $prefijo = DB::connection('mysql')->getTablePrefix();

        DB::connection('mysql')->statement("
            INSERT INTO {$prefijo}integros_tomafisica_det
                (idproducto, idalmacen, fk_tomafisica, numero_toma, tomafisica, existencia, diferencia, precio_compra, fk_user, date_creation)
            VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                tomafisica = VALUES(tomafisica),
                existencia = VALUES(existencia),
                diferencia = VALUES(diferencia),
                precio_compra = VALUES(precio_compra)
        ", [$idProducto, $idAlmacen, $numToma, $toma, $existencia, $diferencia, $precio, $userId, $ahora]);

        return [
            'success' => true,
            'deleted' => false,
            'tomafisica' => $toma,
            'existencia' => $existencia,
            'diferencia' => $diferencia,
            'toma_otra' => $this->tomaOtraProducto($idProducto, $idAlmacen, $userId, $numToma),
            'fecha' => $ahora,
            'usuario' => $usuario->login,
        ];
    }

    /**
     * En que almacen(es) y con que numero de toma tiene el usuario
     * autenticado una toma fisica en curso (fk_tomafisica=0), sin recibir
     * nada mas que el DOLAPIKEY. Generaliza getTomaPendiente() (que exigia
     * idalmacen) para no depender de que el cliente ya sepa el almacen.
     * Un usuario puede tener tomas en curso en mas de un almacen a la vez
     * (la regla "una toma por almacen" es por almacen, no global), por eso
     * devuelve una lista.
     */
    public function tomaActivaUsuario(Usuario $usuario): array
    {
        $userId = (int) $usuario->rowid;
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $filas = DB::connection('mysql')->select("
            SELECT idalmacen, numero_toma, COUNT(*) AS contados
            FROM {$prefijo}integros_tomafisica_det
            WHERE fk_user = ? AND fk_tomafisica = 0
            GROUP BY idalmacen, numero_toma
            ORDER BY idalmacen ASC, numero_toma ASC
        ", [$userId]);

        $idsAlmacen = array_values(array_unique(array_map(fn ($f) => (int) $f->idalmacen, $filas)));
        $almacenes = Almacen::query()->whereIn('rowid', $idsAlmacen)->pluck('ref', 'rowid');

        $tomas = array_map(fn ($fila) => [
            'idalmacen' => (int) $fila->idalmacen,
            'descripcion' => $almacenes[$fila->idalmacen] ?? '',
            'numero_toma' => (int) $fila->numero_toma,
            'contados' => (int) $fila->contados,
        ], $filas);

        return [
            'success' => true,
            'total' => count($tomas),
            'tomas' => $tomas,
        ];
    }

    /**
     * Todos los productos del catalogo para la grilla de toma fisica, con
     * su conteo en curso (si lo tiene) via LEFT JOIN contra
     * integros_tomafisica_det (fk_tomafisica = 0 = toma en curso, no
     * cerrada). Version liviana de doListProductos(): sin paginacion,
     * ordenamiento ni las subconsultas pesadas de categorias/proveedor/
     * toma_otra - solo los campos que la grilla necesita para pintar.
     */
    public function temporalTomaFisica(Request $request): array
    {
        $idAlmacen = (int) $request->input('idalmacen', 0);
        $idUsuario = (int) $request->input('idusuario', 0);
        $numToma = $this->numeroToma($request);

        if ($idAlmacen <= 0 || $idUsuario <= 0) {
            return ['success' => false, 'message' => 'Almacén o usuario inválido.', 'total' => 0, 'productos' => []];
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();

        $filas = DB::connection('mysql')->select("
            SELECT p.rowid AS idproducto, p.ref AS product_ref, p.label AS product_label,
                   COALESCE(ps.reel, 0) AS existencia,
                   tf.tomafisica, u.login AS usuario
            FROM {$prefijo}product p
            LEFT JOIN {$prefijo}product_stock ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ?
            LEFT JOIN {$prefijo}integros_tomafisica_det tf
                ON tf.idproducto = p.rowid AND tf.idalmacen = ? AND tf.fk_user = ?
               AND tf.fk_tomafisica = 0 AND tf.numero_toma = ?
            LEFT JOIN {$prefijo}user u ON u.rowid = tf.fk_user
            WHERE p.entity IN (0, 1) AND p.fk_product_type = 0
            ORDER BY p.label ASC
        ", [$idAlmacen, $idAlmacen, $idUsuario, $numToma]);

        $productos = array_map(fn ($fila) => [
            'idproducto' => (int) $fila->idproducto,
            'ref' => $fila->product_ref ?? '',
            'descripcion' => $fila->product_label ?? '',
            'existencia' => (float) $fila->existencia,
            'cantidad' => $fila->tomafisica !== null ? (float) $fila->tomafisica : null,
            'usuario' => $fila->usuario ?? '',
        ], $filas);

        return [
            'success' => true,
            'total' => count($productos),
            'productos' => $productos,
        ];
    }

    /**
     * Inicializa la toma en curso del usuario: elimina sus lineas de conteo
     * (fk_tomafisica=0) en el almacen/numero de toma indicados. Las lineas
     * ya asociadas a un encabezado cerrado (fk_tomafisica>0) no se tocan.
     * Replica doInicializarToma()/inicializarToma().
     */
    public function inicializarToma(Request $request, Usuario $usuario): array
    {
        $idAlmacen = (int) $request->input('idalmacen', 0);

        if ($idAlmacen <= 0) {
            return ['success' => false, 'message' => 'Debe seleccionar un almacén.'];
        }

        $userId = (int) $usuario->rowid;
        $numToma = $this->numeroToma($request);

        $eliminados = DB::connection('mysql')->table('integros_tomafisica_det')
            ->where('fk_user', $userId)
            ->where('idalmacen', $idAlmacen)
            ->where(function ($q) {
                $q->where('fk_tomafisica', 0)->orWhereNull('fk_tomafisica');
            })
            ->where('numero_toma', $numToma)
            ->delete();

        DB::connection('mysql')->table('integros_tomafisica_noencontrados')
            ->where('fk_user', $userId)
            ->where('idalmacen', $idAlmacen)
            ->where('fk_tomafisica', 0)
            ->where('numero_toma', $numToma)
            ->delete();

        return [
            'success' => true,
            'eliminados' => $eliminados,
            'message' => "Toma #{$numToma} inicializada: {$eliminados} registro(s) de conteo eliminados.",
        ];
    }

    /**
     * Cierra la toma en curso del usuario: crea el encabezado
     * (integros_tomafisica, ref consecutivo TOMA-0000001), asocia las
     * lineas contadas (fk_tomafisica=0 -> idToma) y genera el movimiento de
     * stock de correccion (product_stock.reel + PMP + stock_mouvement) por
     * cada linea con diferencia <> 0 que no requiera lote. Replica
     * doCerrarToma()/cerrarToma() y Product::correct_stock().
     */
    public function cerrarToma(Request $request, Usuario $usuario): array
    {
        $idAlmacen = (int) $request->input('idalmacen', 0);

        if ($idAlmacen <= 0) {
            return ['success' => false, 'message' => 'Debe seleccionar un almacén.'];
        }

        $userId = (int) $usuario->rowid;
        $numToma = $this->numeroToma($request);
        $prefijo = DB::connection('mysql')->getTablePrefix();

        // Solo cuenta lo que tiene toma fisica: las lineas con cantidad vacia se eliminan
        DB::connection('mysql')->table('integros_tomafisica_det')
            ->where('fk_user', $userId)
            ->where('idalmacen', $idAlmacen)
            ->where('fk_tomafisica', 0)
            ->where('numero_toma', $numToma)
            ->whereNull('tomafisica')
            ->delete();

        $totales = $this->totalesCierre($idAlmacen, $userId, $numToma);

        if ($totales['contados'] <= 0) {
            return ['success' => false, 'message' => 'No hay productos contados en la toma en curso.'];
        }

        $categoriasArr = collect($request->input('categorias_arr', []))
            ->filter(fn ($v) => is_numeric($v) && (int) $v > 0)
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $catsIds = implode(',', $categoriasArr);
        $catsLabels = $categoriasArr
            ? Categoria::query()->whereIn('rowid', $categoriasArr)->orderBy('label')->pluck('label')->implode(', ')
            : '';

        $ahora = now()->format('Y-m-d H:i:s');
        $idToma = null;
        $ref = '';

        // Consecutivo TOMA-0000001: el UNIQUE de ref respalda ante
        // concurrencia (si choca, se reintenta con el siguiente)
        for ($intento = 0; $intento < 3; $intento++) {
            $fila = DB::connection('mysql')->selectOne("
                SELECT COALESCE(MAX(CAST(SUBSTRING(ref, 6) AS UNSIGNED)), 0) + 1 AS n
                FROM {$prefijo}integros_tomafisica
            ");
            $n = (int) ($fila->n ?? 1);
            $refTry = 'TOMA-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);

            try {
                $idToma = DB::connection('mysql')->table('integros_tomafisica')->insertGetId([
                    'ref' => $refTry,
                    'fk_user' => $userId,
                    'idalmacen' => $idAlmacen,
                    'numero_toma' => $numToma,
                    'categorias_ids' => $catsIds,
                    'categorias' => $catsLabels,
                    'total_productos' => $totales['contados'],
                    'total_toma' => $totales['total_toma'],
                    'costo_total' => $totales['costo_total'],
                    'perdida' => $totales['perdida'],
                    'fecha' => $ahora,
                    'date_creation' => $ahora,
                ]);
                $ref = $refTry;
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($ref === '') {
            abort(500, 'No se pudo generar el consecutivo de la toma física.');
        }

        $lineas = DB::connection('mysql')->table('integros_tomafisica_det')
            ->where('fk_user', $userId)
            ->where('idalmacen', $idAlmacen)
            ->where('fk_tomafisica', 0)
            ->where('numero_toma', $numToma)
            ->update(['fk_tomafisica' => $idToma]);

        DB::connection('mysql')->table('integros_tomafisica_noencontrados')
            ->where('fk_user', $userId)
            ->where('idalmacen', $idAlmacen)
            ->where('fk_tomafisica', 0)
            ->where('numero_toma', $numToma)
            ->update(['fk_tomafisica' => $idToma]);

        // Movimientos de stock: correccion por cada linea con diferencia <> 0 (sin lote)
        $labelMov = "Correccion de Stock por toma física nro {$ref}";

        $porAjustar = DB::connection('mysql')->table('integros_tomafisica_det as tf')
            ->join('product as pr', 'pr.rowid', '=', 'tf.idproducto')
            ->where('tf.fk_tomafisica', $idToma)
            ->where('tf.diferencia', '<>', 0)
            ->select('tf.idproducto', 'tf.diferencia', 'tf.precio_compra', 'pr.ref as product_ref', 'pr.tobatch')
            ->get();

        $movsOk = 0;
        $omitidosLote = [];
        $movErrores = [];

        foreach ($porAjustar as $linea) {
            if ((int) $linea->tobatch > 0) {
                // Requiere lote: no se puede ajustar automaticamente
                $omitidosLote[] = $linea->product_ref;
                continue;
            }

            try {
                $this->ajustarStock($userId, $idAlmacen, (int) $linea->idproducto, (float) $linea->diferencia, (float) $linea->precio_compra, $labelMov, $ref);
                $movsOk++;
            } catch (\Throwable $e) {
                $movErrores[] = $linea->product_ref;
            }
        }

        $mensaje = "Toma {$ref} cerrada con {$lineas} producto(s). Movimientos de stock generados: {$movsOk}.";
        if ($omitidosLote) {
            $mensaje .= ' Omitidos por requerir lote: '.count($omitidosLote).'.';
        }
        if ($movErrores) {
            $mensaje .= ' Con error: '.count($movErrores).'.';
        }

        return [
            'success' => true,
            'idToma' => (int) $idToma,
            'ref' => $ref,
            'lineas' => $lineas,
            'movimientos' => $movsOk,
            'omitidos_lote' => array_slice($omitidosLote, 0, 30),
            'mov_errores' => array_slice($movErrores, 0, 30),
            'totales' => $totales,
            'message' => $mensaje,
        ];
    }

    private function numeroToma(Request $request): int
    {
        $n = (int) $request->input('numero_toma', 1);

        return $n > 0 ? $n : 1;
    }

    /**
     * Totales de las lineas CONTADAS de la toma en curso (precio congelado
     * por linea). Replica totalesCierre().
     */
    private function totalesCierre(int $idAlmacen, int $userId, int $numToma): array
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $fila = DB::connection('mysql')->selectOne("
            SELECT
                COUNT(*) AS contados,
                COALESCE(SUM(tomafisica), 0) AS total_toma,
                COALESCE(SUM(diferencia), 0) AS dif_qty,
                COALESCE(SUM(tomafisica * precio_compra), 0) AS costo_total,
                COALESCE(SUM(diferencia * precio_compra), 0) AS perdida,
                COALESCE(SUM(CASE WHEN diferencia > 0 THEN diferencia * precio_compra ELSE 0 END), 0) AS faltante_valor,
                COALESCE(SUM(CASE WHEN diferencia < 0 THEN -diferencia * precio_compra ELSE 0 END), 0) AS sobrante_valor
            FROM {$prefijo}integros_tomafisica_det
            WHERE fk_user = ? AND idalmacen = ? AND fk_tomafisica = 0 AND numero_toma = ?
        ", [$userId, $idAlmacen, $numToma]);

        return [
            'contados' => (int) ($fila->contados ?? 0),
            'total_toma' => (float) ($fila->total_toma ?? 0),
            'dif_qty' => (float) ($fila->dif_qty ?? 0),
            'costo_total' => (float) ($fila->costo_total ?? 0),
            'perdida' => (float) ($fila->perdida ?? 0),
            'faltante_valor' => (float) ($fila->faltante_valor ?? 0),
            'sobrante_valor' => (float) ($fila->sobrante_valor ?? 0),
        ];
    }

    /**
     * Replica Product::correct_stock() + MouvementStock::_create() para
     * productos SIN lote (los de lote ya se filtran antes de llamar esto):
     * inserta el movimiento, actualiza product_stock.reel, recalcula el PMP
     * (solo en entradas, con precio > 0) y el stock denormalizado del
     * producto, y limpia el registro de product_stock si quedo en 0.
     *
     * diferencia > 0 (faltante fisico, existencia > lo contado) -> SALIDA.
     * diferencia < 0 (sobrante, lo contado > existencia) -> ENTRADA.
     */
    private function ajustarStock(int $userId, int $idAlmacen, int $idProducto, float $diferencia, float $precioCompra, string $label, string $inventoryCode): void
    {
        $sens = $diferencia > 0 ? 1 : 0; // 1 = salida, 0 = entrada
        $qty = $sens === 0 ? abs($diferencia) : -abs($diferencia);
        $prefijo = DB::connection('mysql')->getTablePrefix();
        $ahora = now()->format('Y-m-d H:i:s');

        DB::connection('mysql')->transaction(function () use ($prefijo, $ahora, $idProducto, $idAlmacen, $qty, $sens, $precioCompra, $label, $inventoryCode, $userId) {
            $mvid = DB::connection('mysql')->table('stock_mouvement')->insertGetId([
                'datem' => $ahora,
                'fk_product' => $idProducto,
                'batch' => null,
                'eatby' => null,
                'sellby' => null,
                'fk_entrepot' => $idAlmacen,
                'value' => $qty,
                'type_mouvement' => $sens,
                'fk_user_author' => $userId,
                'label' => $label,
                'inventorycode' => $inventoryCode,
                'price' => $precioCompra,
                'fk_origin' => 0,
                'origintype' => '',
                'fk_projet' => 0,
            ]);

            // load_stock('novirtual') de Dolibarr NO confia en la columna
            // denormalizada product.stock (puede estar desactualizada):
            // recalcula sumando product_stock.reel en tiempo real.
            $producto = DB::connection('mysql')->table('product')
                ->where('rowid', $idProducto)
                ->select('pmp')
                ->lockForUpdate()
                ->first();
            $oldQty = (float) (DB::connection('mysql')->table('product_stock')
                ->where('fk_product', $idProducto)
                ->lockForUpdate()
                ->sum('reel'));
            $oldPmp = (float) ($producto->pmp ?? 0);

            $filaStock = DB::connection('mysql')->table('product_stock')
                ->where('fk_entrepot', $idAlmacen)
                ->where('fk_product', $idProducto)
                ->select('rowid', 'reel')
                ->lockForUpdate()
                ->first();

            // PMP solo se recalcula en entradas (sens=0) con precio > 0;
            // en salidas el PMP no cambia.
            $newPmp = $oldPmp;
            if ($sens === 0 && $precioCompra > 0) {
                $oldQtyTouse = $oldQty >= 0 ? $oldQty : 0;
                $newPmp = ($oldPmp > 0 && ($oldQtyTouse + $qty) != 0)
                    ? (($oldQtyTouse * $oldPmp) + ($qty * $precioCompra)) / ($oldQtyTouse + $qty)
                    : $precioCompra;
            }

            if ($filaStock) {
                DB::connection('mysql')->table('product_stock')
                    ->where('rowid', $filaStock->rowid)
                    ->update(['reel' => (float) $filaStock->reel + $qty]);
            } else {
                DB::connection('mysql')->table('product_stock')->insert([
                    'reel' => $qty,
                    'fk_entrepot' => $idAlmacen,
                    'fk_product' => $idProducto,
                ]);
            }

            DB::connection('mysql')->statement("
                UPDATE {$prefijo}product as p SET pmp = ?,
                    stock = (SELECT SUM(ps.reel) FROM {$prefijo}product_stock as ps WHERE ps.fk_product = p.rowid)
                WHERE rowid = ?
            ", [$newPmp, $idProducto]);

            DB::connection('mysql')->table('product_stock')
                ->where('fk_product', $idProducto)
                ->where('fk_entrepot', $idAlmacen)
                ->where('reel', 0)
                ->delete();

            DB::connection('mysql')->table('stock_mouvement_extrafields')->updateOrInsert(
                ['fk_object' => $mvid],
                ['tipo_causa' => 'correccion']
            );
        });
    }

    /**
     * Existencia actual (product_stock) y precio de compra vigente (ultimo
     * precio proveedor -> costo -> PMP), que se congela en la linea.
     */
    private function existenciaYPrecio(int $idProducto, int $idAlmacen): array
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $fila = DB::connection('mysql')->selectOne("
            SELECT COALESCE(ps.reel, 0) AS reel,
                   COALESCE(
                       NULLIF((SELECT pfp.unitprice FROM {$prefijo}product_fournisseur_price pfp
                               WHERE pfp.fk_product = p.rowid ORDER BY pfp.tms DESC LIMIT 1), 0),
                       NULLIF(p.cost_price, 0),
                       NULLIF(p.pmp, 0),
                       0
                   ) AS precio
            FROM {$prefijo}product p
            LEFT JOIN {$prefijo}product_stock ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ?
            WHERE p.rowid = ?
        ", [$idAlmacen, $idProducto]);

        return [(float) ($fila->reel ?? 0), (float) ($fila->precio ?? 0)];
    }

    /**
     * Valor actual de la OTRA toma pendiente para un producto (otros
     * usuarios). Null si nadie lo ha contado en la otra toma.
     */
    private function tomaOtraProducto(int $idProducto, int $idAlmacen, int $userId, int $numToma): ?float
    {
        $otro = $numToma === 1 ? 2 : 1;
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $fila = DB::connection('mysql')->selectOne("
            SELECT SUM(tomafisica) AS toma_otra
            FROM {$prefijo}integros_tomafisica_det
            WHERE idproducto = ? AND idalmacen = ? AND fk_tomafisica = 0 AND numero_toma = ? AND fk_user <> ?
        ", [$idProducto, $idAlmacen, $otro, $userId]);

        return $fila && $fila->toma_otra !== null ? (float) $fila->toma_otra : null;
    }

    /**
     * Dos usuarios no pueden contar el MISMO producto en el MISMO numero de
     * toma pendiente: login del otro usuario si ya lo conto ('' si no).
     */
    private function productoContadoPorOtro(int $idProducto, int $idAlmacen, int $userId, int $numToma): string
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $fila = DB::connection('mysql')->selectOne("
            SELECT COALESCE(u.login, CONCAT('usuario #', tf.fk_user)) AS login
            FROM {$prefijo}integros_tomafisica_det tf
            LEFT JOIN {$prefijo}user u ON u.rowid = tf.fk_user
            WHERE tf.idproducto = ? AND tf.idalmacen = ?
              AND tf.fk_tomafisica = 0 AND tf.numero_toma = ?
              AND tf.fk_user <> ?
            LIMIT 1
        ", [$idProducto, $idAlmacen, $numToma, $userId]);

        return $fila ? (string) $fila->login : '';
    }

    /**
     * Un usuario solo puede tener UNA toma pendiente por almacen: si tiene
     * lineas en curso con OTRO numero de toma, devuelve ese numero (0 si no).
     */
    private function otraTomaPendiente(int $idAlmacen, int $userId, int $numToma): int
    {
        $prefijo = DB::connection('mysql')->getTablePrefix();

        $fila = DB::connection('mysql')->selectOne("
            SELECT numero_toma FROM {$prefijo}integros_tomafisica_det
            WHERE fk_user = ? AND idalmacen = ?
              AND fk_tomafisica = 0 AND numero_toma <> ?
            LIMIT 1
        ", [$userId, $idAlmacen, $numToma]);

        return $fila ? (int) $fila->numero_toma : 0;
    }

    /**
     * PDF de una toma fisica ya cerrada (encabezado + lineas), para que la
     * app Android lo descargue y lo abra directo. Replica exportPdfToma(),
     * cambiando TCPDF (vendida dentro de Dolibarr) por dompdf ya que este
     * proyecto no depende del arbol de Dolibarr.
     *
     * @return array{success: bool, message?: string, filename?: string, contenido?: string}
     */
    public function generarPdfToma(int $idToma): array
    {
        if ($idToma <= 0) {
            return ['success' => false, 'message' => 'Toma inválida.'];
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();

        $toma = DB::connection('mysql')->selectOne("
            SELECT t.ref, t.estado, t.fecha, t.numero_toma, t.categorias, e.ref AS almacen,
                   TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) AS nombre, u.login
            FROM {$prefijo}integros_tomafisica t
            LEFT JOIN {$prefijo}entrepot e ON e.rowid = t.idalmacen
            LEFT JOIN {$prefijo}user u ON u.rowid = t.fk_user
            WHERE t.rowid = ?
        ", [$idToma]);

        if (!$toma) {
            return ['success' => false, 'message' => 'Toma no encontrada.'];
        }

        $lineas = DB::connection('mysql')->select("
            SELECT tf.tomafisica, tf.existencia, tf.diferencia, tf.precio_compra,
                   p.ref AS product_ref, p.label AS product_label,
                   (SELECT GROUP_CONCAT(c.label ORDER BY c.label SEPARATOR ', ')
                       FROM {$prefijo}categorie_product cp
                       INNER JOIN {$prefijo}categorie c ON c.rowid = cp.fk_categorie AND c.type = 0
                       WHERE cp.fk_product = p.rowid) AS categorias
            FROM {$prefijo}integros_tomafisica_det tf
            INNER JOIN {$prefijo}product p ON p.rowid = tf.idproducto
            WHERE tf.fk_tomafisica = ?
            ORDER BY p.label ASC
        ", [$idToma]);

        $dompdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($this->htmlPdfToma($toma, $lineas));
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return [
            'success' => true,
            'filename' => preg_replace('/[^A-Za-z0-9_-]/', '_', $toma->ref).'.pdf',
            'contenido' => $dompdf->output(),
        ];
    }

    /**
     * Numero formateado sin ceros decimales sobrantes (igual que el
     * rtrim(rtrim(number_format(...))) del legacy).
     */
    private function numeroSinCerosSobrantes(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
    }

    private function htmlPdfToma(object $toma, array $lineas): string
    {
        $estado = $toma->estado ?: 'cerrado';
        $estadoMayus = mb_strtoupper($estado);
        $colorEstado = $estado === 'anulado' ? '#B71C1C' : '#2E7D32';
        $ref = e($toma->ref);
        $almacen = e($toma->almacen ?? '');
        $usuario = e($toma->nombre ?: $toma->login);
        $fecha = $toma->fecha ? date('Y-m-d H:i', strtotime($toma->fecha)) : '';
        $categorias = e(mb_substr((string) ($toma->categorias ?: 'Todas'), 0, 70));

        $filas = '';
        foreach ($lineas as $linea) {
            $valor = (float) $linea->diferencia * (float) $linea->precio_compra;
            $filas .= '<tr>'
                .'<td>'.e(mb_substr((string) $linea->product_ref, 0, 16)).'</td>'
                .'<td>'.e(mb_substr((string) $linea->categorias, 0, 40)).'</td>'
                .'<td>'.e(mb_substr((string) $linea->product_label, 0, 60)).'</td>'
                .'<td class="num">'.$this->numeroSinCerosSobrantes((float) $linea->existencia).'</td>'
                .'<td class="num">'.$this->numeroSinCerosSobrantes((float) $linea->tomafisica).'</td>'
                .'<td class="num">'.$this->numeroSinCerosSobrantes((float) $linea->diferencia).'</td>'
                .'<td class="num">'.number_format($valor, 0, ',', '.').'</td>'
                .'</tr>';
        }

        return <<<HTML
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #282828; }
            .banner { background: #1F3B4D; color: #fff; padding: 8px; text-align: center; font-size: 14px; font-weight: bold; }
            .sub { background: #EFF5F4; color: #1F3B4D; text-align: center; padding: 4px; font-size: 9px; font-weight: bold; }
            .sub.estado { color: {$colorEstado}; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            th { background: #3BBFA8; color: #fff; padding: 4px; border: 1px solid #999; font-size: 9px; }
            td { border: 1px solid #999; padding: 3px; font-size: 8.5px; }
            td.num, th.num { text-align: right; }
        </style>
        </head>
        <body>
            <div class="banner">TOMA FÍSICA — {$ref}</div>
            <div class="sub">Almacén: {$almacen} &middot; Toma Nro: {$toma->numero_toma} &middot; Categorías: {$categorias}</div>
            <div class="sub estado">Usuario: {$usuario} &middot; Fecha: {$fecha} &middot; Estado: {$estadoMayus}</div>
            <table>
                <thead>
                    <tr>
                        <th>Ref.</th><th>Categoría</th><th>Producto</th>
                        <th class="num">Exist.</th><th class="num">Toma</th><th class="num">Dif.</th><th class="num">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    {$filas}
                </tbody>
            </table>
        </body>
        </html>
        HTML;
    }
}
