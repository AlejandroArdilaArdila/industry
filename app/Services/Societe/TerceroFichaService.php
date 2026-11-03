<?php

namespace App\Services\Societe;

use App\Models\DolibarrUser;
use App\Models\Sociedad;
use App\Services\Panel\FichaExtrafields;
use App\Services\Panel\ActividadesService;
use App\Services\Panel\PanelDashboardService;
use App\Services\Panel\PermisosDolibarr;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lectura/escritura de la ficha de un tercero (societe + societe_extrafields)
 * para el modal de creación/edición del panel. Respeta las convenciones de esta
 * instalación de Dolibarr: siren = número de documento, siret = tipo de
 * documento (NIT/CC/...), ape = código CIIU, town = rowid de c_ciudades_fe y
 * zip = código DANE de esa ciudad (igual que ClientesService). Las categorías
 * van en categorie_societe (de cliente, type 2) y categorie_fournisseur (de
 * proveedor, type 1).
 */
class TerceroFichaService
{
    private const PAIS_COLOMBIA = 70;

    /**
     * Pasos del asistente: cada uno se valida y guarda por separado, así que
     * solo se tocan las columnas de societe y los grupos de extrafields del
     * paso enviado (clave para no poner en 0 booleanos de otros pasos).
     */
    public const SECCIONES = [
        'identificacion' => [
            'campos' => ['nom', 'name_alias', 'siret', 'siren', 'ape', 'fk_typent', 'fk_forme_juridique',
                'client', 'fournisseur', 'code_client', 'code_fournisseur', 'status'],
            'grupos_ef' => [],
        ],
        'contacto' => [
            'campos' => ['address', 'fk_pays', 'fk_departement', 'town', 'phone', 'phone_mobile', 'fax', 'email', 'url'],
            'grupos_ef' => [],
        ],
        'comercial' => [
            'campos' => ['price_level', 'remise_client', 'mode_reglement', 'cond_reglement', 'outstanding_limit'],
            'grupos_ef' => ['credito'],
        ],
        'tributario' => [
            'campos' => ['tva_assuj'],
            'grupos_ef' => ['tributario'],
        ],
        // No toca columnas de societe: guarda los enlaces en las tablas de categorías.
        'categorias' => [
            'campos' => [],
            'grupos_ef' => [],
        ],
        'adicionales' => [
            'campos' => [],
            'grupos_ef' => ['otros'],
        ],
        'notas' => [
            'campos' => ['note_public', 'note_private'],
            'grupos_ef' => [],
        ],
    ];

    public const TIPOS_DOCUMENTO = [
        'NIT' => 'NIT',
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'TI' => 'Tarjeta de identidad',
        'PP' => 'Pasaporte',
        'PEP' => 'Permiso especial de permanencia',
        'NIE' => 'NIT de otro país',
    ];

    /**
     * Agrupación visual de los extrafields conocidos. Cualquier extrafield que
     * no esté aquí (p. ej. uno nuevo creado en Dolibarr) cae en "otros", así
     * que nunca se pierde del formulario.
     */
    /** Campo del formulario => [tipo de categoría Dolibarr, tabla de enlace]. */
    private const CATEGORIAS = [
        'categorias_cliente' => [2, 'categorie_societe'],
        'categorias_proveedor' => [1, 'categorie_fournisseur'],
    ];

    private const GRUPOS_EXTRAFIELDS = [
        'tributario' => ['clasificacion_tributaria', 'retefuente', 'reteica', 'reteiva'],
        'credito' => ['cupo_base', 'cupo_actual', 'dias_plazo_cupo', 'vender_sin_cupon', 'vender_con_facturas_vencidas'],
    ];

    public function __construct(
        private FichaExtrafields $extrafields,
        private PermisosDolibarr $permisos,
        private PanelDashboardService $dashboard,
    ) {
    }

    /** Permiso Dolibarr "Create and update third parties": cubre crear y editar. */
    public function puedeEditar(?DolibarrUser $usuario): bool
    {
        return $this->permisos->tiene($usuario, PermisosDolibarr::TERCEROS_CREAR);
    }

    /**
     * Tercero sin guardar con los valores por defecto del formulario de creación.
     */
    public function nuevo(): Sociedad
    {
        $tercero = new Sociedad;
        $tercero->client = 1;
        $tercero->fournisseur = 0;
        $tercero->status = 1;
        $tercero->tva_assuj = 1;
        $tercero->fk_pays = self::PAIS_COLOMBIA;

        return $tercero;
    }

