<?php

namespace App\Services\Usuarios;

use App\Models\DolibarrUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Toda la lógica de lectura/escritura del perfil de usuario Dolibarr (datos
 * personales, extrafields, contraseña, grupos, permisos), compartida entre
 * el autoservicio (PerfilController) y la edición de admin (UsuarioEditController).
 */
class PerfilUsuarioService
{
    private const CAMPOS_DATOS_PERSONALES = [
        'firstname', 'lastname', 'email', 'job', 'office_phone', 'user_mobile',
        'address', 'zip', 'town', 'fk_state', 'fk_country', 'birth', 'civility', 'gender',
    ];

    private const CAMPOS_SOLO_ADMIN = ['statut', 'admin', 'employee'];

    /**
     * @return array<string, array<int, string>>
     */
    public function reglasDatosPersonales(bool $esAdmin, int $usuarioId): array
    {
        $reglas = [
            'firstname' => ['required', 'string', 'max:50'],
            'lastname' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'job' => ['nullable', 'string', 'max:128'],
            'office_phone' => ['nullable', 'string', 'max:30'],
            'user_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:25'],
            'town' => ['nullable', 'string', 'max:50'],
            'fk_state' => ['nullable', 'integer'],
            'fk_country' => ['nullable', 'integer'],
            'birth' => ['nullable', 'date'],
            'civility' => ['nullable', 'string', 'max:6'],
            'gender' => ['nullable', 'in:man,woman'],
        ];

        if ($esAdmin) {
            $reglas['statut'] = ['required', 'in:0,1'];
            $reglas['admin'] = ['required', 'in:0,1'];
            $reglas['employee'] = ['required', 'in:0,1'];
        }

