<?php

namespace App\Services\Productos;

use App\Models\DolibarrUser;
use App\Models\Producto;
use App\Services\Panel\FichaExtrafields;
use App\Services\Panel\PermisosDolibarr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lectura/escritura de la ficha de un producto o servicio (product,
 * product_price, product_barcode, categorie_product, product_extrafields)
 * para el modal de creación/edición del panel. Sigue las convenciones que ya
 * usa el POS (ProductosService::guardarProducto): códigos de barras en
 * product_barcode, historial de precios en product_price y plantilla
 * contable según la tarifa de IVA.
 */
class ProductoFichaService
{
    public const TIPO_PRODUCTO = 0;
    public const TIPO_SERVICIO = 1;

    /** Plantilla contable (product_extrafields.fk_plantilla_contable) por tarifa de IVA, igual que el POS. */
    private const PLANTILLA_CONTABLE_POR_IVA = [0 => 17, 5 => 16, 19 => 15];

    /**
     * Pasos del asistente: cada uno se valida y guarda por separado.
     */
    public const SECCIONES = [
        'general' => [
            'campos' => ['ref', 'label', 'description', 'tosell', 'tobuy', 'url', 'seuil_stock_alerte', 'desiredstock'],
            'grupos_ef' => [],
        ],
        'precios' => ['campos' => ['tva_tx', 'cost_price'], 'grupos_ef' => []],
        'codigos' => ['campos' => [], 'grupos_ef' => []],
        'categorias' => ['campos' => [], 'grupos_ef' => []],
        'tributario' => ['campos' => [], 'grupos_ef' => ['tributario']],
        'adicionales' => ['campos' => [], 'grupos_ef' => ['otros']],
        'notas' => ['campos' => ['note_public', 'note'], 'grupos_ef' => []],
    ];

    /**
     * Extrafields que van en el paso "Tributario y contable"; el resto
     * (opciones de venta en el POS, embalaje…) cae en "Campos adicionales".
     */
    private const EXTRAFIELDS_TRIBUTARIOS = [
        'fk_plantilla_contable', 'fk_centro_costos', 'categoria',
        'icui', 'ibua', 'icl', 'adv', 'al_consumo', 'otros_impuestos',
        'icui_estado', 'ibua_estado', 'icl_estado', 'adv_estado', 'al_consumo_estado', 'otros_impuestos_estado',
        'icui_porcentaje', 'ibua_porcentaje', 'icl_porcentaje', 'adv_porcentaje', 'consumo_porcentaje', 'otro_porcentaje',
        'base_retencion_venta', 'base_retencion_compra', 'tarifa_retencion_venta', 'tarifa_retencion_compra',
    ];

    public function __construct(
        private FichaExtrafields $extrafields,
        private PermisosDolibarr $permisos,
    ) {
    }

    /** "Create/modify products" o "Create/modify services" según el tipo. */
    public function puedeEditar(?DolibarrUser $usuario, int $tipo): bool
    {
        return $this->permisos->tiene($usuario, $tipo === self::TIPO_SERVICIO
            ? PermisosDolibarr::SERVICIOS_CREAR
            : PermisosDolibarr::PRODUCTOS_CREAR);
    }

    public function nuevo(int $tipo): Producto
    {
        $producto = new Producto;
        $producto->fk_product_type = $tipo;
        $producto->tosell = 1;
        $producto->tobuy = 1;
        $producto->tva_tx = 19;

        return $producto;
    }