    /**
     * Todo lo que necesita la vista del modal: el tercero, sus extrafields
     * (definición + valor + opciones) agrupados, catálogos y KPIs.
     *
     * @return array<string, mixed>
     */
    public function datosFicha(Sociedad $tercero): array
    {
        $extrafields = $this->extrafields->conValores('societe', 'societe_extrafields', $tercero->exists ? (int) $tercero->rowid : null);

        $grupos = ['tributario' => collect(), 'credito' => collect(), 'otros' => collect()];
        foreach ($extrafields as $def) {
            $grupos[$this->grupoDe($def->name)]->push($def);
        }

        return [
            'tercero' => $tercero,
            'extrafieldsPorGrupo' => $grupos,
            'tiposDocumento' => self::TIPOS_DOCUMENTO,
            'tiposTercero' => $this->catalogo('c_typent', 'id', 'libelle'),
            'formasJuridicas' => $this->formasJuridicas(),
            'paises' => $this->catalogo('c_country', 'rowid', 'label', 'label'),
            'departamentos' => $this->departamentosColombia(),
            'ciudades' => $this->ciudades(),
            'ciius' => $this->ciius(),
            'catalogosFaltantes' => $this->catalogosFaltantes(),
            'mediosPago' => $this->catalogo('c_paiement', 'id', 'libelle'),
            'terminosPago' => $this->catalogo('c_payment_term', 'rowid', 'libelle', 'sortorder'),
            'nivelesPrecio' => $this->nivelesPrecio(),
            'categoriasCliente' => $this->categoriasDeTipo(2),
            'categoriasProveedor' => $this->categoriasDeTipo(1),
            'categoriasClienteDe' => $this->categoriasAsignadas($tercero, 'categorie_societe'),
            'categoriasProveedorDe' => $this->categoriasAsignadas($tercero, 'categorie_fournisseur'),
            'kpis' => $tercero->exists ? $this->kpis($tercero) : null,
            'actividad' => $tercero->exists ? $this->actividadComercial($tercero) : null,
            'creador' => $this->nombreUsuario($tercero->fk_user_creat),
            'modificador' => $this->nombreUsuario($tercero->fk_user_modif),
        ];
    }

