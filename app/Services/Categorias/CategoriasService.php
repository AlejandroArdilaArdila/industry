<?php

namespace App\Services\Categorias;

use App\Models\Categoria;
use App\Models\DolibarrUser;
use App\Services\Panel\PermisosDolibarr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administrador de etiquetas/categorías de Dolibarr (tabla categorie) para
 * todos los tipos: un solo listado y un solo formulario. El tipo de una
 * categoría decide en qué tabla de enlace (categorie_<tabla>) viven sus
 * elementos; los ids salen de Categorie::$MAP_ID de Dolibarr.
 */
class CategoriasService
{
    /** Tipo Dolibarr => [etiqueta, tabla de enlace sin prefijo, icono, color]. */
    public const TIPOS = [
        0 => ['Productos / servicios', 'categorie_product', 'fa-cube', '6c6aa8'],
        2 => ['Clientes', 'categorie_societe', 'fa-users', '0891b2'],
        1 => ['Proveedores', 'categorie_fournisseur', 'fa-truck', 'b45309'],
        4 => ['Contactos', 'categorie_contact', 'fa-address-book', '0d9488'],
        7 => ['Usuarios', 'categorie_user', 'fa-user-gear', '4338ca'],
        3 => ['Miembros', 'categorie_member', 'fa-id-card', '7c3aed'],
        5 => ['Cuentas bancarias', 'categorie_account', 'fa-building-columns', '15803d'],
        9 => ['Almacenes', 'categorie_warehouse', 'fa-warehouse', '92400e'],
        6 => ['Proyectos', 'categorie_project', 'fa-diagram-project', 'be185d'],
        10 => ['Eventos / agenda', 'categorie_actioncomm', 'fa-calendar-days', '0369a1'],
        12 => ['Tickets', 'categorie_ticket', 'fa-ticket', 'a21caf'],
        16 => ['Pedidos', 'categorie_order', 'fa-cart-shopping', '65a30d'],
        17 => ['Facturas', 'categorie_invoice', 'fa-file-invoice-dollar', '16a34a'],
        20 => ['Pedidos a proveedor', 'categorie_supplier_order', 'fa-dolly', 'c2410c'],
        21 => ['Facturas de proveedor', 'categorie_supplier_invoice', 'fa-file-invoice', 'dc2626'],
    ];

    public function __construct(private PermisosDolibarr $permisos)
    {
    }

    public function puedeEditar(?DolibarrUser $usuario): bool
    {
        return $this->permisos->tiene($usuario, PermisosDolibarr::CATEGORIAS_CREAR);
    }

    public function puedeEliminar(?DolibarrUser $usuario): bool
    {
        return $this->permisos->tiene($usuario, PermisosDolibarr::CATEGORIAS_ELIMINAR);
    }

    /**
     * Tipos para el filtro del listado y el selector del formulario, con
     * cuántas categorías tiene cada uno.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tipos(): array
    {
        $conteos = DB::connection('mysql')->table('categorie')
            ->groupBy('type')
            ->pluck(DB::raw('COUNT(*)'), 'type');

        return collect(self::TIPOS)
            ->map(fn ($t, $id) => [
                'rowid' => $id,
                'label' => $t[0],
                'icono' => $t[2],
                'color' => $t[3],
                'total' => (int) ($conteos[$id] ?? 0),
            ])
            ->values()
            ->all();
    }

    public static function nombreTipo(int $tipo): string
    {
        return self::TIPOS[$tipo][0] ?? 'Tipo '.$tipo;
    }

    /**
     * Las raíces "POS" y "SUCURSAL" (cuentas bancarias) las busca el POS por
     * nombre (ConfiguracionService): renombrarlas, moverlas o borrarlas rompe
     * los métodos de pago y las sucursales.
     */
    public function esProtegida(Categoria $categoria): bool
    {
        if (!$categoria->exists) {
            return false;
        }
        $label = mb_strtoupper(trim((string) $categoria->label));

        return $label === 'POS' || ((int) $categoria->type === 5 && $label === 'SUCURSAL');
    }

    // ------------------------------------------------------------------ ficha

    public function nueva(?int $tipo): Categoria
    {
        $categoria = new Categoria;
        $categoria->type = $tipo !== null && isset(self::TIPOS[$tipo]) ? $tipo : 0;
        $categoria->fk_parent = 0;

        return $categoria;
    }