    /**
     * @return array<string, mixed>
     */
    public function datosFicha(Producto $producto): array
    {
        $existe = $producto->exists;
        $id = $existe ? (int) $producto->rowid : null;
        $db = DB::connection('mysql');

        $extrafields = $this->extrafields->conValores('product', 'product_extrafields', $id);
        $grupos = ['tributario' => collect(), 'otros' => collect()];
        foreach ($extrafields as $def) {
            $grupos[$this->grupoDe($def->name)]->push($def);
        }

        return [
            'producto' => $producto,
            'esServicio' => (int) $producto->fk_product_type === self::TIPO_SERVICIO,
            'extrafieldsPorGrupo' => $grupos,
            'tarifasIva' => $this->tarifasIva(),
            'niveles' => $this->nivelesPrecio(),
            'preciosPorNivel' => $existe ? $this->preciosPorNivel($producto) : [],
            'historialPrecios' => $existe ? $this->historialPrecios($producto) : collect(),
            'codigosBarras' => $existe
                ? $db->table('product_barcode')->where('fk_product', $id)->orderBy('rowid')->pluck('barcode')->all()
                : [],
            'categorias' => $db->table('categorie')->where('type', 0)->whereNotNull('label')->where('label', '<>', '')
                ->orderBy('label')->get(['rowid', 'label', 'color', 'fk_parent'])
                ->map(fn ($c) => [
                    'id' => (int) $c->rowid,
                    'label' => $c->label,
                    'color' => $c->color ? '#'.ltrim($c->color, '#') : null,
                    'parent' => (int) $c->fk_parent ?: null,
                ]),
            'categoriasDe' => $existe
                ? $db->table('categorie_product')->where('fk_product', $id)->pluck('fk_categorie')->map(fn ($v) => (int) $v)->all()
                : [],
            'stock' => $existe ? $this->stock($producto) : null,
            'creador' => $this->nombreUsuario($producto->fk_user_author),
            'modificador' => $this->nombreUsuario($producto->fk_user_modif),
        ];
    }

    /**
     * Reglas solo de los campos del paso indicado.
     *
     * @return array<string, array<int, mixed>>
     */
    public function reglas(Producto $producto, string $seccion): array
    {
        $reglas = match ($seccion) {
            'general' => [
                'ref' => ['required', 'string', 'max:128', Rule::unique('mysql.product', 'ref')->ignore($producto->rowid, 'rowid')],
                'label' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'tosell' => ['required', 'in:0,1'],
                'tobuy' => ['required', 'in:0,1'],
                'url' => ['nullable', 'string', 'max:255'],
                'seuil_stock_alerte' => ['nullable', 'numeric', 'min:0'],
                'desiredstock' => ['nullable', 'numeric', 'min:0'],
            ],
            'precios' => [
                'tva_tx' => ['required', 'numeric', Rule::in($this->tarifasIva()->pluck('taux')->map(fn ($t) => (float) $t)->all())],
                'precios' => ['array'],
                'precios.1' => ['required', 'numeric', 'min:0'],
                'precios.*' => ['nullable', 'numeric', 'min:0'],
                'precio_min' => ['nullable', 'numeric', 'min:0', 'lte:precios.1'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],
            ],
            'codigos' => [
                'codigos_barras' => ['nullable', 'string'],
            ],
            'categorias' => [
                'categorias' => ['array'],
                'categorias.*' => ['integer'],
            ],
            'notas' => [
                'note_public' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
            ],
            default => [],
        };

        $grupos = self::SECCIONES[$seccion]['grupos_ef'];

        return $reglas + $this->extrafields->reglas('product', fn ($def) => in_array($this->grupoDe($def->name), $grupos, true));
    }

    /**
     * @return array<string, string>
     */
    public function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'unique' => 'Ya existe otro producto con esta :attribute.',
            'max' => 'El campo :attribute no puede superar :max caracteres.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'min' => 'El campo :attribute no puede ser negativo.',
            'in' => 'El valor de :attribute no es válido.',
            'precio_min.lte' => 'El precio mínimo no puede ser mayor que el precio de venta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function atributos(): array
    {
        return [
            'ref' => 'referencia', 'label' => 'nombre', 'tva_tx' => 'tarifa de IVA',
            'precios.1' => 'precio de venta', 'precios.2' => 'precio 2', 'precios.3' => 'precio 3',
            'precio_min' => 'precio mínimo', 'cost_price' => 'costo',
            'seuil_stock_alerte' => 'stock mínimo', 'desiredstock' => 'stock deseado',
        ] + $this->extrafields->atributos('product');
    }