        return $reglas;
    }

    public function actualizarDatosPersonales(DolibarrUser $usuario, array $datos, bool $esAdmin): void
    {
        $campos = self::CAMPOS_DATOS_PERSONALES;
        if ($esAdmin) {
            $campos = [...$campos, ...self::CAMPOS_SOLO_ADMIN];
        }

        $usuario->fill(array_intersect_key($datos, array_flip($campos)));
        $usuario->save();
    }

    public function paises(): Collection
    {
        return DB::connection('mysql')->table('c_country')
            ->where('active', 1)
            ->orderBy('label')
            ->get(['rowid', 'label']);
    }

    public function departamentos(): Collection
    {
        return DB::connection('mysql')->table('c_departements')
            ->orderBy('nom')
            ->get(['rowid', 'nom']);
    }

    public function civilidades(): Collection
    {
        return DB::connection('mysql')->table('c_civility')
            ->where('active', 1)
            ->orderBy('label')
            ->get(['code', 'label']);
    }

    /**
     * Definiciones de extrafields habilitados (elementtype=user), para
     * renderizar el formulario.
     */
    public function extrafieldsDefiniciones(): Collection
    {
        return DB::connection('mysql')->table('extrafields')
            ->where('elementtype', 'user')
            ->where('enabled', 1)
            ->orderBy('pos')
            ->get(['name', 'label', 'type', 'param']);
    }

    /**
     * Valores actuales guardados en user_extrafields para este usuario.
     *
     * @return array<string, mixed>
     */
    public function valoresExtrafields(DolibarrUser $usuario): array
    {
        $row = DB::connection('mysql')->table('user_extrafields')
            ->where('fk_object', $usuario->rowid)
            ->first();

        return $row ? (array) $row : [];
    }

    /**
     * Opciones [valor => etiqueta] para un extrafield select/sellist. Para
     * "select" el propio param ya trae el mapa. Para "sellist" hay que
     * consultar la tabla externa que indica el spec (ver opcionesSellist).
     *
     * @return array<int|string, string>
     */
    public function opcionesExtrafield(object $def): array
    {
        $param = $this->decodeParam($def->param);
        $options = $param['options'] ?? [];

        if (empty($options)) {
            return [];
        }

        if ($def->type === 'select') {
            return $options;
        }

        if ($def->type === 'sellist') {
            return $this->opcionesSellist(array_key_first($options));
        }

        return [];
    }

    /**
     * Parsea el spec sellist de Dolibarr: "tabla:campo_label:campo_key" con
     * un filtro opcional después de "::", ej.
     * "c_nomina_configuracion_seguridad_social:descripcion:rowid::(code:=:'RIESGO_ARL')".
     * Solo soporta el patrón de filtro (columna:=:'valor') — el único que
     * aparece hoy en los extrafields de usuario — no un parser genérico de SQL.
     *
     * @return array<int|string, string>
     */
    private function opcionesSellist(?string $spec): array
    {
        if (!$spec) {
            return [];
        }

        $partes = explode('::', $spec, 2);
        [$tabla, $labelField, $keyField] = array_pad(explode(':', $partes[0]), 3, null);

        if (!$tabla || !$labelField || !$keyField) {
            return [];
        }

        $db = DB::connection('mysql');
        $query = $db->table($tabla);

        if (isset($partes[1]) && preg_match("/\(([a-zA-Z0-9_]+):=:'([^']*)'\)/", $partes[1], $m)) {
            $query->where($m[1], $m[2]);
        }

        if (Schema::connection('mysql')->hasColumn($tabla, 'active')) {
            $query->where('active', 1);
        }

        return $query->orderBy($labelField)->pluck($labelField, $keyField)->all();
    }

    private function decodeParam(?string $param): array
    {
        if (!$param) {
            return [];
        }

        $decoded = @unserialize($param);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Guarda los extrafields enviados por el formulario. Los booleanos
     * ausentes en el request (checkbox sin marcar) se guardan como 0.
     *
     * @param  array<string, mixed>  $valores
     */
    public function actualizarExtrafields(DolibarrUser $usuario, array $valores): void
    {
        $defs = $this->extrafieldsDefiniciones();
        $datos = [];

        foreach ($defs as $def) {
            if (!array_key_exists($def->name, $valores)) {
                if ($def->type === 'boolean') {
                    $datos[$def->name] = 0;
                }
                continue;
            }

            $valor = $valores[$def->name];
            $datos[$def->name] = match ($def->type) {
                'boolean' => $valor ? 1 : 0,
                'int' => $valor !== '' && $valor !== null ? (int) $valor : null,
                'double' => $valor !== '' && $valor !== null ? (float) $valor : null,
                default => $valor !== '' ? $valor : null,
            };
        }

        if (empty($datos)) {
            return;
        }

        DB::connection('mysql')->table('user_extrafields')->updateOrInsert(
            ['fk_object' => $usuario->rowid],
            $datos
        );
    }

    /**
     * Guarda la contraseña nueva con password_hash() (bcrypt), el formato
     * moderno de Dolibarr, y limpia pass/pass_temp (nunca texto plano).
     */
    public function cambiarPassword(DolibarrUser $usuario, string $nueva): void
    {
        DB::connection('mysql')->table('user')->where('rowid', $usuario->rowid)->update([
            'pass_crypted' => password_hash($nueva, PASSWORD_BCRYPT),
            'pass_encoding' => 'password_hash',
            'pass' => null,
            'pass_temp' => null,
            'datelastpassvalidation' => now(),
        ]);
    }

    public function grupos(): Collection
    {
        return DB::connection('mysql')->table('usergroup')->orderBy('nom')->get(['rowid', 'nom']);
    }

    /**
     * @return array<int, int>
     */
    public function gruposDe(DolibarrUser $usuario): array
    {
        return DB::connection('mysql')->table('usergroup_user')
            ->where('fk_user', $usuario->rowid)
            ->pluck('fk_usergroup')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<int, int|string>  $grupoIds
     */
    public function sincronizarGrupos(DolibarrUser $usuario, array $grupoIds): void
    {
        $grupoIds = array_map('intval', $grupoIds);
        $db = DB::connection('mysql');

        $db->table('usergroup_user')->where('fk_user', $usuario->rowid)->delete();

        foreach ($grupoIds as $grupoId) {
            $db->table('usergroup_user')->insert([
                'entity' => 1,
                'fk_user' => $usuario->rowid,
                'fk_usergroup' => $grupoId,
            ]);
        }
    }

    /**
     * Los 301 permisos de Dolibarr agrupados por módulo (consultado en vivo,
     * sin lista de módulos harcodeada), para el editor dinámico.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function permisosAgrupados(): Collection
    {
        return DB::connection('mysql')->table('rights_def')
            ->orderBy('module_position')
            ->orderBy('family_position')
            ->orderBy('id')
            ->get(['id', 'module', 'libelle', 'perms', 'subperms'])
            ->groupBy('module');
    }

    /**
     * @return array<int, int>
     */
    public function permisosDe(DolibarrUser $usuario): array
    {
        return DB::connection('mysql')->table('user_rights')
            ->where('fk_user', $usuario->rowid)
            ->pluck('fk_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<int, int|string>  $rightIds
     */
    public function sincronizarPermisos(DolibarrUser $usuario, array $rightIds): void
    {
        $rightIds = array_map('intval', $rightIds);
        $db = DB::connection('mysql');

        $db->table('user_rights')->where('fk_user', $usuario->rowid)->delete();

        foreach ($rightIds as $rightId) {
            $db->table('user_rights')->insert([
                'entity' => 1,
                'fk_user' => $usuario->rowid,
                'fk_id' => $rightId,
            ]);
        }
    }
}