    /**
     * Reglas solo de los campos del paso indicado.
     *
     * @return array<string, array<int, mixed>>
     */
    public function reglas(Sociedad $tercero, string $seccion): array
    {
        if ($seccion === 'categorias') {
            return collect(self::CATEGORIAS)->keys()
                ->flatMap(fn ($campo) => [$campo => ['array'], "$campo.*" => ['integer']])
                ->all();
        }

        $campos = self::SECCIONES[$seccion]['campos'];

        return array_filter(
            $this->todasLasReglas($tercero),
            function ($clave) use ($campos, $seccion) {
                if (str_starts_with($clave, 'ef.')) {
                    return in_array($this->grupoDe(substr($clave, 3)), self::SECCIONES[$seccion]['grupos_ef'], true);
                }

                return in_array($clave, $campos, true);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function todasLasReglas(Sociedad $tercero): array
    {
        $reglas = [
            'nom' => ['required', 'string', 'max:128'],
            'name_alias' => ['nullable', 'string', 'max:128'],
            'siret' => ['required', Rule::in(array_keys(self::TIPOS_DOCUMENTO))],
            'siren' => [
                'required', 'string', 'max:128',
                Rule::unique('mysql.societe', 'siren')->ignore($tercero->rowid, 'rowid'),
            ],
            'ape' => ['nullable', 'string', 'max:128'],
            'fk_typent' => ['nullable', 'integer'],
            'fk_forme_juridique' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
            'fk_pays' => ['nullable', 'integer'],
            'fk_departement' => ['nullable', 'integer'],
            'town' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_mobile' => ['nullable', 'string', 'max:30'],
            'fax' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:128'],
            'url' => ['nullable', 'string', 'max:255'],
            'client' => ['required', 'in:0,1,2,3'],
            'fournisseur' => ['required', 'in:0,1'],
            'code_client' => [
                'nullable', 'string', 'max:24',
                Rule::unique('mysql.societe', 'code_client')->ignore($tercero->rowid, 'rowid'),
            ],
            'code_fournisseur' => [
                'nullable', 'string', 'max:24',
                Rule::unique('mysql.societe', 'code_fournisseur')->ignore($tercero->rowid, 'rowid'),
            ],
            'status' => ['required', 'in:0,1'],
            'price_level' => ['nullable', 'integer', 'min:1'],
            'remise_client' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mode_reglement' => ['nullable', 'integer'],
            'cond_reglement' => ['nullable', 'integer'],
            'outstanding_limit' => ['nullable', 'numeric', 'min:0'],
            'tva_assuj' => ['required', 'in:0,1'],
            'note_public' => ['nullable', 'string'],
            'note_private' => ['nullable', 'string'],
        ];

        return $reglas + $this->extrafields->reglas('societe', fn () => true);
    }

    /**
     * Mensajes en español solo para este formulario (la app sigue en locale
     * 'en' para no alterar los mensajes que consumen POS/app por la API).
     *
     * @return array<string, string>
     */
    public function mensajes(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'unique' => 'Ya existe otro tercero con este :attribute.',
            'email' => 'Escribe un correo válido.',
            'max' => 'El campo :attribute no puede superar :max caracteres.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'remise_client.max' => 'El descuento no puede superar el 100 %.',
            'min' => 'El campo :attribute no puede ser negativo.',
            'in' => 'El valor de :attribute no es válido.',
            'array' => 'El valor de :attribute no es válido.',
            'categorias_cliente.*.integer' => 'Una de las categorías de cliente no es válida.',
            'categorias_proveedor.*.integer' => 'Una de las categorías de proveedor no es válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function atributos(): array
    {
        $atributos = [
            'nom' => 'razón social', 'name_alias' => 'alias', 'siret' => 'tipo de documento',
            'siren' => 'número de documento', 'email' => 'correo', 'code_client' => 'código de cliente',
            'code_fournisseur' => 'código de proveedor', 'remise_client' => 'descuento',
            'outstanding_limit' => 'límite de crédito', 'categorias_cliente' => 'categorías de cliente',
            'categorias_proveedor' => 'categorías de proveedor',
        ];

        return $atributos + $this->extrafields->atributos('societe');
    }

    public function guardar(Sociedad $tercero, string $seccion, array $datos, array $extrafields, DolibarrUser $usuario): void
    {
        DB::connection('mysql')->transaction(function () use ($tercero, $seccion, $datos, $extrafields, $usuario) {
            if ($seccion === 'categorias') {
                $this->guardarCategorias($tercero, $datos);
            }
            $this->asignarCampos($tercero, $seccion, $datos);
            $tercero->fk_user_modif = $usuario->rowid;
            $tercero->save();

            $this->guardarExtrafields($tercero, $extrafields, self::SECCIONES[$seccion]['grupos_ef']);
        });
    }

    /**
     * Crea el tercero con los datos del primer paso (identificación) y los
     * mismos valores por defecto que usa la creación desde el POS
     * (ClientesService::guardarCliente). Si los códigos de cliente/proveedor
     * vienen vacíos se generan como lo haría Dolibarr (mod_codeclient_monkey).
     */
    public function crear(array $datos, array $extrafields, DolibarrUser $usuario): Sociedad
    {
        return DB::connection('mysql')->transaction(function () use ($datos, $extrafields, $usuario) {
            // clasificacion_tributaria es NOT NULL: sin un "Consumidor final" ni el catálogo, es mejor avisar que dejar caer un error de SQL.
            if ($this->clasificacionTributariaPorDefecto() === null) {
                throw ValidationException::withMessages(['nom' => 'No se puede crear el tercero: a la base de datos le falta la clasificación tributaria (tabla c_clasificacion_tributaria o columna societe_extrafields.clasificacion_tributaria).']);
            }

            $tercero = $this->nuevo();
            $this->asignarCampos($tercero, 'identificacion', $datos);

            $tercero->entity = 1;
            $tercero->statut = 0;
            $tercero->fk_account = 0;
            $tercero->fk_stcomm = 0;
            $tercero->fk_incoterms = 0;
            $tercero->fk_multicurrency = 0;
            $tercero->datec = now()->format('Y-m-d H:i:s');
            $tercero->fk_user_creat = $usuario->rowid;
            $tercero->fk_user_modif = $usuario->rowid;

            if (in_array((int) $tercero->client, [1, 2, 3], true) && blank($tercero->code_client)) {
                $tercero->code_client = $this->siguienteCodigo('code_client', 'CU');
            }
            if ((int) $tercero->fournisseur === 1 && blank($tercero->code_fournisseur)) {
                $tercero->code_fournisseur = $this->siguienteCodigo('code_fournisseur', 'SU');
            }

            $tercero->save();

            // La fila de extrafields siempre existe (el POS la consulta y la actualiza).
            // clasificacion_tributaria es NOT NULL sin default: arranca con la del
            // "Consumidor final", igual que la creación desde el POS, y se ajusta
            // en el paso Tributario.
            $this->extrafields->guardar('societe', 'societe_extrafields', (int) $tercero->rowid, $extrafields,
                fn ($def) => in_array($this->grupoDe($def->name), self::SECCIONES['identificacion']['grupos_ef'], true),
                [
                    'por_actualizar' => 0,
                    'clasificacion_tributaria' => $this->clasificacionTributariaPorDefecto(),
                ]);

            return $tercero;
        });
    }

    private function clasificacionTributariaPorDefecto(): ?string
    {
        $db = DB::connection('mysql');

        try {
            return $db->table('societe as s')
                ->join('societe_extrafields as e', 'e.fk_object', '=', 's.rowid')
                ->where('s.nom', 'Consumidor final')
                ->value('e.clasificacion_tributaria')
                ?? ($this->existeTabla('c_clasificacion_tributaria') ? $db->table('c_clasificacion_tributaria')->orderBy('rowid')->value('rowid') : null);
        } catch (QueryException) {
            return null; // la base no tiene la columna societe_extrafields.clasificacion_tributaria
        }
    }

    private function asignarCampos(Sociedad $tercero, string $seccion, array $datos): void
    {
        foreach (self::SECCIONES[$seccion]['campos'] as $campo) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }
            $valor = $datos[$campo];
            $tercero->{$campo} = ($valor === '' ? null : $valor);
        }

        // zip = código DANE de la ciudad elegida (convención de esta instalación).
        if (!empty($datos['town']) && $this->existeTabla('c_ciudades_fe')) {
            $codigo = DB::connection('mysql')->table('c_ciudades_fe')->where('rowid', $datos['town'])->value('code');
            if ($codigo) {
                $tercero->zip = $codigo;
            }
        }
    }

    /**
     * Siguiente código con la máscara de mod_codeclient_monkey de Dolibarr:
     * PREFIJO + aamm + "-" + consecutivo global de 5 dígitos (CU2610-00042).
     */
    private function siguienteCodigo(string $campo, string $prefijo): string
    {
        $db = DB::connection('mysql');
        $max = (int) $db->table('societe')
            ->where($campo, 'like', $prefijo.'____-%')
            ->selectRaw('MAX(CAST(SUBSTRING('.$campo.' FROM '.(strlen($prefijo) + 6).') AS SIGNED)) AS max')
            ->value('max');

        return $prefijo.now()->format('ym').'-'.sprintf('%05d', $max + 1);
    }

    /**
     * Deja exactamente las categorías marcadas de cada tipo (solo ids válidos
     * de ese tipo; un campo ausente = ninguna marcada).
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarCategorias(Sociedad $tercero, array $datos): void
    {
        $db = DB::connection('mysql');

        foreach (self::CATEGORIAS as $campo => [$tipo, $tabla]) {
            $ids = array_map('intval', $datos[$campo] ?? []);
            $validos = $ids ? $db->table('categorie')->where('type', $tipo)->whereIn('rowid', $ids)->pluck('rowid')->map(fn ($v) => (int) $v)->all() : [];

            $db->table($tabla)->where('fk_soc', $tercero->rowid)->whereNotIn('fk_categorie', $validos)->delete();
            $actuales = $db->table($tabla)->where('fk_soc', $tercero->rowid)->pluck('fk_categorie')->map(fn ($v) => (int) $v)->all();
            foreach (array_diff($validos, $actuales) as $id) {
                $db->table($tabla)->insert(['fk_categorie' => $id, 'fk_soc' => $tercero->rowid]);
            }
        }
    }

    /**
     * @return Collection<int, array{id: int, label: string, color: ?string, parent: ?int}>
     */
    private function categoriasDeTipo(int $tipo): Collection
    {
        return DB::connection('mysql')->table('categorie')
            ->where('type', $tipo)
            ->whereNotNull('label')->where('label', '<>', '')
            ->orderBy('label')
            ->get(['rowid', 'label', 'color', 'fk_parent'])
            ->map(fn ($c) => [
                'id' => (int) $c->rowid,
                'label' => $c->label,
                'color' => $c->color ? '#'.ltrim($c->color, '#') : null,
                'parent' => (int) $c->fk_parent ?: null,
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function categoriasAsignadas(Sociedad $tercero, string $tabla): array
    {
        if (!$tercero->exists) {
            return [];
        }

        return DB::connection('mysql')->table($tabla)
            ->where('fk_soc', $tercero->rowid)
            ->pluck('fk_categorie')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function grupoDe(string $nombreExtrafield): string
    {
        foreach (self::GRUPOS_EXTRAFIELDS as $grupo => $nombres) {
            if (in_array($nombreExtrafield, $nombres, true)) {
                return $grupo;
            }
        }

        return 'otros';
    }

    /**
     * @param  array<string, mixed>  $valores
     * @param  array<int, string>  $grupos  solo se escriben los extrafields de estos grupos
     */
    private function guardarExtrafields(Sociedad $tercero, array $valores, array $grupos): void
    {
        $this->extrafields->guardar('societe', 'societe_extrafields', (int) $tercero->rowid, $valores,
            fn ($def) => in_array($this->grupoDe($def->name), $grupos, true));
    }

    private function catalogo(string $tabla, string $id, string $label, ?string $orden = null): Collection
    {
        return DB::connection('mysql')->table($tabla)
            ->where('active', 1)
            ->orderBy($orden ?? $label)
            ->get(["$id as id", "$label as label"]);
    }

    private function formasJuridicas(): Collection
    {
        return DB::connection('mysql')->table('c_forme_juridique')
            ->where('active', 1)
            ->where('fk_pays', self::PAIS_COLOMBIA)
            ->orderBy('libelle')
            ->get(['code as id', 'libelle as label']);
    }

    private function departamentosColombia(): Collection
    {
        return DB::connection('mysql')->table('c_departements as d')
            ->join('c_regions as r', 'r.code_region', '=', 'd.fk_region')
            ->where('r.fk_pays', self::PAIS_COLOMBIA)
            ->where('d.active', 1)
            ->orderBy('d.nom')
            ->get(['d.rowid as id', 'd.nom as label']);
    }

    /** Catálogos de facturación electrónica que pueden no existir en una base incompleta. */
    private function existeTabla(string $tabla): bool
    {
        static $cache = [];

        return $cache[$tabla] ??= Schema::connection('mysql')->hasTable($tabla);
    }

    /** @return array<int, string> nombres de los catálogos de la ficha que no están en la base de datos */
    private function catalogosFaltantes(): array
    {
        $faltan = [];
        foreach (['c_ciudades_fe' => 'ciudades (c_ciudades_fe)', 'c_codigos_ciiu' => 'códigos CIIU (c_codigos_ciiu)', 'c_clasificacion_tributaria' => 'clasificación tributaria (c_clasificacion_tributaria)'] as $tabla => $nombre) {
            if (! $this->existeTabla($tabla)) {
                $faltan[] = $nombre;
            }
        }

        return $faltan;
    }

    private function ciudades(): Collection
    {
        if (! $this->existeTabla('c_ciudades_fe')) {
            return collect();
        }

        return DB::connection('mysql')->table('c_ciudades_fe')
            ->where('active', 1)
            ->orderBy('ciudad')
            ->get(['rowid as id', 'ciudad', 'depto', 'code'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'label' => "{$c->ciudad} ({$c->depto})",
                'depto' => $c->depto,
                'code' => $c->code,
            ]);
    }

    private function ciius(): Collection
    {
        if (! $this->existeTabla('c_codigos_ciiu')) {
            return collect();
        }

        return DB::connection('mysql')->table('c_codigos_ciiu')
            ->where(fn ($q) => $q->where('active', 1)->orWhereNull('active'))
            ->orderBy('codigo')
            ->get(['codigo', 'descripcion'])
            ->map(fn ($c) => ['id' => $c->codigo, 'label' => "{$c->codigo} · {$c->descripcion}"]);
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
     * @return array{facturas: int, cartera: float, ultimaFactura: ?string}
     */
    /**
     * Actividad comercial del tercero (todos sus documentos, sin importar el año): solicitudes, cotizaciones, órdenes de
     * compra, entregas en curso y seguimientos pendientes, con los colores de las cards del tablero.
     *
     * @return array<int, array{clave: string, etiqueta: string, icono: string, color: string, activos: int, total: ?int, extra: ?string}>
     */
    private function actividadComercial(Sociedad $tercero): array
    {
        $db = DB::connection('mysql');
        $p = $db->getTablePrefix();
        $id = (int) $tercero->rowid;
        $colores = $this->dashboard->coloresEtapas();

        $docs = $db->selectOne("SELECT
                (SELECT COUNT(*) FROM {$p}solicitudes_solicitud WHERE fk_soc = ? AND status BETWEEN 1 AND 6) sol_a,
                (SELECT COUNT(*) FROM {$p}solicitudes_solicitud WHERE fk_soc = ? AND status > 0) sol_t,
                (SELECT COUNT(*) FROM {$p}cotizaciones_clientecotizacion WHERE fk_soc = ? AND status IN (1,2)) cot_a,
                (SELECT COUNT(*) FROM {$p}cotizaciones_clientecotizacion WHERE fk_soc = ? AND status > 0) cot_t,
                (SELECT COUNT(*) FROM {$p}ordencompra_orden WHERE fk_soc = ? AND status NOT IN (0,5,9,10,31)) oc_a,
                (SELECT COUNT(*) FROM {$p}ordencompra_orden WHERE fk_soc = ? AND status NOT IN (0,31)) oc_t,
                (SELECT COUNT(*) FROM {$p}ordencompra_guias_entregas g INNER JOIN {$p}ordencompra_orden o ON o.rowid = g.fk_orden
                    WHERE o.fk_soc = ? AND g.guia IS NOT NULL AND g.guia <> '' AND (g.estado IS NULL OR g.estado <> 'ENTREGADO')) ent_a,
                (SELECT COUNT(*) FROM {$p}ordencompra_guias_entregas g INNER JOIN {$p}ordencompra_orden o ON o.rowid = g.fk_orden
                    WHERE o.fk_soc = ? AND g.guia IS NOT NULL AND g.guia <> '') ent_t", array_fill(0, 8, $id));

        $hoy = now(ActividadesService::TZ)->toDateString();
        $seg = $db->selectOne("SELECT COUNT(*) n, COALESCE(SUM(DATE(datep) < ?),0) atrasados FROM {$p}actioncomm
            WHERE fk_soc = ? AND code LIKE ? AND percent < 100 AND elementtype IN ('solicitud','clientecotizacion','orden')", [$hoy, $id, 'AC\_SEGUIMIENTO\_RECORDATORIO%']);

        return [
            ['clave' => 'solicitudes', 'etiqueta' => 'Solicitudes', 'icono' => 'fa-inbox', 'color' => $colores[1]['fondo'], 'activos' => (int) $docs->sol_a, 'total' => (int) $docs->sol_t, 'extra' => null],
            ['clave' => 'cotizaciones', 'etiqueta' => 'Cotizaciones', 'icono' => 'fa-file-invoice-dollar', 'color' => $colores[2]['fondo'], 'activos' => (int) $docs->cot_a, 'total' => (int) $docs->cot_t, 'extra' => null],
            ['clave' => 'ordenes', 'etiqueta' => 'Órdenes de compra', 'icono' => 'fa-shopping-cart', 'color' => $colores[3]['fondo'], 'activos' => (int) $docs->oc_a, 'total' => (int) $docs->oc_t, 'extra' => null],
            ['clave' => 'entregas', 'etiqueta' => 'Entregas en curso', 'icono' => 'fa-truck', 'color' => $colores[4]['fondo'], 'activos' => (int) $docs->ent_a, 'total' => (int) $docs->ent_t, 'extra' => null],
            ['clave' => 'seguimientos', 'etiqueta' => 'Seguimientos pendientes', 'icono' => 'fa-bell', 'color' => '#f59e0b', 'activos' => (int) $seg->n, 'total' => null, 'extra' => (int) $seg->atrasados > 0 ? ((int) $seg->atrasados).' atrasados' : null],
        ];
    }

    private function kpis(Sociedad $tercero): array
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        $row = $db->selectOne(
            "SELECT
                SUM(f.fk_statut > 0) AS facturas,
                MAX(CASE WHEN f.fk_statut > 0 THEN f.datef END) AS ultima,
                SUM(CASE WHEN f.fk_statut = 1 AND f.paye = 0
                    THEN f.total_ttc - COALESCE((SELECT SUM(pf.amount) FROM {$prefix}paiement_facture pf WHERE pf.fk_facture = f.rowid), 0)
                    ELSE 0 END) AS cartera
            FROM {$prefix}facture f
            WHERE f.fk_soc = ?",
            [$tercero->rowid]
        );

        return [
            'facturas' => (int) ($row->facturas ?? 0),
            'cartera' => (float) ($row->cartera ?? 0),
            'ultimaFactura' => $row->ultima ?? null,
        ];
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