    /**
     * Códigos de barras del textarea (uno por línea, también acepta comas),
     * sin vacíos ni repetidos, con sus errores de validación.
     *
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    public function codigosDesdeTexto(?string $texto, Producto $producto): array
    {
        $codigos = collect(preg_split('/[\r\n,;]+/', (string) $texto))
            ->map(fn ($c) => trim($c))
            ->filter()
            ->unique()
            ->values();

        $errores = [];
        $largos = $codigos->filter(fn ($c) => mb_strlen($c) > 20);
        if ($largos->isNotEmpty()) {
            $errores['codigos_barras'] = 'Cada código puede tener máximo 20 caracteres: '.$largos->implode(', ');
        }

        $enUso = DB::connection('mysql')->table('product_barcode as b')
            ->join('product as p', 'p.rowid', '=', 'b.fk_product')
            ->whereIn('b.barcode', $codigos->all())
            ->when($producto->exists, fn ($q) => $q->where('b.fk_product', '<>', $producto->rowid))
            ->get(['b.barcode', 'p.ref']);
        if ($enUso->isNotEmpty() && empty($errores)) {
            $errores['codigos_barras'] = 'Ya están en otro producto: '
                .$enUso->map(fn ($r) => "{$r->barcode} ({$r->ref})")->implode(', ');
        }

        return [$codigos->all(), $errores];
    }

    public function crear(int $tipo, array $datos, DolibarrUser $usuario): Producto
    {
        return DB::connection('mysql')->transaction(function () use ($tipo, $datos, $usuario) {
            $producto = $this->nuevo($tipo);
            $this->asignarCampos($producto, 'general', $datos);

            $producto->entity = 1;
            $producto->datec = now()->format('Y-m-d H:i:s');
            $producto->fk_user_author = $usuario->rowid;
            $producto->fk_user_modif = $usuario->rowid;
            $producto->price = 0;
            $producto->price_ttc = 0;
            $producto->price_base_type = 'TTC';
            $producto->save();

            // La fila de extrafields siempre existe (el POS la consulta y la actualiza).
            $this->extrafields->guardar('product', 'product_extrafields', (int) $producto->rowid, [], fn () => false, [
                'producto_publicado' => 1,
                'fk_plantilla_contable' => self::PLANTILLA_CONTABLE_POR_IVA[19] ?? null,
            ]);

            return $producto;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  datos validados del paso
     * @param  array<int, string>  $codigos  ya depurados (solo paso "codigos")
     */
    public function guardar(Producto $producto, string $seccion, array $datos, array $extrafields, array $codigos, DolibarrUser $usuario): void
    {
        DB::connection('mysql')->transaction(function () use ($producto, $seccion, $datos, $extrafields, $codigos, $usuario) {
            match ($seccion) {
                'precios' => $this->guardarPrecios($producto, $datos, $usuario),
                'codigos' => $this->guardarCodigos($producto, $codigos),
                'categorias' => $this->guardarCategorias($producto, $datos['categorias'] ?? []),
                default => $this->asignarCampos($producto, $seccion, $datos),
            };

            $producto->fk_user_modif = $usuario->rowid;
            $producto->save();

            $grupos = self::SECCIONES[$seccion]['grupos_ef'];
            if ($grupos) {
                $this->extrafields->guardar('product', 'product_extrafields', (int) $producto->rowid, $extrafields,
                    fn ($def) => in_array($this->grupoDe($def->name), $grupos, true));
            }
        });
    }

    private function asignarCampos(Producto $producto, string $seccion, array $datos): void
    {
        foreach (self::SECCIONES[$seccion]['campos'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $producto->{$campo} = $datos[$campo] === '' ? null : $datos[$campo];
            }
        }
    }