    /**
     * @return array<string, mixed>
     */
    public function datosFicha(Categoria $categoria): array
    {
        $db = DB::connection('mysql');
        $existe = $categoria->exists;
        $excluir = $existe ? Categoria::idsConDescendientes([(int) $categoria->rowid]) : [];

        return [
            'categoria' => $categoria,
            'tipos' => $this->tipos(),
            // Posibles padres de todos los tipos; el formulario muestra solo los
            // del tipo elegido. No puede colgar de sí misma ni de una descendiente.
            'padres' => $this->rutas()
                ->reject(fn ($c) => in_array($c['id'], $excluir, true))
                ->values(),
            'elementos' => $existe ? $this->contarElementos((int) $categoria->type, [(int) $categoria->rowid])[(int) $categoria->rowid] ?? 0 : 0,
            'subcategorias' => $existe ? $db->table('categorie')->where('fk_parent', $categoria->rowid)->count() : 0,
            'enPromociones' => $existe ? $db->table('integros_promociones_categories')->where('fk_category', $categoria->rowid)->count() : 0,
            'protegida' => $this->esProtegida($categoria),
            'creador' => $existe ? $this->nombreUsuario($categoria->fk_user_creat) : null,
            'editor' => $existe ? $this->nombreUsuario($categoria->fk_user_modif) : null,
        ];
    }

