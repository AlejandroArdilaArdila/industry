<?php

namespace App\Services\Configuracion;

use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\CuentaBancaria;
use App\Models\Permiso;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Replica configuracionUsuario() (api_app.class.php, modulo integrosapk):
 * acceso/credenciales, permisos iapk (con cruce contra user_rights o todo
 * true si es admin), setup del modulo (llx_const dinamico) y metodos de
 * pago (categoria configurada + subcategorias). Emite el resultado por
 * WebSocket como efecto secundario (best-effort, no bloquea la respuesta).
 */
class ConfiguracionUsuarioService
{
    private const MODULO_INTEGROSAPK = 'iapk';
    private const PREFIJO_CONST_APK = 'APK_';

    /**
     * Siempre resuelve el usuario a partir del DOLAPIKEY autenticado (no se
     * recibe 'id' por body): con eso basta, ese es el unico usuario cuya
     * configuracion tiene sentido devolver en este endpoint.
     */
    public function obtener(Request $request, Usuario $usuarioAutenticado): array
    {
        $usuarios = Usuario::query()
            ->where('rowid', $usuarioAutenticado->rowid)
            ->select(['rowid', 'login', 'pass_crypted', 'api_key', 'admin', 'firstname', 'lastname'])
            ->with(['extrafields' => fn ($q) => $q->select('fk_object', 'pin_admin')])
            ->with(['grupos' => fn ($q) => $q->select('usergroup.rowid')])
            ->with(['permisosApk' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSAPK)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->with(['grupos.permisosApk' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSAPK)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->get();

        $catalogoApk = Permiso::query()
            ->where('module', self::MODULO_INTEGROSAPK)
            ->get(['perms', 'subperms']);

        $usuariosArr = $usuarios->map(fn ($usuario) => [
            'rowid' => (int) $usuario->rowid,
            'login' => $usuario->login,
            'pass_crypted' => $usuario->pass_crypted,
            'api_key' => $usuario->api_key,
            'admin' => (int) $usuario->admin === 1,
            'fullname' => trim($usuario->firstname.' '.$usuario->lastname),
            'pin_admin' => $usuario->extrafields?->pin_admin ?: 0,
            'permisos' => $this->mapaPermisosApk($usuario, $catalogoApk),
        ])->values()->all();

        $setup = $this->setupModulo();
        $metodos = $this->metodosPago($setup);
        $almacenes = $this->almacenes();

        $result = [
            'ok' => true,
            'total' => count($usuariosArr),
            'usuarios' => $usuariosArr,
            'setup' => $setup,
            'metodos' => $metodos,
            'almacenes' => $almacenes,
        ];

        $this->emitirWss($request, $result);

        return $result;
    }

    /**
     * Version reducida para cuando la DOLAPIKEY es de un proveedor (no de un
     * usuario iapk): no hay pass_crypted/permisos/almacenes/pin_admin que
     * devolver, asi que solo se responde el setup general (consts APK_* +
     * nombre de la empresa). Sin efecto secundario de WSS: ese evento es
     * para refrescar terminales POS, no aplica aqui.
     */
    public function obtenerParaProveedor(): array
    {
        return [
            'ok' => true,
            'total' => 0,
            'usuarios' => [],
            'setup' => $this->setupModulo(),
            'metodos' => [],
            'almacenes' => [],
        ];
    }

    /**
     * Todo en true si el usuario es admin; si no, cruza el catalogo
     * completo del modulo iapk contra lo que el usuario tiene (directo o
     * heredado de sus grupos).
     *
     * @param  \Illuminate\Support\Collection<int, Permiso>  $catalogoApk
     * @return array<string, bool>
     */
    private function mapaPermisosApk(Usuario $usuario, $catalogoApk): array
    {
        if ((int) $usuario->admin === 1) {
            return $catalogoApk->mapWithKeys(fn ($permiso) => [$permiso->perms => true])->all();
        }

        $otorgados = $usuario->permisosApk
            ->concat($usuario->grupos->flatMap(fn ($grupo) => $grupo->permisosApk))
            ->map(fn ($permiso) => $permiso->perms.'|'.$permiso->subperms)
            ->unique();

        $permisos = [];

        foreach ($catalogoApk as $permiso) {
            $permisos[$permiso->perms] = $otorgados->contains($permiso->perms.'|'.$permiso->subperms);
        }

        return $permisos;
    }

    /**
     * Constantes APK_* (llx_const, dinamico - no hardcodeadas), mas el
     * nombre de la empresa.
     */
    private function setupModulo(): array
    {
        $nombresConst = Configuracion::query()
            ->where('name', 'like', self::PREFIJO_CONST_APK.'%')
            ->distinct()
            ->pluck('name')
            ->all();

        return Configuracion::valores(array_merge($nombresConst, ['MAIN_INFO_SOCIETE_NOM']));
    }

    /**
     * Cuentas bancarias de la categoria configurada en
     * APK_CATEGORIA_METODOS_PAGO y todas sus subcategorias, con "LIQ" primero.
     */
    private function metodosPago(array $setup): array
    {
        $idCategoria = (int) ($setup['APK_CATEGORIA_METODOS_PAGO'] ?? 0);

        if ($idCategoria <= 0) {
            return [];
        }

        $idsCategorias = Categoria::idsConDescendientes([$idCategoria]);

        return CuentaBancaria::query()
            ->whereHas('categorias', fn ($q) => $q->whereIn('categorie.rowid', $idsCategorias))
            ->orderByRaw("(ref = 'LIQ') DESC")
            ->orderBy('ref')
            ->get(['rowid', 'ref', 'label'])
            ->map(fn ($m) => [
                'id' => (int) $m->rowid,
                'code' => $m->ref,
                'metodo' => $m->label,
            ])
            ->values()
            ->all();
    }

    /**
     * Todos los almacenes activos.
     */
    private function almacenes(): array
    {
        return Almacen::query()
            ->where('statut', 1)
            ->orderBy('ref')
            ->get(['rowid', 'ref'])
            ->map(fn ($almacen) => [
                'idAlmacen' => (int) $almacen->rowid,
                'descripcion' => $almacen->ref,
            ])
            ->values()
            ->all();
    }

    /**
     * Notifica a los clientes conectados por WebSocket (servidor wss/,
     * POST /wss/emitir-evento) que la configuracion de usuarios cambio.
     * Best-effort: si el wss esta caido no debe tumbar la respuesta HTTP.
     */
    private function emitirWss(Request $request, array $result): void
    {
        try {
            Http::withHeaders(['DOLAPIKEY' => $request->header('DOLAPIKEY')])
                ->timeout(3)
                ->post(rtrim((string) config('services.wss.url'), '/').'/wss/emitir-evento', [
                    'usuarios' => 'all',
                    'evento' => 'update',
                    'datos' => [
                        'mensaje' => 'Configuración usuarios actualizada',
                        'result' => $result,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo emitir el evento de configuracionUsuario por WebSocket: '.$e->getMessage());
        }
    }
}
