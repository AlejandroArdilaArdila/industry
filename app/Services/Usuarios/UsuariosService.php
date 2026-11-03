<?php

namespace App\Services\Usuarios;

use App\Models\Almacen;
use App\Models\Configuracion;
use App\Models\Permiso;
use App\Models\PosControlCash;
use App\Models\Sociedad;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UsuariosService
{
    private const MODULO_INTEGROSPOS = 'integrospos';

    private const MODULO_INTEGROSAPK = 'iapk';

    private const MODULO_INTEGROS = 'integros';

    /**
     * Busca el usuario dueño del header DOLAPIKEY, con sus permisos de
     * integrospos, iapk e integros ya cargados (tieneDerecho() no discrimina
     * por modulo, solo por perms/subperms). Aborta con 401 si falta el
     * header o no corresponde a ningun usuario activo.
     */
    public function obtenerPorApiKey(Request $request): Usuario
    {
        $apiKey = $request->header('DOLAPIKEY');

        if (! $apiKey) {
            abort(401, "Falta el header 'DOLAPIKEY'");
        }

        $usuario = $this->buscarPorApiKey($apiKey);

        if (! $usuario) {
            abort(401, 'DOLAPIKEY inválida');
        }

        return $usuario;
    }

    /**
     * Igual que obtenerPorApiKey() pero sin abortar: devuelve null si falta
     * la key o no corresponde a ningun usuario activo. Para endpoints que
     * aceptan mas de un tipo de DOLAPIKEY (por ejemplo, usuario interno o
     * proveedor) y necesitan decidir cual aplica antes de responder 401.
     */
    public function buscarPorApiKey(?string $apiKey): ?Usuario
    {
        if (! $apiKey) {
            return null;
        }

        $modulos = [self::MODULO_INTEGROSPOS, self::MODULO_INTEGROSAPK, self::MODULO_INTEGROS];

        return Usuario::query()
            ->where('statut', 1)
            ->where(function ($q) use ($apiKey) {
                $q->where('api_key', $apiKey)
                    ->orWhere('api_key', $this->cifrarApiKeyEstiloDolibarr($apiKey));
            })
            ->select(['rowid', 'login', 'firstname', 'lastname'])
            ->with(['permisos' => function ($q) use ($modulos) {
                $q->whereIn('module', $modulos)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->with(['grupos' => function ($q) {
                $q->select('usergroup.rowid');
            }])
            ->with(['grupos.permisos' => function ($q) use ($modulos) {
                $q->whereIn('module', $modulos)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->first();
    }

    /**
     * Replica dolEncrypt($apiKey, '', '', 'dolibarr'): Dolibarr usa un IV
     * fijo (derivado del seed 'dolibarr') para poder buscar el usuario
     * por api_key sin tener que desencriptar toda la tabla.
     */
    private function cifrarApiKeyEstiloDolibarr(string $apiKey): string
    {
        $clave = config('services.dolibarr.instance_key');
        $cifrado = 'AES-256-CTR';
        $iv = substr(md5('dolibarr'), 0, openssl_cipher_iv_length($cifrado));

        return 'dolcrypt:'.$cifrado.':'.$iv.':'.openssl_encrypt($apiKey, $cifrado, (string) $clave, 0, $iv);
    }

    /**
     * Valida un apiKey suelto recibido en el body (no en el header DOLAPIKEY)
     * contra los usuarios activos. Replica el endpoint legacy validate_apikey:
     * nunca aborta, siempre responde un arreglo con 'ok' indicando si la key
     * es valida (el mismo query indexado que obtenerPorApiKey(), en vez del
     * decrypt-y-compara-uno-por-uno del original en PHP puro).
     */
    public function validarApiKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'APIKEY requerida'];
        }

        $usuario = Usuario::query()
            ->where('statut', 1)
            ->where(function ($q) use ($apiKey) {
                $q->where('api_key', $apiKey)
                    ->orWhere('api_key', $this->cifrarApiKeyEstiloDolibarr($apiKey));
            })
            ->select(['rowid', 'login', 'firstname', 'lastname', 'admin'])
            ->first();

        if (! $usuario) {
            return ['ok' => false, 'error' => 'APIKEY inválida'];
        }

        $partes = array_filter([$usuario->firstname, $usuario->lastname]);
        $nombre = implode(' ', $partes) ?: $usuario->login;

        return [
            'ok' => true,
            'user' => [
                'id' => (int) $usuario->rowid,
                'login' => $usuario->login,
                'nombre' => $nombre,
                'admin' => (int) $usuario->admin,
            ],
        ];
    }

    /**
     * Valida un pin_admin (user_extrafields.pin_admin) y devuelve a que
     * usuario pertenece. Replica autorizacionPos() de api_pos.lib.php.
     */
    public function autorizarPin(Request $request, Usuario $usuarioAutenticado): array
    {
        if (! $usuarioAutenticado->tieneDerecho('backend', 'read')) {
            abort(409, 'Este usuario no tiene permisos para esta acción.');
        }

        $pin = (string) $request->input('pin', '');
        $userId = $request->input('user');

        $usuario = Usuario::query()
            ->whereHas('extrafields', fn ($q) => $q->where('pin_admin', $pin))
            ->select(['rowid', 'firstname', 'lastname'])
            ->first();

        if (! $usuario) {
            if ($request->has('bandera')) {
                return ['message' => 'Pin no encontrado', 'status' => 1];
            }

            abort(409, 'Pin no autorizado');
        }

        if ($userId == $usuario->rowid) {
            return ['message' => 'Pin valido para este usuario', 'status' => 1];
        }

        return [
            'message' => 'Pin valido',
            'nombre' => trim($usuario->firstname.' '.$usuario->lastname),
            'status' => 0,
        ];
    }

    public function getUsersPos(Request $request)
    {
        $idUsuario = (int) $request->input('idUsuario', 0);

        // Cliente predeterminado (const CLIENTE_PREDETERMINADO): es el mismo
        // para todos los usuarios, se resuelve una sola vez fuera del loop en
        // vez de repetirlo por cada usuario de la lista.
        $idCliente = 'SIN CLIENTE PREDETERMINADO';
        $documentoCliente = 'SIN CLIENTE PREDETERMINADO';
        $nombreCliente = 'SIN CLIENTE PREDETERMINADO';
        $clientePredeterminado = Configuracion::valores(['CLIENTE_PREDETERMINADO'])['CLIENTE_PREDETERMINADO'] ?? null;

        if ($clientePredeterminado !== null && $clientePredeterminado !== '') {
            $cliente = Sociedad::query()
                ->select(['rowid', 'nom', 'siren'])
                ->find((int) $clientePredeterminado);

            if ($cliente) {
                $idCliente = (int) $cliente->rowid;
                $documentoCliente = (int) $cliente->siren;
                $nombreCliente = $cliente->nom;
            }
        }

        $usuarios = Usuario::query()
            ->where('statut', 1)
            ->when($idUsuario > 0, fn ($q) => $q->where('rowid', $idUsuario))
            ->select(['rowid', 'login', 'pass_crypted', 'api_key', 'admin', 'firstname', 'lastname'])
            ->with(['extrafields' => function ($q) {
                $q->select('fk_object', 'pin_admin');
            }])
            ->with(['terminal' => function ($q) {
                $q->select('rowid', 'fk_soc', 'datafono_codigo_terminal', 'datafono_id_cajero', 'datafono_numero_caja', 'fk_warehouse', 'fk_resolucion_factura', 'fk_resolucion_pos', 'fk_resolucion_borrador');
            }])
            // Bloques de sucursal (variable global SUCURSALES): solo hay filas aqui
            // si el cajero ya se guardo con el formulario nuevo por bloques.
            ->with(['terminal.sucursales' => function ($q) {
                $q->select('rowid', 'fk_cash', 'fk_warehouse_sucursal', 'fk_resolucion_factura', 'fk_resolucion_pos', 'fk_resolucion_borrador', 'datafono_codigo_terminal', 'datafono_id_cajero', 'datafono_numero_caja');
            }])
            ->with(['terminal.sucursales.sucursal' => function ($q) {
                $q->select('rowid', 'ref');
            }])
            ->with(['terminal.sucursales.almacenes' => function ($q) {
                $q->select('rowid', 'fk_cash_sucursal', 'fk_warehouse');
            }])
            ->with(['terminal.sucursales.almacenes.almacen' => function ($q) {
                $q->select('rowid', 'ref');
            }])
            ->with(['permisos' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSPOS)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->with(['grupos' => function ($q) {
                $q->select('usergroup.rowid');
            }])
            ->with(['grupos.permisos' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSPOS)
                    ->select('rights_def.perms', 'rights_def.subperms');
            }])
            ->get();

        $resolucionesPorTipo = $this->resolucionesAsignadas($usuarios);

        // Nombres de almacen para el bloque sintetico de cajeros SIN sucursales
        // (modo clasico), batched en una sola query para no caer en N+1.
        $idsAlmacenClasico = $usuarios->pluck('terminal.fk_warehouse')->filter(fn ($id) => (int) $id > 0)->unique();
        $almacenesPorId = Almacen::query()->whereIn('rowid', $idsAlmacenClasico)->pluck('ref', 'rowid');

        $listUsers = [];

        foreach ($usuarios as $usuario) {
            $idUser = (int) $usuario->rowid;
            $terminal = $usuario->terminal;
            $idTerminal = $terminal ? (int) $terminal->rowid : 0;
            $estadoCaja = $this->estadoCajaPos($idTerminal, $idUser);

            // Bloques de sucursal (SUCURSALES activa). Si el cajero no tiene ninguno
            // guardado todavia, sigue en el modelo de siempre: un unico almacen/
            // datafono/resolucion en la propia terminal.
            $sucursalesPayload = [];
            foreach ($terminal?->sucursales ?? [] as $s) {
                $almacenes = $s->almacenes->isNotEmpty()
                    ? $s->almacenes->map(fn ($a) => [
                        'rowid' => (int) $a->fk_warehouse,
                        'almacen' => $a->almacen->ref ?? '',
                    ])->values()->all()
                    // Sin almacenes hijos: la sucursal es su propio almacen.
                    : [[
                        'rowid' => (int) $s->fk_warehouse_sucursal,
                        'almacen' => $s->sucursal->ref ?? '',
                    ]];

                $sucursalesPayload[] = [
                    'idSucursal' => (int) $s->fk_warehouse_sucursal,
                    'nombreSucursal' => $s->sucursal->ref ?? '',
                    'almacenes' => $almacenes,
                    'datafonoTerminal' => [
                        'codigoDeTerminal' => $s->datafono_codigo_terminal ?? '',
                        'idCajero' => $s->datafono_id_cajero ?? '',
                        'numeroDeCaja' => $s->datafono_numero_caja ?? '',
                    ],
                    'resolucion' => [
                        'factura' => $resolucionesPorTipo[1][(int) $s->fk_resolucion_factura] ?? null,
                        'pos' => $resolucionesPorTipo[2][(int) $s->fk_resolucion_pos] ?? null,
                        'borrador' => $resolucionesPorTipo[3][(int) $s->fk_resolucion_borrador] ?? null,
                    ],
                ];
            }

            // Cajero clasico (sin bloques de sucursal guardados): sintetizar un unico
            // bloque con su almacen/datafono/resolucion de siempre, para que el
            // cliente SIEMPRE lea de 'sucursales' sin importar el modo — nunca hay
            // datos de almacen/datafono/resolucion fuera de este array.
            if (empty($sucursalesPayload) && $terminal && (int) $terminal->fk_warehouse > 0) {
                $nombreAlmacen = $almacenesPorId[$terminal->fk_warehouse] ?? '';
                $sucursalesPayload[] = [
                    'idSucursal' => (int) $terminal->fk_warehouse,
                    'nombreSucursal' => $nombreAlmacen,
                    'almacenes' => [[
                        'rowid' => (int) $terminal->fk_warehouse,
                        'almacen' => $nombreAlmacen,
                    ]],
                    'datafonoTerminal' => [
                        'codigoDeTerminal' => $terminal->datafono_codigo_terminal ?? '',
                        'idCajero' => $terminal->datafono_id_cajero ?? '',
                        'numeroDeCaja' => $terminal->datafono_numero_caja ?? '',
                    ],
                    'resolucion' => [
                        'factura' => $resolucionesPorTipo[1][(int) $terminal->fk_resolucion_factura] ?? null,
                        'pos' => $resolucionesPorTipo[2][(int) $terminal->fk_resolucion_pos] ?? null,
                        'borrador' => $resolucionesPorTipo[3][(int) $terminal->fk_resolucion_borrador] ?? null,
                    ],
                ];
            }

            $listUsers[] = [
                'rowid' => $usuario->rowid,
                'login' => $usuario->login,
                'pass_crypted' => $usuario->pass_crypted,
                'api_key' => $usuario->api_key,
                'pin_admin' => $usuario->extrafields?->pin_admin ?: 0,
                'admin' => (int) $usuario->admin === 1,
                'fullname' => trim($usuario->firstname.' '.$usuario->lastname),
                'idVendedor' => $idUser,
                'codigoVendedor' => (string) $idUser,
                'nombreVendedor' => trim($usuario->firstname.' '.$usuario->lastname),
                'idTerminal' => $idTerminal,
                'idCliente' => $idCliente,
                'documentoCliente' => $documentoCliente,
                'nombreCliente' => $nombreCliente,
                'cierreCaja' => $estadoCaja['cierre'],
                'aperturaCaja' => $estadoCaja['apertura'],
                'reAperturaCaja' => $estadoCaja['reapertura'],
                // Unica fuente de almacen/datafono/resolucion, con o sin SUCURSALES
                // activa: siempre trae al menos un bloque cuando el cajero tiene
                // terminal (real si usa Sucursales, sintetico desde la propia
                // terminal si no) — nunca hay estos datos duplicados fuera de aqui.
                'sucursales' => $sucursalesPayload,
                'permisos' => [
                    'pinCajon' => $usuario->tieneDerecho('pinCajon', 'write'),
                    'pinRimprimir' => $usuario->tieneDerecho('pinReimprimir', 'write'),
                    'pinDevoluciones' => $usuario->tieneDerecho('pinDevoluciones', 'write'),
                    'pinSumarRestar' => $usuario->tieneDerecho('pinSumarRestar', 'write'),
                    'pinEliminar' => $usuario->tieneDerecho('pinEliminar', 'write'),
                    'pedidosProvisionales' => $usuario->tieneDerecho('pedidosProvisionales', 'write'),
                    'aplicaDevoluciones' => $usuario->tieneDerecho('devoluciones', 'write'),
                    'nivelPrecioPinUsuario' => $usuario->tieneDerecho('pinNivelPrecios', 'write'),
                    'devoluciones' => $usuario->tieneDerecho('devoluciones', 'write'),
                    'entradasSalidas' => $usuario->tieneDerecho('entradasSalidas', 'read'),
                    // Migrados desde pos/configuracion (ConfiguracionService::getConfiguracion):
                    // ese endpoint dejo de traer permisos, todos quedan consolidados aca.
                    'eliminarCotizacion' => $usuario->tieneDerecho('eliminarCotizacion', 'write'),
                    'descuentoGlobal' => $usuario->tieneDerecho('descuentos', 'write'),
                    'abonoFacturas' => $usuario->tieneDerecho('abonoFacturas', 'write'),
                    'redencionPuntos' => $usuario->tieneDerecho('redencionPuntos', 'read'),
                    // Resto del catalogo del modulo integrospos (rights_def) que todavia
                    // no se enviaba aca: sin estos, el cliente no tenia forma de saber si
                    // el usuario los tiene o no.
                    'aperturaCaja' => $usuario->tieneDerecho('aperturaCaja', 'read'),
                    'aperturas' => $usuario->tieneDerecho('aperturas', 'read'),
                    'backend' => $usuario->tieneDerecho('backend', 'read'),
                    'crearCierres' => $usuario->tieneDerecho('cierre', 'write'),
                    'verCierres' => $usuario->tieneDerecho('cierre', 'read'),
                    'domicilios' => $usuario->tieneDerecho('domicilios', 'read'),
                    'domiciliosAsignados' => $usuario->tieneDerecho('domiciliosAsignados', 'read'),
                    'exportarEntradasSalidas' => $usuario->tieneDerecho('entradasSalidas', 'export'),
                    'facturas' => $usuario->tieneDerecho('facturas', 'read'),
                    'frontend' => $usuario->tieneDerecho('frontend', ''),
                    'impresionF8' => $usuario->tieneDerecho('impresionF8', 'write'),
                    'informeVentas' => $usuario->tieneDerecho('informeVentas', 'read'),
                    'logUsuarios' => $usuario->tieneDerecho('logUsuarios', 'read'),
                    'pinDescuentoGlobal' => $usuario->tieneDerecho('pinDescuentoGlobal', 'write'),
                    'pinEliminarCotizacion' => $usuario->tieneDerecho('pinEliminarCotizacion', 'write'),
                    'pinEditarCliente' => $usuario->tieneDerecho('pinEditarCliente', 'write'),
                    'pinEditarProductos' => $usuario->tieneDerecho('pinEditarProductos', 'write'),
                    'corregirPrecio' => $usuario->tieneDerecho('corregirPrecio', 'write'),
                    'productosRedimidos' => $usuario->tieneDerecho('productosRedimidos', 'read'),
                    'promo' => $usuario->tieneDerecho('promo', 'read'),
                    'terminal' => $usuario->tieneDerecho('terminal', 'write'),
                    'ticketVariables' => $usuario->tieneDerecho('ticketVariables', 'write'),
                ],
            ];
        }

        return $listUsers;
    }

    /**
     * Trae de una sola vez (sin N+1 por usuario) los datos de las resoluciones
     * DIAN asignadas en pos_cash.fk_resolucion_{factura,pos,borrador} para los
     * usuarios ya cargados. Cada tipo de documento vive en su propia tabla
     * (facturacione_config_invoice[_pos|_borrador], mismo mapeo que
     * ResolucionFactura::TABLAS_POR_TIPO). Solo lo que esta explicitamente
     * asignado en la terminal - sin caer a la resolucion activa global si no
     * hay nada asignado (a diferencia de como numera sp_pos_numerar_factura).
     *
     * @return array<int, array<int, array<string, mixed>>> [tipoFac => [rowid => datos]]
     */
    private function resolucionesAsignadas(Collection $usuarios): array
    {
        $tablasPorTipo = [
            1 => 'facturacione_config_invoice',
            2 => 'facturacione_config_invoice_pos',
            3 => 'facturacione_config_invoice_borrador',
        ];

        $columnaPorTipo = [
            1 => 'fk_resolucion_factura',
            2 => 'fk_resolucion_pos',
            3 => 'fk_resolucion_borrador',
        ];

        $resultado = [];

        foreach ($tablasPorTipo as $tipoFac => $tabla) {
            $columna = $columnaPorTipo[$tipoFac];
            $idsTerminal = $usuarios->pluck('terminal.'.$columna);
            // Ademas de la resolucion unica de la terminal, cada bloque de sucursal
            // (SUCURSALES activa) trae la suya propia - hay que traerlas todas en el
            // mismo SELECT batched para no volver a caer en N+1 por sucursal.
            $idsSucursales = $usuarios->flatMap(function ($u) use ($columna) {
                return $u->terminal?->sucursales?->pluck($columna) ?? collect();
            });
            $ids = $idsTerminal->merge($idsSucursales)->filter(fn ($id) => (int) $id > 0)->unique()->values();

            $resultado[$tipoFac] = [];

            if ($ids->isEmpty()) {
                continue;
            }

            $columnas = ['rowid', 'prefijo', 'resolucion', 'tipo_factura', 'de', 'a', 'fecha_desde', 'fecha_hasta', 'estado'];

            if ($tipoFac === 3) {
                $columnas[] = 'info_qr';
            }

            // Datos tecnicos DIAN para calcular el CUFE/CUDE: son globales (llx_const,
            // no varian por resolucion ni por usuario), asi que se resuelven una sola
            // vez por tipo aqui afuera del loop de filas, no dentro de formatearResolucion().
            $datosTecnicos = $this->datosTecnicosDian($tipoFac);

            // Prefijos de nota credito por resolucion (mismo numero de resolucion que
            // la factura, en su propia tabla de config), para no consultar por fila.
            $prefijosNcPorResolucion = $this->prefijosNotaCreditoPorResolucion($tipoFac);

            foreach (DB::connection('mysql')->table($tabla)->whereIn('rowid', $ids)->get($columnas) as $fila) {
                $resultado[$tipoFac][(int) $fila->rowid] = $this->formatearResolucion($fila, $prefijosNcPorResolucion) + $datosTecnicos;
            }
        }

        return $resultado;
    }

    /**
     * Prefijo de nota credito para cada numero de resolucion activo, segun el
     * tipo de documento (1=factura -> facturacione_config_creditnote,
     * 2=pos -> ..._pos, 3=borrador -> ..._borrador). La nota credito comparte
     * el mismo numero de resolucion DIAN que su factura, pero vive en su
     * propia tabla con su propio prefijo.
     *
     * @return array<string, array{prefijo: string, de: int}> [resolucion => datos]
     */
    private function prefijosNotaCreditoPorResolucion(int $tipoFac): array
    {
        $tablasCreditNotaPorTipo = [
            1 => 'facturacione_config_creditnote',
            2 => 'facturacione_config_creditnote_pos',
            3 => 'facturacione_config_creditnote_borrador',
        ];

        // tipo_factura (1=normal, 2=contingencia/provisional, etc.) es una
        // clasificacion independiente del canal (factura/pos/borrador, que ya
        // separa la tabla): una fila de NC solo sirve para una factura de su
        // MISMO tipo_factura, nunca de otro, aunque compartan resolucion (por
        // ejemplo la resolucion comodin "0"). Por eso se agrupa por tipo_factura
        // antes de indexar por resolucion.
        return DB::connection('mysql')->table($tablasCreditNotaPorTipo[$tipoFac])
            ->where('estado', 1)
            ->get(['resolucion', 'prefijo', 'de', 'tipo_factura'])
            ->groupBy('tipo_factura')
            ->map(fn ($filas) => $filas->keyBy('resolucion')
                ->map(fn ($fila) => ['prefijo' => $fila->prefijo, 'de' => (int) $fila->de])
                ->all())
            ->all();
    }

    private function formatearResolucion(object $fila, array $prefijosNcPorResolucion = []): array
    {
        // Solo se busca entre las NC del mismo tipo_factura que esta factura
        // (ver comentario en prefijosNotaCreditoPorResolucion). Dentro de ese
        // subconjunto, si no hay una NC con el mismo numero de resolucion
        // (caso normal), cae a la resolucion "0" como comodin generico - misma
        // logica sea que la resolucion de la factura venga asignada por usuario
        // (UsuariosService) o por caja (ConfiguracionService).
        $prefijosNcDeEsteTipo = $prefijosNcPorResolucion[(int) $fila->tipo_factura] ?? [];
        $notaCredito = $prefijosNcDeEsteTipo[$fila->resolucion] ?? $prefijosNcDeEsteTipo['0'] ?? null;
        $prefijoNc = $notaCredito['prefijo'] ?? null;

        return [
            'prefijo' => $fila->prefijo,
            'resolucion' => (string) $fila->resolucion,
            'rangoDesde' => (int) $fila->de,
            'rangoHasta' => (int) $fila->a,
            'fechaDesde' => $fila->fecha_desde,
            'fechaHasta' => $fila->fecha_hasta,
            'activa' => (int) $fila->estado === 1,
            'infoQr' => $fila->info_qr ?? ".",
            'ultimoConsecutivo' => $this->ultimoConsecutivo($fila->prefijo, (int) $fila->de),
            'prefijoNc' => $prefijoNc,
            'ultimaNotaCredito' => $prefijoNc !== null ? $this->ultimaNotaCredito($prefijoNc, $notaCredito['de']) : null,
        ];
    }

    /**
     * Parametros tecnicos DIAN (llx_const, mismos nombres que usa
     * sp_pos_dian_armar_json_factura_interna para calcular el CUFE/CUDE):
     * softwareId, pin y claveTecnica identifican el software ante la DIAN;
     * entorno indica produccion (1) o habilitacion/pruebas (2). Sin estos
     * datos el CUFE que calcule el POS localmente no coincidiria con el que
     * calcularia el servidor, asi que van sueltos aqui para que el servidor
     * local pueda generar la factura electronica sin depender del API por
     * cada venta. tipo_fac=3 (borrador) no usa CUFE real (es un valor
     * aleatorio de relleno), no aplica.
     */
    private function datosTecnicosDian(int $tipoFac): array
    {
        if ($tipoFac === 1) {
            $valores = Configuracion::valores(['FACTURACIONE_MYPARAM1', 'FACTURACIONE_MYPARAM3', 'FACTURACIONE_MYPARAM4', 'FACTURACIONE_MYPARAM5']);

            return [
                'softwareId' => $valores['FACTURACIONE_MYPARAM1'] ?? null,
                'claveTecnica' => $valores['FACTURACIONE_MYPARAM3'] ?? null,
                'pin' => $valores['FACTURACIONE_MYPARAM4'] ?? null,
                'entorno' => $valores['FACTURACIONE_MYPARAM5'] ?? null,
            ];
        }

        if ($tipoFac === 2) {
            $valores = Configuracion::valores(['POS_IDSOFTWARE', 'POS_PIN', 'POS_ENTORNO']);
            $pin = $valores['POS_PIN'] ?? null;

            return [
                'softwareId' => $valores['POS_IDSOFTWARE'] ?? null,
                // Para documento POS la clave tecnica del hash del CUFE es el mismo PIN
                // (ver v_technical_key = v_pin en sp_pos_dian_armar_json_factura_interna).
                'claveTecnica' => $pin,
                'pin' => $pin,
                'entorno' => $valores['POS_ENTORNO'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Mismo calculo que sp_pos_numerar_factura para "v_ultimo": el numero mas
     * alto ya usado en llx_facture.ref con ese prefijo (ignorando borradores
     * "(PROVxxx)" y refs cuyo sufijo no es puramente numerico). Si todavia no
     * se ha facturado nada en el rango, devuelve rangoDesde-1 (el proximo
     * consecutivo a asignar seria rangoDesde), igual que hace el SP.
     */
    private function ultimoConsecutivo(string $prefijo, int $rangoDesde): int
    {
        $longitudPrefijo = strlen($prefijo);

        $fila = DB::connection('mysql')->selectOne('
            SELECT CAST(SUBSTRING(ref, ?) AS UNSIGNED) AS ultimo
            FROM llx_facture
            WHERE ref COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, \'%\')
              AND ref COLLATE utf8mb4_unicode_ci NOT LIKE \'%PROV%\'
              AND SUBSTRING(ref, ?, 1) REGEXP \'^[0-9]$\'
            ORDER BY CAST(SUBSTRING(ref, ?) AS UNSIGNED) DESC
            LIMIT 1
        ', [$longitudPrefijo + 1, $prefijo, $longitudPrefijo + 1, $longitudPrefijo + 1]);

        $ultimo = (int) ($fila->ultimo ?? 0);

        return $ultimo < $rangoDesde ? $rangoDesde - 1 : $ultimo;
    }

    /**
     * Ref de la ultima nota credito emitida (llx_facture.type=2) con ese
     * prefijo. Si todavia no se ha emitido ninguna, devuelve prefijo+rangoDesde
     * (la primera del rango de la resolucion), igual criterio que
     * ultimoConsecutivo() usa para "nada facturado todavia".
     */
    private function ultimaNotaCredito(string $prefijoNc, int $rangoDesde): string
    {
        $longitudPrefijo = strlen($prefijoNc);

        $fila = DB::connection('mysql')->selectOne('
            SELECT ref
            FROM llx_facture
            WHERE type = 2
              AND ref COLLATE utf8mb4_unicode_ci LIKE CONCAT(?, \'%\')
              AND ref COLLATE utf8mb4_unicode_ci NOT LIKE \'%PROV%\'
              AND SUBSTRING(ref, ?, 1) REGEXP \'^[0-9]$\'
            ORDER BY CAST(SUBSTRING(ref, ?) AS UNSIGNED) DESC
            LIMIT 1
        ', [$prefijoNc, $longitudPrefijo + 1, $longitudPrefijo + 1]);

        return $fila->ref ?? $prefijoNc.$rangoDesde;
    }

    /**
     * Replica getAperturaCiereCajaPos(): a partir del ultimo registro de
     * pos_control_cash para el terminal/usuario, determina si hay que
     * pedir apertura, cierre o reapertura de caja. Migrado desde
     * ConfiguracionService::getConfiguracion() junto con el resto de datos
     * propios del usuario (terminal, almacen, cliente predeterminado).
     */
    private function estadoCajaPos(int $idTerminal, int $idUsuario): array
    {
        $ultimo = PosControlCash::query()
            ->where('fk_user', $idUsuario)
            ->where('fk_cash', $idTerminal)
            ->orderByDesc('rowid')
            ->first(['rowid', 'date_c', 'type_control']);

        if (! $ultimo) {
            return ['apertura' => true, 'cierre' => false, 'reapertura' => false];
        }

        $fechaUltimo = substr($ultimo->date_c, 0, 10);

        if (in_array((int) $ultimo->type_control, [2, 3], true)) {
            return [
                'apertura' => false,
                'cierre' => date('Y-m-d') !== $fechaUltimo,
                'reapertura' => false,
            ];
        }

        if (date('Y-m-d') !== $fechaUltimo) {
            return ['apertura' => true, 'cierre' => false, 'reapertura' => false];
        }

        $ultimosDos = PosControlCash::query()
            ->where('fk_user', $idUsuario)
            ->where('fk_cash', $idTerminal)
            ->orderByDesc('rowid')
            ->limit(2)
            ->get(['type_control', 'date_c']);

        $fechaApertura = null;
        $posicionApertura = null;
        $fechaCierre = null;

        foreach ($ultimosDos as $posicion => $registro) {
            if ((int) $registro->type_control === 1) {
                $fechaCierre = substr($registro->date_c, 0, 10);
            }
            if ((int) $registro->type_control === 2) {
                $fechaApertura = substr($registro->date_c, 0, 10);
                $posicionApertura = $posicion + 1;
            }
        }

        if (strtotime((string) $fechaApertura) !== strtotime((string) $fechaCierre)
            && in_array($posicionApertura, [2, null], true)) {
            return ['apertura' => true, 'cierre' => false, 'reapertura' => false];
        }

        return ['apertura' => false, 'cierre' => false, 'reapertura' => true];
    }

    public function getUsersONE(Request $request)
    {
        $usuarios = Usuario::query()
            ->where('statut', 1)
            ->select(['rowid', 'login', 'pass_crypted', 'api_key', 'admin', 'firstname', 'lastname'])
            ->with(['extrafields' => function ($q) {
                $q->select('fk_object', 'pin_admin');
            }])
            ->with(['grupos' => function ($q) {
                $q->select('usergroup.rowid');
            }])
            ->with(['permisosApk' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSAPK)
                    ->select('rights_def.perms', 'rights_def.subperms', 'rights_def.libelle');
            }])
            ->with(['grupos.permisosApk' => function ($q) {
                $q->where('module', self::MODULO_INTEGROSAPK)
                    ->select('rights_def.perms', 'rights_def.subperms', 'rights_def.libelle');
            }])
            ->with(['terceros' => function ($q) {
                $q->where('societe.fournisseur', 1)
                    ->select('societe.rowid', 'societe.nom', 'societe.tva_intra', 'societe.phone');
            }])
            ->get();

        $catalogoApk = Permiso::query()
            ->where('module', self::MODULO_INTEGROSAPK)
            ->get(['perms', 'subperms']);

        $listUsers = [];

        foreach ($usuarios as $usuario) {
            $listUsers[] = [
                'rowid' => $usuario->rowid,
                'login' => $usuario->login,
                'pass_crypted' => $usuario->pass_crypted,
                'api_key' => $usuario->api_key,
                'pin_admin' => $usuario->extrafields?->pin_admin ?: 0,
                'admin' => (int) $usuario->admin === 1,
                'fullname' => trim($usuario->firstname.' '.$usuario->lastname),
                'permisosApk' => $this->todosLosPermisosApk($usuario, $catalogoApk),
                'proveedoresAsignados' => $usuario->terceros
                    ->map(fn ($tercero) => [
                        'id' => (int) $tercero->rowid,
                        'nombre' => $tercero->nom,
                        'rif' => $tercero->tva_intra,
                        'telefono' => $tercero->phone,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $listUsers;
    }

    /**
     * Todos los permisos del modulo integrosapk (catalogo completo) con
     * true/false segun si el usuario lo tiene, directo o heredado de sus
     * grupos.
     *
     * @param  Collection<int, Permiso>  $catalogoApk
     * @return array<string, bool>
     */
    private function todosLosPermisosApk(Usuario $usuario, $catalogoApk): array
    {
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
}