    /**
     * Todas las categorías con su ruta completa ("Padre › Hija"), para el
     * selector de categoría padre.
     *
     * @return Collection<int, array{id: int, label: string, tipo: int}>
     */
    private function rutas(): Collection
    {
        $prefix = DB::connection('mysql')->getTablePrefix();

        return collect(DB::connection('mysql')->select($this->sqlRutas($prefix).'
            SELECT c.rowid, c.type, COALESCE(r.ruta, c.label) AS ruta
            FROM '.$prefix.'categorie c
            LEFT JOIN rutas r ON r.rowid = c.rowid
            ORDER BY ruta'))
            ->map(fn ($r) => ['id' => (int) $r->rowid, 'label' => $r->ruta, 'tipo' => (int) $r->type]);
    }

    /**
     * CTE recursivo "rutas" (rowid, ruta): la ruta completa de cada categoría
     * alcanzable desde una raíz. Tope de profundidad por si hay ciclos.
     */
    public function sqlRutas(string $prefix): string
    {
        return "WITH RECURSIVE rutas (rowid, ruta, nivel) AS (
                SELECT rowid, CAST(label AS CHAR(2000)), 0 FROM {$prefix}categorie WHERE fk_parent = 0
                UNION ALL
                SELECT c.rowid, CONCAT(r.ruta, ' › ', c.label), r.nivel + 1
                FROM {$prefix}categorie c JOIN rutas r ON c.fk_parent = r.rowid
                WHERE r.nivel < 20
            )";
    }

    /**
     * Elementos asignados por categoría, todas del mismo tipo.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function contarElementos(int $tipo, array $ids): array
    {
        $tabla = self::TIPOS[$tipo][1] ?? null;
        if (!$tabla || !$ids) {
            return [];
        }

        return DB::connection('mysql')->table($tabla)
            ->whereIn('fk_categorie', $ids)
            ->groupBy('fk_categorie')
            ->pluck(DB::raw('COUNT(*)'), 'fk_categorie')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    // ------------------------------------------------------------- validación

    /**
     * @return array<string, mixed>
     */
    public function reglas(Categoria $categoria, array $entrada): array
    {
        $tipo = $categoria->exists ? (int) $categoria->type : (int) ($entrada['tipo'] ?? -1);
        $padre = (int) ($entrada['fk_parent'] ?? 0);

        return [
            'tipo' => $categoria->exists ? ['nullable'] : ['required', 'integer', Rule::in(array_keys(self::TIPOS))],
            'label' => [
                'required', 'string', 'max:180',
                // Índice único de Dolibarr: (entity, fk_parent, label, type).
                Rule::unique('mysql.categorie', 'label')
                    ->where('type', $tipo)
                    ->where('fk_parent', $padre)
                    ->ignore($categoria->rowid, 'rowid'),
            ],
            'fk_parent' => ['nullable', 'integer'],
            'color' => ['nullable', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mensajes(): array
    {
        return [
            'label.unique' => 'Ya existe una categoría con ese nombre en el mismo tipo y nivel.',
            'color.regex' => 'El color debe tener el formato #RRGGBB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function atributos(): array
    {
        return ['tipo' => 'tipo', 'label' => 'nombre', 'fk_parent' => 'categoría padre', 'color' => 'color', 'description' => 'descripción'];
    }

    /**
     * Reglas que dependen de la base: el padre debe existir, ser del mismo
     * tipo y no ser la propia categoría ni una descendiente; las protegidas
     * no se renombran ni se mueven.
     *
     * @param  array<string, mixed>  $datos
     */
    public function validarRelaciones(Categoria $categoria, array $datos): void
    {
        $tipo = $categoria->exists ? (int) $categoria->type : (int) $datos['tipo'];
        $padre = (int) ($datos['fk_parent'] ?? 0);

        if ($padre > 0) {
            $filaPadre = Categoria::query()->find($padre);
            if (!$filaPadre || (int) $filaPadre->type !== $tipo) {
                throw ValidationException::withMessages(['fk_parent' => 'La categoría padre debe ser del mismo tipo.']);
            }
            if ($categoria->exists && in_array($padre, Categoria::idsConDescendientes([(int) $categoria->rowid]), true)) {
                throw ValidationException::withMessages(['fk_parent' => 'Una categoría no puede quedar dentro de sí misma ni de una de sus subcategorías.']);
            }
        }

        if ($this->esProtegida($categoria)
            && (trim($datos['label']) !== trim((string) $categoria->label) || $padre !== (int) $categoria->fk_parent)) {
            throw ValidationException::withMessages(['label' => 'El POS usa esta categoría por su nombre: no se puede renombrar ni mover.']);
        }
    }

    // -------------------------------------------------------------- escritura

    /**
     * @param  array<string, mixed>  $datos  validados
     */
    public function guardar(Categoria $categoria, array $datos, DolibarrUser $usuario): Categoria
    {
        $nueva = !$categoria->exists;

        if ($nueva) {
            $categoria->type = (int) $datos['tipo'];
            $categoria->entity = 1;
            $categoria->visible = 1;
            $categoria->position = 0;
            $categoria->date_creation = now()->format('Y-m-d H:i:s');
            $categoria->fk_user_creat = $usuario->rowid;
        }

        $categoria->label = trim($datos['label']);
        $categoria->fk_parent = (int) ($datos['fk_parent'] ?? 0);
        // Dolibarr guarda el color sin "#".
        $categoria->color = ($datos['color'] ?? '') !== '' ? strtolower(ltrim($datos['color'], '#')) : null;
        $categoria->description = ($datos['description'] ?? '') !== '' ? $datos['description'] : null;
        $categoria->fk_user_modif = $usuario->rowid;
        $categoria->save();

        return $categoria;
    }

    /**
     * Como Categorie::delete() de Dolibarr: las subcategorías suben al padre
     * de la eliminada y se quitan sus enlaces, traducciones y extrafields.
     * No se borra si una promoción la usa (dejaría la promoción sin efecto
     * sin que nadie lo note).
     */
    public function eliminar(Categoria $categoria): void
    {
        if ($this->esProtegida($categoria)) {
            throw ValidationException::withMessages(['categoria' => 'El POS usa esta categoría por su nombre: no se puede eliminar.']);
        }

        $db = DB::connection('mysql');
        $promociones = $db->table('integros_promociones_categories')->where('fk_category', $categoria->rowid)->count();
        if ($promociones > 0) {
            throw ValidationException::withMessages([
                'categoria' => "Está en {$promociones} promoción".($promociones === 1 ? '' : 'es').': quítala de ahí antes de eliminarla.',
            ]);
        }

        $db->transaction(function () use ($db, $categoria) {
            $id = (int) $categoria->rowid;

            $db->table('categorie')->where('fk_parent', $id)->update(['fk_parent' => (int) $categoria->fk_parent]);

            if ($tabla = self::TIPOS[(int) $categoria->type][1] ?? null) {
                $db->table($tabla)->where('fk_categorie', $id)->delete();
            }
            $db->table('categorie_lang')->where('fk_category', $id)->delete();
            $db->table('categories_extrafields')->where('fk_object', $id)->delete();

            $categoria->delete();
        });
    }

    private function nombreUsuario($id): ?string
    {
        if (!$id) {
            return null;
        }
        $u = DB::connection('mysql')->table('user')->where('rowid', $id)->first(['firstname', 'lastname', 'login']);

        return $u ? (trim($u->firstname.' '.$u->lastname) ?: $u->login) : null;
    }
}
