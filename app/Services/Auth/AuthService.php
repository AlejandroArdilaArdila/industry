<?php

namespace App\Services\Auth;

use App\Models\Configuracion;
use App\Models\DolibarrUser;
use App\Models\PosLoginLogout;
use App\Models\SesionDispositivo;
use App\Models\Sociedad;
use App\Models\SociedadComercial;
use App\Models\TerminalPos;
use App\Support\DolibarrCrypt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * Días de validez del refresh token (más allá del access token).
     */
    private const REFRESH_DAYS = 7;

    /**
     * Replica main() del login legado: valida el body crudo, credenciales,
     * estado del usuario, api_key, terminal configurada, y el candado de un
     * solo dispositivo activo (tabla integros_user_token) antes de emitir la
     * sesion real (Sanctum, sin cambios frente al resto del servicio).
     * device_name identifica el dispositivo tanto para el "slot" de Sanctum
     * (un token activo por device_name) como para el candado de un solo
     * dispositivo activo, por eso ahora es obligatorio.
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function login(Request $request): array
    {
        $raw = $request->getContent();

        if ($raw === false || trim((string) $raw) === '') {
            return [400, ['error' => 'NO SE HAN RECIBIDO DATOS PARA PROCESAR SU SOLICITUD.']];
        }

        $payload = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [400, ['error' => 'ESTRUCTURA DE DATOS RECIBIDOS ERRONEA: '.json_last_error_msg()]];
        }

        $login = isset($payload['login']) ? trim((string) $payload['login']) : null;
        $password = $payload['password'] ?? null;
        $deviceName = isset($payload['device_name']) ? trim((string) $payload['device_name']) : null;
        $esApp = filter_var($payload['app'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // IP y version del POS: si el POS no envia la IP se toma la de la peticion.
        $ip = isset($payload['ip']) && trim((string) $payload['ip']) !== '' ? trim((string) $payload['ip']) : $request->ip();
        $versionPos = isset($payload['version']) && trim((string) $payload['version']) !== '' ? trim((string) $payload['version']) : null;

        if (!$login || !$password || !$deviceName) {
            return [403, ['error' => 'PARÁMETROS INCOMPLETOS']];
        }

        /** @var DolibarrUser|null $user */
        $user = DolibarrUser::query()
            ->where('login', $login)
            ->whereIn('entity', [0, 1])
            ->first();

        if (!$user || !$this->verifyPassword($password, $user)) {
            return [403, ['error' => 'CREDENCIALES INVALIDAS']];
        }

        if ((int) $user->statut !== 1) {
            return [403, ['error' => 'USUARIO INACTIVO']];
        }

        if (empty($user->getAttribute('api_key'))) {
            return [403, ['error' => 'USUARIO NO TIENE API_KEY CONFIGURADA']];
        }

        $seleccionarCaja = (Configuracion::valores(['SELECCIONAR_CAJA'])['SELECCIONAR_CAJA'] ?? null) == 1;

        if (!$esApp) {
            $terminal = TerminalPos::query()->where('fk_soc', $user->rowid)->first();

            if (!$terminal) {
                return [403, ['error' => 'Usuario no tiene terminal configurada.']];
            }

            if ((int) $terminal->is_used === 1) {
                return [403, ['error' => 'Terminal bloqueada.']];
            }
        }

        $ahora = Carbon::now();

        $sesionActiva = SesionDispositivo::query()
            ->where('fk_user', $user->rowid)
            ->where('revoked', 0)
            ->where('expires_at', '>', $ahora)
            ->orderByDesc('id')
            ->first();

        if ($sesionActiva && $sesionActiva->device_id !== $deviceName) {
            return [423, [
                'error' => 'USUARIO RELACIONADO CON UN ID DE DISPOSITIVO - El usuario ya tiene una sesión activa en el equipo ['.$sesionActiva->device_id.'].',
            ]];
        }

        // Un token por device_name: revoca los anteriores con el mismo nombre.
        $user->tokens()->where('name', $deviceName)->delete();

        $sesion = $this->issueSession($user, $deviceName);

        $this->registrarSesionDispositivo($user, $deviceName, $sesion, $sesionActiva, $ahora, $ip, $versionPos);

        $registroLogin = new PosLoginLogout();
        $registroLogin->fk_user = $user->rowid;
        $registroLogin->date_login = $ahora;
        $registroLogin->save();

        return [200, $sesion];
    }

    /**
     * Renueva la sesión a partir de un refresh token válido.
     *
     * @return array<string,mixed>|null  null si el refresh token es inválido o expiró
     */
    public function refresh(string $refreshToken): ?array
    {
        /** @var PersonalAccessToken|null $token */
        $token = PersonalAccessToken::where('refresh_token', hash('sha256', $refreshToken))->first();

        if (! $token
            || ($token->refresh_expires_at && Carbon::parse($token->refresh_expires_at)->isPast())) {
            return null;
        }

        /** @var DolibarrUser $user */
        $user = $token->tokenable;
        $deviceName = $token->name;

        // Rota: elimina el token actual y emite uno nuevo.
        $token->delete();

        return $this->issueSession($user, $deviceName);
    }

    /**
     * Emite access + refresh token y arma el payload completo. device_name
     * es a la vez el "slot" de Sanctum (un token activo por device_name) y
     * el identificador que usa el candado de un solo dispositivo activo
     * (integros_user_token); se devuelve tal cual en "device_id".
     *
     * @return array<string,mixed>
     */
    private function issueSession(DolibarrUser $user, string $deviceName): array
    {
        $accessPlain  = bin2hex(random_bytes(32));   // 64 hex
        $refreshPlain = bin2hex(random_bytes(32));   // 64 hex

        $expiresAt = Carbon::tomorrow();             // 00:00 del día siguiente

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->create([
            'name'       => $deviceName,
            'token'      => hash('sha256', $accessPlain),
            'abilities'  => ['*'],
            'expires_at' => $expiresAt,
        ]);

        // Columnas propias (no están en el $fillable de Sanctum).
        $token->forceFill([
            'refresh_token'      => hash('sha256', $refreshPlain),
            'refresh_expires_at' => $expiresAt->copy()->addDays(self::REFRESH_DAYS),
            'device_id'          => $deviceName,
        ])->save();

        $caja = $this->estadoCaja($user);

        return [
            'access_token'  => $accessPlain,
            'refresh_token' => $refreshPlain,
            'expires_at'    => $expiresAt->format('Y-m-d H:i:s'),
            'device_id'     => $deviceName,
            'user_id'       => (string) $user->getKey(),
            'APIKEY'        => DolibarrCrypt::decrypt((string) $user->getAttribute('api_key')),
            'apertura'      => $caja['apertura'],
            'cierre'        => $caja['cierre'],
            'continuar'     => $caja['continuar'],
            'proveedoresAsignados' => $this->proveedoresAsignados($user),
        ];
    }

    /**
     * Terceros con fournisseur=1 asignados al usuario como vendedor
     * (societe_commerciaux.fk_user).
     *
     * @return array<int, array<string,mixed>>
     */
    private function proveedoresAsignados(DolibarrUser $user): array
    {
        $idsTerceros = SociedadComercial::query()
            ->where('fk_user', $user->rowid)
            ->pluck('fk_soc')
            ->unique()
            ->values()
            ->all();

        if (empty($idsTerceros)) {
            return [];
        }

        return Sociedad::query()
            ->whereIn('rowid', $idsTerceros)
            ->where('fournisseur', 1)
            ->get(['rowid', 'nom', 'tva_intra', 'phone'])
            ->map(fn ($proveedor) => [
                'id' => (int) $proveedor->rowid,
                'nombre' => $proveedor->nom,
                'rif' => $proveedor->tva_intra,
                'telefono' => $proveedor->phone,
            ])
            ->values()
            ->all();
    }

    /**
     * POST /api.php/integrospos/terminales/integrosTokenOpen
     *
     * El POS reporta desde que equipo esta trabajando: si el usuario tiene una
     * sesion activa (integros_user_token no revocada y sin expirar) en ese
     * device_id, se actualizan la IP local, la version del POS, el servidor
     * local y last_seen_at.
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function registrarActividadDispositivo(DolibarrUser $user, array $payload): array
    {
        $deviceId = trim((string) ($payload['device_id'] ?? ''));

        if ($deviceId === '') {
            return [400, ['error' => 'PARÁMETROS INCOMPLETOS: device_id es obligatorio.']];
        }

        if (isset($payload['idusuario']) && (int) $payload['idusuario'] !== (int) $user->rowid) {
            return [403, ['error' => 'EL idusuario NO CORRESPONDE AL USUARIO DE LA DOLAPIKEY.']];
        }

        $ahora = Carbon::now();

        $sesion = SesionDispositivo::query()
            ->where('fk_user', $user->rowid)
            ->where('device_id', $deviceId)
            ->where('revoked', 0)
            ->where('expires_at', '>', $ahora)
            ->orderByDesc('id')
            ->first();

        if (!$sesion) {
            return [404, ['error' => 'NO HAY SESIÓN ACTIVA PARA ESTE USUARIO EN EL DISPOSITIVO ['.$deviceId.'].']];
        }

        $ip = trim((string) ($payload['ip_local'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ''));
        $servidorLocal = trim((string) ($payload['servidor_local'] ?? ''));

        if ($ip !== '') {
            $sesion->ip = mb_substr($ip, 0, 45);
        }
        if ($version !== '') {
            $sesion->version_pos = mb_substr($version, 0, 50);
        }
        if ($servidorLocal !== '') {
            $sesion->servidor_local = mb_substr($servidorLocal, 0, 255);
        }
        $sesion->last_seen_at = $ahora;
        $sesion->save();

        return [200, [
            'success' => true,
            'user_id' => (int) $user->rowid,
            'device_id' => $sesion->device_id,
            'ip' => $sesion->ip,
            'version' => $sesion->version_pos,
            'servidor_local' => $sesion->servidor_local,
            'last_seen_at' => $ahora->format('Y-m-d H:i:s'),
        ]];
    }

    /**
     * Mantiene al dia el registro de candado de dispositivo (integros_user_token):
     * si ya habia una sesion activa para este mismo dispositivo, la cierra
     * (revoked=1, igual que el legado) y guarda ahi mismo los datos del
     * nuevo token emitido; si no habia ninguna, crea una fila nueva.
     */
    private function registrarSesionDispositivo(
        DolibarrUser $user,
        string $deviceName,
        array $sesion,
        ?SesionDispositivo $sesionActiva,
        Carbon $ahora,
        ?string $ip,
        ?string $versionPos
    ): void {
        $tokenHash = hash('sha256', $sesion['access_token']);
        $refreshHash = hash('sha256', $sesion['refresh_token']);

        $registro = $sesionActiva ?? new SesionDispositivo();

        if (!$sesionActiva) {
            $registro->fk_user = $user->rowid;
            $registro->device_id = $deviceName;
        }

        $registro->revoked = 0;
        $registro->token_hash = $tokenHash;
        $registro->refresh_hash = $refreshHash;
        $registro->expires_at = $sesion['expires_at'];
        $registro->last_seen_at = $ahora;
        $registro->ip = $ip !== null ? mb_substr($ip, 0, 45) : null;
        $registro->version_pos = $versionPos !== null ? mb_substr($versionPos, 0, 50) : null;
        $registro->save();
    }

    /**
     * Estado de caja del cajero (apertura/cierre/continuar).
     *
     * Réplica de la validación original de IntegrosPOS sobre
     * integrospos_terminal_aperturacierre.
     *
     * @return array{apertura: bool, cierre: bool, continuar: bool}
     */
    private function estadoCaja(DolibarrUser $user): array
    {
        $hoy = Carbon::today()->format('Y-m-d');

        $row = DB::connection('mysql')
            ->table('integrospos_terminal_aperturacierre')
            ->where('fk_user', $user->getKey())
            ->where(function ($q) use ($hoy) {
                $q->where('estado', 1)
                    ->orWhereRaw('DATE(fecha_apertura) = ?', [$hoy]);
            })
            ->orderByDesc('rowid')
            ->first();

        $apertura  = true;
        $cierre    = false;
        $continuar = true;

        if ($row) {
            $apertura = false;

            $diffDays = (int) abs(
                Carbon::parse($row->fecha_apertura)->startOfDay()
                    ->diffInDays(Carbon::today())
            );

            if ((int) $row->estado === 1 && $diffDays >= 1) {
                // Caja abierta de un día anterior: hay que cerrarla.
                $cierre = true;
                $continuar = false;
            } elseif ((int) $row->estado === 2) {
                // Caja ya cerrada.
                $continuar = false;
            }
        }

        return compact('apertura', 'cierre', 'continuar');
    }

    /**
     * Verifica la contraseña contra el hash almacenado por Dolibarr.
     *
     * Dolibarr moderno guarda pass_crypted con password_hash() (bcrypt, $2y$).
     * Instalaciones/usuarios legacy pueden tener MD5 (32 hex) o incluso el
     * campo `pass` en texto plano. Se contemplan los tres casos.
     */
    public function verifyPassword(string $password, DolibarrUser $user): bool
    {
        $hash = (string) $user->getAuthPassword(); // pass_crypted

        // 1. bcrypt / algoritmos soportados por password_hash()
        if ($hash !== '' && password_get_info($hash)['algo'] !== null) {
            return password_verify($password, $hash);
        }

        // 2. MD5 legacy
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }

        // 3. Texto plano en `pass` (config Dolibarr insegura, último recurso)
        $plain = (string) ($user->getAttribute('pass') ?? '');
        if ($plain !== '') {
            return hash_equals($plain, $password);
        }

        return false;
    }

    /**
     * Replica Logout(): revoca el token actual (Sanctum) y cierra el ultimo
     * registro de pos_login_logout que siga sin date_logout. A diferencia
     * del original, el usuario no viene de un parametro user_id del body
     * (evita que un cliente cierre la sesion de otro usuario) sino del
     * usuario ya autenticado por el access_token.
     *
     * @return array<string,mixed>
     */
    public function logout(DolibarrUser $user): array
    {
        $fechaLogout = Carbon::now();

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        // Cierra el candado de dispositivo (integros_user_token): la ultima
        // fila de este usuario queda revoked=1 y con cierre_sesion, liberando
        // el dispositivo.
        SesionDispositivo::query()
            ->where('fk_user', $user->rowid)
            ->orderByDesc('id')
            ->limit(1)
            ->update(['revoked' => 1, 'cierre_sesion' => $fechaLogout]);

        $ultimoLogin = PosLoginLogout::query()
            ->where('fk_user', $user->rowid)
            ->whereNull('date_logout')
            ->orderByDesc('date_login')
            ->first();

        if (!$ultimoLogin) {
            return ['error' => 'No se encontró un login activo para este usuario.'];
        }

        $ultimoLogin->date_logout = $fechaLogout;
        $ultimoLogin->save();

        return [
            'success' => true,
            'user_id' => $user->rowid,
            'date_logout' => $fechaLogout->format('Y-m-d H:i:s'),
        ];
    }
}