    /**
     * Cada nivel con precio distinto al vigente (o si cambió el IVA) agrega una
     * fila a product_price, como hace Dolibarr; el nivel 1 además queda como
     * precio base del producto. Los precios se digitan con IVA incluido.
     */
    private function guardarPrecios(Producto $producto, array $datos, DolibarrUser $usuario): void
    {
        $iva = (float) $datos['tva_tx'];
        $cambioIva = (float) $producto->tva_tx !== $iva;
        $vigentes = $this->preciosPorNivel($producto);
        $multiprecios = !empty($this->nivelesPrecio());
        $precioMinTtc = isset($datos['precio_min']) && $datos['precio_min'] !== '' ? (float) $datos['precio_min'] : 0.0;
        $sinIva = fn (float $ttc) => round($ttc / (1 + $iva / 100), 8);

        foreach ($datos['precios'] ?? [] as $nivel => $valor) {
            $nivel = (int) $nivel;
            if ($valor === null || $valor === '' || $nivel < 1 || (!$multiprecios && $nivel > 1)) {
                continue;
            }
            $ttc = (float) $valor;
            $minTtc = $nivel === 1 ? $precioMinTtc : (float) ($vigentes[$nivel]->price_min_ttc ?? 0);
            $actual = $vigentes[$nivel] ?? null;
            $igual = $actual && (float) $actual->price_ttc === $ttc && (float) $actual->price_min_ttc === $minTtc;
            if ($igual && !$cambioIva) {
                continue;
            }

            DB::connection('mysql')->table('product_price')->insert([
                'entity' => 1,
                'fk_product' => $producto->rowid,
                'date_price' => now(),
                'price_level' => $multiprecios ? $nivel : 0,
                'price' => $sinIva($ttc),
                'price_ttc' => $ttc,
                'price_min' => $sinIva($minTtc),
                'price_min_ttc' => $minTtc,
                'price_base_type' => 'TTC',
                'tva_tx' => $iva,
                'fk_user_author' => $usuario->rowid,
                'tosell' => (int) $producto->tosell,
            ]);

            if ($nivel === 1) {
                $producto->price = $sinIva($ttc);
                $producto->price_ttc = $ttc;
                $producto->price_min = $sinIva($minTtc);
                $producto->price_min_ttc = $minTtc;
                $producto->price_base_type = 'TTC';
            }
        }

        $producto->tva_tx = $iva;
        $producto->cost_price = isset($datos['cost_price']) && $datos['cost_price'] !== '' ? (float) $datos['cost_price'] : null;

        // Igual que el POS: la plantilla contable sigue a la tarifa de IVA.
        $plantilla = self::PLANTILLA_CONTABLE_POR_IVA[(int) $iva] ?? null;
        if ($plantilla !== null) {
            DB::connection('mysql')->table('product_extrafields')
                ->updateOrInsert(['fk_object' => $producto->rowid], ['fk_plantilla_contable' => $plantilla]);
        }
    }

