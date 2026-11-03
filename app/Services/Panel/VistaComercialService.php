<?php

namespace App\Services\Panel;

use App\Services\Ia\IaConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién ve el panel "solo comercial" (desde el Tablero comercial hacia abajo). Mismo criterio que el Dolibarr anterior
 * (informessolu / ActionsInformessolu::esNegociador): negociador = integrante de un grupo ACTIVO de Olimpiadas
 * (informessolu_olimpiadas_grupos_integrantes), excepto el grupo RETIRADOS. No tiene relación con los grupos de usuarios.
 */
class VistaComercialService
{
    public const CONST_COMPLETA = 'PANEL_DASH_VISTA_COMPLETA';

    /** @return array<int, int> ids de usuarios que siempre ven el panel completo, aunque estén en un grupo de negociadores */
    public function usuariosCompletos(): array
    {
        return array_values(array_map('intval', (array) (IaConfigService::json(self::CONST_COMPLETA)['usuarios'] ?? [])));
    }

    public function guardarUsuariosCompletos(array $ids): void
    {
        $validos = DB::connection('mysql')->table('user')->whereIn('rowid', array_map('intval', $ids))->pluck('rowid')->map(fn ($v) => (int) $v)->all();
        IaConfigService::escribir(self::CONST_COMPLETA, ['usuarios' => $validos], 'Usuarios que siempre ven el panel completo (excepción a la vista de negociadores)');
    }

    /** @return array<int, array{id: int, nombre: string, login: string}> usuarios activos, para elegir las excepciones */
    public function usuarios(): array
    {
        return DB::connection('mysql')->table('user')->where('statut', 1)->orderBy('firstname')->orderBy('lastname')
            ->get(['rowid', 'firstname', 'lastname', 'login'])
            ->map(fn ($u) => ['id' => (int) $u->rowid, 'nombre' => trim("{$u->firstname} {$u->lastname}") ?: (string) $u->login, 'login' => (string) $u->login])->all();
    }

    /** Las tablas de Olimpiadas las crea el módulo informessolu: si no existen (instalación sin el módulo) nadie es negociador. */
    private function disponible(): bool
    {
        $s = Schema::connection('mysql');

        return $s->hasTable('informessolu_olimpiadas_grupos') && $s->hasTable('informessolu_olimpiadas_grupos_integrantes');
    }

    /** Consulta base: grupos de Olimpiadas activos (estado = 1), sin el grupo RETIRADOS. Mismo filtro del informe de Metas. */
    private function gruposActivos()
    {
        $db = DB::connection('mysql');

        // En SQL crudo Laravel no antepone el prefijo de tablas (llx_) a los alias: wrap() sí lo hace (g → llx_g).
        return $db->table('informessolu_olimpiadas_grupos as g')
            ->where('g.estado', 1)
            ->whereRaw('UPPER(TRIM('.$db->getQueryGrammar()->wrap('g.nombre_grupo').")) <> 'RETIRADOS'");
    }

    /** @return array<int, array{id: int, nombre: string, integrantes: int}> grupos de Olimpiadas que cuentan como negociadores */
    public function grupos(): array
    {
        if (! $this->disponible()) {
            return [];
        }

        return $this->gruposActivos()
            ->leftJoin('informessolu_olimpiadas_grupos_integrantes as gi', 'gi.fk_grupo', '=', 'g.rowid')
            ->groupBy('g.rowid', 'g.nombre_grupo', 'g.orden')->orderBy('g.orden')->orderBy('g.rowid')
            ->get(['g.rowid', 'g.nombre_grupo', DB::raw('COUNT('.DB::connection('mysql')->getQueryGrammar()->wrap('gi.fk_usuario').') as n')])
            ->map(fn ($g) => ['id' => (int) $g->rowid, 'nombre' => (string) $g->nombre_grupo, 'integrantes' => (int) $g->n])->all();
    }

    /** @return array<int, string> nombres de los grupos de Olimpiadas a los que pertenece el usuario */
    public function gruposDe(object $user): array
    {
        if (! $this->disponible()) {
            return [];
        }

        return $this->gruposActivos()
            ->join('informessolu_olimpiadas_grupos_integrantes as gi', 'gi.fk_grupo', '=', 'g.rowid')
            ->where('gi.fk_usuario', $user->getKey())->orderBy('g.orden')->pluck('g.nombre_grupo')->map(fn ($n) => (string) $n)->all();
    }

    /** Es negociador quien integra al menos un grupo activo de Olimpiadas (fk_usuario = rowid del usuario de Dolibarr). */
    public function esNegociador(object $user): bool
    {
        return (bool) $this->gruposDe($user);
    }

    /**
     * ¿Se muestra el panel reducido (solo desde el Tablero comercial)?
     * La prioridad la tiene el GRUPO: quien es negociador ve solo el Tablero comercial aunque sea administrador. Lo único
     * que lo cambia es estar en la lista de excepciones (Configuración → Panel: «ven siempre el panel completo») o que un
     * administrador pida expresamente el panel completo con ?completo=1 (la vista reducida le
     * muestra un botón «Ver panel completo»); un usuario no administrador no puede saltarse la restricción.
     */
    public function activa(object $user, bool $verCompleto = false): bool
    {
        if ($verCompleto && $user->isAdmin()) {
            return false;
        }
        if (in_array((int) $user->getKey(), $this->usuariosCompletos(), true)) {
            return false; // excepción configurada: siempre el panel completo
        }

        return $this->esNegociador($user);
    }
}