    /**
     * @param  array<int, string>  $codigos
     */
    private function guardarCodigos(Producto $producto, array $codigos): void
    {
        $db = DB::connection('mysql');
        $db->table('product_barcode')->where('fk_product', $producto->rowid)->whereNotIn('barcode', $codigos)->delete();

        $existentes = $db->table('product_barcode')->where('fk_product', $producto->rowid)->pluck('barcode')->all();
        foreach (array_diff($codigos, $existentes) as $codigo) {
            $db->table('product_barcode')->insert([
                'fk_product' => $producto->rowid,
                'barcode' => $codigo,
                'datec' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function guardarCategorias(Producto $producto, array $ids): void
    {
        $db = DB::connection('mysql');
        $validos = $db->table('categorie')->where('type', 0)->whereIn('rowid', array_map('intval', $ids))->pluck('rowid')->all();

        $db->table('categorie_product')->where('fk_product', $producto->rowid)->whereNotIn('fk_categorie', $validos)->delete();
        $actuales = $db->table('categorie_product')->where('fk_product', $producto->rowid)->pluck('fk_categorie')->all();
        foreach (array_diff($validos, $actuales) as $id) {
            $db->table('categorie_product')->insert(['fk_categorie' => $id, 'fk_product' => $producto->rowid]);
        }
    }

    private function grupoDe(string $nombreExtrafield): string
    {
        return in_array($nombreExtrafield, self::EXTRAFIELDS_TRIBUTARIOS, true) ? 'tributario' : 'otros';
    }

    /**
     * Precio vigente (última fila de product_price) por nivel. Sin
     * multiprecios Dolibarr guarda el nivel 0, que aquí se trata como el 1.
     *
     * @return array<int, object>
     */
    private function preciosPorNivel(Producto $producto): array
    {
        $vigentes = [];
        DB::connection('mysql')->table('product_price')
            ->where('fk_product', $producto->rowid)
            ->orderByDesc('date_price')
            ->orderByDesc('rowid')
            ->get(['price_level', 'price', 'price_ttc', 'price_min_ttc', 'date_price'])
            ->each(function ($fila) use (&$vigentes) {
                $vigentes[max(1, (int) $fila->price_level)] ??= $fila;
            });

        // Productos antiguos sin historial: el precio base del producto es el nivel 1.
        $vigentes[1] ??= (object) [
            'price_ttc' => $producto->price_ttc, 'price_min_ttc' => $producto->price_min_ttc, 'date_price' => null,
        ];

        return $vigentes;
    }

    private function historialPrecios(Producto $producto): Collection
    {
        return DB::connection('mysql')->table('product_price as pp')
            ->leftJoin('user as u', 'u.rowid', '=', 'pp.fk_user_author')
            ->where('pp.fk_product', $producto->rowid)
            ->orderByDesc('pp.date_price')
            ->orderByDesc('pp.rowid')
            ->limit(8)
            ->get(['pp.date_price', 'pp.price_level', 'pp.price_ttc', 'pp.tva_tx', 'u.login']);
    }

    /**
     * Tarifas de IVA activas para Colombia (c_tva), sin repetir la tarifa.
     */
    private function tarifasIva(): Collection
    {
        return DB::connection('mysql')->table('c_tva')
            ->where('fk_pays', 70)
            ->where('active', 1)
            ->orderByDesc('taux')
            ->get(['taux', 'note'])
            ->unique(fn ($t) => (float) $t->taux)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function nivelesPrecio(): array
    {
        $db = DB::connection('mysql');
        if ((int) $db->table('const')->where('name', 'PRODUIT_MULTIPRICES')->value('value') !== 1) {
            return [];
        }

        $limite = max(1, (int) $db->table('const')->where('name', 'PRODUIT_MULTIPRICES_LIMIT')->value('value'));
        $niveles = [];
        for ($i = 1; $i <= $limite; $i++) {
            $niveles[$i] = "Precio $i";
        }

        return $niveles;
    }

    /**
     * @return array{total: float, bodegas: Collection}
     */
    private function stock(Producto $producto): array
    {
        $bodegas = DB::connection('mysql')->table('product_stock as ps')
            ->leftJoin('entrepot as e', 'e.rowid', '=', 'ps.fk_entrepot')
            ->where('ps.fk_product', $producto->rowid)
            ->orderBy('e.ref')
            ->get(['e.ref', 'e.lieu', 'ps.reel']);

        return ['total' => (float) $bodegas->sum('reel'), 'bodegas' => $bodegas];
    }

    private function nombreUsuario(mixed $id): ?string
    {
        if (!$id) {
            return null;
        }

        $u = DB::connection('mysql')->table('user')->where('rowid', $id)->first(['firstname', 'lastname', 'login']);

        return $u ? (trim("{$u->firstname} {$u->lastname}") ?: $u->login) : null;
    }
}
