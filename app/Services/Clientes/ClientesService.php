<?php

namespace App\Services\Clientes;

use App\Models\CiudadFe;
use App\Models\Configuracion;
use App\Models\Contacto;
use App\Models\Departamento;
use App\Models\Factura;
use App\Models\OrdenVenta;
use App\Models\Producto;
use App\Models\Propuesta;
use App\Models\PuntoFidelizacion;
use App\Models\PuntoFidelizacionDetalle;
use App\Models\PuntoFidelizacionRedimido;
use App\Models\Sociedad;
use App\Models\SociedadComercial;
use App\Models\SociedadExtrafields;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientesService
{
    private const CAMPOS_ORDEN_PERMITIDOS = [
        'rowid', 'nom', 'siren', 'zip', 'price_level', 'remise_client', 'phone', 'email',
    ];

    /**
     * Nombres de c_clasificacion_tributaria por rowid (lo que guarda
     * societe_extrafields.clasificacion_tributaria). Se lee del diccionario en vez
     * de una lista fija para que las clasificaciones nuevas salgan sin tocar codigo.
     *
     * @var array<int, string>|null
     */
    private ?array $nombresClasificacionTributaria = null;

    /**
     * Dolibarr no tiene tabla de labels para el estado de commande (a
     * diferencia de propal, que usa c_propalst); son los valores estandar
     * de fk_statut para ordenes de venta.
     */
    private const ESTADOS_ORDEN = [
        0 => 'Borrador',
        1 => 'Validada',
        2 => 'En proceso',
        3 => 'Cerrada',
        9 => 'Cancelada',
    ];

    /**
     * Idem para facture (verificado contra respuesta real del endpoint
     * legacy: fk_statut=1 se muestra como "Pendiente", no "Validada"; paye
     * es una columna aparte, no se mezcla aqui).
     */
    private const ESTADOS_FACTURA = [
        0 => 'Borrador',
        1 => 'Pendiente',
        2 => 'Pagada',
        3 => 'Abandonada',
    ];

    public function listarClientes(Request $request, Usuario $usuario): array
    {
        if (! $usuario->tieneDerecho('backend', 'read')) {
            abort(401, 'Este usuario no tiene permisos para esta acción');
        }

        $byNombre = trim((string) $request->input('byNombre', ''));
        $byId = trim((string) $request->input('byId', ''));
        $byDocument = trim((string) $request->input('byDocument', ''));
        $orderBy = trim((string) $request->input('orderBy', ''));
        $orderType = strtoupper(trim((string) $request->input('orderType', '')));
        $limit = (int) $request->input('limit', 0);
        $fkVendedor = (int) $request->input('fk_vendedor', 0);

        // isset(), no truthy: cualquier valor guardado en esa constante activa la busqueda por code_client.
        $usaCodigoCliente = array_key_exists(
            'BUSQUEDA_CODIGO_CLIENTE_defecto_documento',
            Configuracion::valores(['BUSQUEDA_CODIGO_CLIENTE_defecto_documento'])
        );

        $clientes = Sociedad::query()
            ->where('client', '>', 0)
            ->when($byId !== '', fn ($q) => $q->where('rowid', (int) $byId))
            ->when($byId === '' && $byDocument !== '', function ($q) use ($byDocument, $usaCodigoCliente) {
                $q->where($usaCodigoCliente ? 'code_client' : 'siren', $byDocument);
            })
            ->when($byId === '' && $byNombre !== '', fn ($q) => $q->where('nom', 'like', "%{$byNombre}%"))
            ->when($fkVendedor > 0, function ($q) use ($fkVendedor) {
                $q->whereHas('vendedores', fn ($q2) => $q2->where('fk_user', $fkVendedor));
            })
            ->when(
                in_array($orderBy, self::CAMPOS_ORDEN_PERMITIDOS, true),
                fn ($q) => $q->orderBy($orderBy, $orderType === 'DESC' ? 'desc' : 'asc')
            )
            ->when($limit > 1, fn ($q) => $q->limit($limit))
            ->select([
                'rowid', 'nom', 'fk_typent', 'address', 'phone', 'email', 'siren', 'zip',
                'fk_departement', 'tva_assuj', 'siret', 'price_level', 'remise_client',
                'mode_reglement', 'cond_reglement', 'ape',
            ])
            ->with([
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'aplica_puntos', 'vender_sin_cupon', 'vender_con_facturas_vencidas', 'clasificacion_tributaria', 'retefuente', 'reteica', 'reteiva', 'fk_reteiva'),
                'ciudadFe' => fn ($q) => $q->select('rowid', 'zip', 'depto', 'code', 'code_depto', 'ciudad'),
                'ciiu' => fn ($q) => $q->select('codigo', 'agrupacion'),
                'medioPago' => fn ($q) => $q->select('id', 'libelle'),
                'terminoPago' => fn ($q) => $q->select('rowid', 'libelle'),
                'puntosFidelizacion' => fn ($q) => $q->select('id', 'fk_client', 'historico_puntos', 'puntos_acomulados', 'proximos_vencer', 'fecha_vencimiento'),
            ])
            ->get();

        $exclusionesPorClasificacion = $this->exclusionesRetencionVentaTerceroPorClasificacion();

        return $clientes->map(fn (Sociedad $cliente) => $this->formatearCliente($cliente, $exclusionesPorClasificacion))->values()->all();
    }

    /**
     * Une createClientPosLib() y updateClientPosLib() en una sola funcion:
     * si el payload trae idCliente edita ese cliente (validando que exista y
     * este activo), si no lo trae crea uno nuevo (con fallback de
     * ciudad/departamento/clasificacion tributaria al "Consumidor final").
     */
    public function guardarCliente(Request $request, Usuario $usuario): array
    {
        if (! $usuario->tieneDerecho('backend', 'read')) {
            abort(401, 'Este usuario no tiene permisos para esta acción');
        }

        $datos = $request->all();

        if (empty($datos)) {
            abort(401, 'Datos enviados de manera incorrecta.');
        }

        $idCliente = (int) ($datos['idCliente'] ?? 0);

        // Resolver ciudad y departamento desde codeCiudad y nombre de departamento (comun a crear/actualizar).
        $town = null;
        $zip = null;

        $codeCiudad = trim((string) ($datos['codeCiudad'] ?? ''));
        $deptoNombre = trim((string) ($datos['departamento'] ?? ''));
        $codeDepto = ($datos['codeDepto'] ?? null);
        $fkDepartamento = null;

        if ($codeCiudad !== '') {
            $ciudad = CiudadFe::query()->where('code', $codeCiudad)->first();
            if ($ciudad) {
                $town = (int) $ciudad->rowid;
                $zip = $codeCiudad;
            }
        }

        if (! is_null($codeDepto) && $deptoNombre !== '') {
            $departamento = Departamento::query()->where('departamento_code', $codeDepto)->first();
            if ($departamento) {
                $fkDepartamento = (int) $departamento->rowid;
            }
        }

        if ($idCliente > 0) {
            // ACTUALIZAR: valida que el cliente exista y este activo antes de editarlo.
            $cliente = Sociedad::query()->find($idCliente);

            if (! $cliente || $cliente->nom === null) {
                abort(401, 'No se encontró un cliente con el idCliente enviado.');
            }

            if ((int) $cliente->status !== 1) {
                abort(401, 'El cliente no está activo, no se puede actualizar.');
            }

            DB::transaction(function () use ($cliente, $datos, $town, $zip, $fkDepartamento) {
                $cliente->nom = $datos['nombreCliente'] ?? null;
                $cliente->siren = $datos['documento'] ?? null;
                $cliente->siret = $datos['tipoDocumento'] ?? '';
                $cliente->address = $datos['direccion'] ?? null;
                $cliente->email = $datos['correo'] ?? null;
                $cliente->phone = $datos['telefono'] ?? null;
                $cliente->tva_assuj = (int) ($datos['Responsableva'] ?? 0);
                $cliente->fk_typent = (int) ($datos['tipoEmpresa'] ?? 0);

                if ($town) {
                    $cliente->town = $town;
                }
                if ($zip) {
                    $cliente->zip = $zip;
                }
                if ($fkDepartamento) {
                    $cliente->fk_departement = $fkDepartamento;
                }

                $cliente->save();

                $fidelizacionActiva = (Configuracion::valores(['FIDELIZACION'])['FIDELIZACION'] ?? null) == 1;
                if ($fidelizacionActiva) {
                    SociedadExtrafields::query()
                        ->where('fk_object', $cliente->rowid)
                        ->update(['aplica_puntos' => ! empty($datos['aplicaPuntos']) ? 1 : 0]);
                }
            });

            return [
                'status' => 1,
                'msg' => 'Cliente editado correctamente',
            ];
        }

        // CREAR: valida documento unico y aplica fallback del "Consumidor final".
        $documento = trim((string) ($datos['documento'] ?? ''));

        if (Sociedad::query()->where('siren', $documento)->exists()) {
            abort(401, "Ya existe un cliente con el número de documento {$documento}");
        }

        $consumidorFinal = Sociedad::query()
            ->where('nom', 'Consumidor final')
            ->select(['rowid', 'fk_departement', 'town', 'zip'])
            ->with(['extrafields' => fn ($q) => $q->select('fk_object', 'clasificacion_tributaria')])
            ->first();

        $porActualizar = false;

        if (! $fkDepartamento && $consumidorFinal?->fk_departement) {
            $fkDepartamento = (int) $consumidorFinal->fk_departement;
            $porActualizar = true;
        }

        if (! $town && $consumidorFinal?->town) {
            $town = $consumidorFinal->town;
            $porActualizar = true;
        }

        if (! $zip && $consumidorFinal?->zip) {
            $zip = $consumidorFinal->zip;
            $porActualizar = true;
        }

        $clasificacionTributaria = $datos['clasificacionTributaria'] ?? null;
        if (! $clasificacionTributaria && $consumidorFinal?->extrafields?->clasificacion_tributaria) {
            $clasificacionTributaria = $consumidorFinal->extrafields->clasificacion_tributaria;
            $porActualizar = true;
        }

        $fidelizacionActiva = (Configuracion::valores(['FIDELIZACION'])['FIDELIZACION'] ?? null) == 1;
        $aplicaPuntos = $fidelizacionActiva ? (! empty($datos['aplicaPuntos']) ? 1 : 0) : null;

        $idClienteNuevo = DB::transaction(function () use ($datos, $fkDepartamento, $town, $zip, $clasificacionTributaria, $aplicaPuntos, $porActualizar) {
            $cliente = new Sociedad;
            $cliente->nom = $datos['nombreCliente'] ?? null;
            $cliente->siren = $datos['documento'] ?? null;
            $cliente->entity = 1;
            $cliente->statut = 0;
            $cliente->status = 1;
            if ($fkDepartamento) {
                $cliente->fk_departement = $fkDepartamento;
            }
            if ($town) {
                $cliente->town = $town;
            }
            if ($zip) {
                $cliente->zip = $zip;
            }
            $cliente->fk_pays = 70;
            $cliente->fk_account = 0;
            $cliente->fk_typent = (int) ($datos['tipoEmpresa'] ?? 0);
            $cliente->fk_stcomm = 0;
            $cliente->client = 1;
            $cliente->fournisseur = 0;
            $cliente->fk_incoterms = 0;
            $cliente->datec = now()->format('Y-m-d H:i:s');
            // Igual que el original: no se usa el usuario autenticado, se deja fijo al usuario 1.
            $cliente->fk_user_creat = 1;
            $cliente->fk_user_modif = 1;
            $cliente->fk_multicurrency = 0;
            $cliente->address = $datos['direccion'] ?? null;
            $cliente->email = $datos['correo'] ?? null;
            $cliente->phone = $datos['telefono'] ?? null;
            $cliente->siret = (($datos['tipoDocumento'] ?? null) == '2') ? 'CC' : 'NIT';
            $cliente->tva_assuj = (int) ($datos['Responsableva'] ?? 0);
            $cliente->save();

            $extrafields = new SociedadExtrafields;
            $extrafields->fk_object = $cliente->rowid;
            $extrafields->aplica_puntos = $aplicaPuntos;
            $extrafields->clasificacion_tributaria = $clasificacionTributaria;
            $extrafields->por_actualizar = $porActualizar ? 1 : 0;
            $extrafields->save();

            return $cliente->rowid;
        });

        return [
            'status' => 1,
            'msg' => 'Cliente creado correctamente',
            'idClient' => (int) $idClienteNuevo,
        ];
    }

    /**
     * Replica getClientPendingInvoices(): cartera pendiente de un cliente (por
     * documento/siren) y su cupo de credito. Una factura se considera pendiente
     * si no esta en borrador (0) ni abandonada (3), no quedo cerrada por
     * reemplazo (fk_statut=2 con close_code='replaced'), y su saldo
     * (total_ttc - pagos - notas de credito) es mayor a 1.
     */
    /**
     * documentoId vacio, "0" o 0: en vez de exigir un cliente puntual, trae la
     * cartera de TODOS los clientes con cupo_base > 0 (uno por uno, mismo calculo
     * que el caso individual).
     */
    public function obtenerFacturasPendientes(Request $request, Usuario $usuario): array
    {
        if (! $usuario->tieneDerecho('backend', 'read')) {
            abort(401, 'Este usuario no tiene permisos para esta acción');
        }

        $documentoId = trim((string) $request->input('documentoId', ''));

        if ($documentoId === '' || $documentoId === '0') {
            return $this->carteraDeClientesConCupo();
        }

        $cliente = Sociedad::query()
            ->where('siren', $documentoId)
            ->select(['rowid', 'nom', 'address', 'phone', 'cond_reglement'])
            ->with([
                'extrafields' => fn ($q) => $q->select('fk_object', 'cupo_actual', 'cupo_base', 'dias_plazo_cupo'),
                'terminoPago' => fn ($q) => $q->select('rowid', 'nbjour'),
            ])
            ->first();

        if (! $cliente) {
            abort(404, "No se encontro un cliente con documento [{$documentoId}]");
        }

        return array_merge(['status' => 200], $this->carteraDeUnCliente($cliente));
    }

    /**
     * Cartera de todos los clientes con cupo_base > 0 (llx_societe_extrafields),
     * uno por uno via carteraDeUnCliente(). En este entorno son ~5 clientes, asi
     * que un loop con una query por cliente es aceptable (no vale la pena una
     * consulta masiva mas compleja para tan pocos registros).
     */
    private function carteraDeClientesConCupo(): array
    {
        $clientes = Sociedad::query()
            ->whereHas('extrafields', fn ($q) => $q->where('cupo_base', '>', 0))
            ->select(['rowid', 'nom', 'address', 'phone', 'cond_reglement'])
            ->with([
                'extrafields' => fn ($q) => $q->select('fk_object', 'cupo_actual', 'cupo_base', 'dias_plazo_cupo'),
                'terminoPago' => fn ($q) => $q->select('rowid', 'nbjour'),
            ])
            ->orderBy('nom')
            ->get();

        return [
            'status' => 200,
            'clientes' => $clientes->map(fn ($cliente) => $this->carteraDeUnCliente($cliente))->values()->all(),
        ];
    }

    /** @return array{cliente: array, facturas_pendientes: array} */
    private function carteraDeUnCliente(Sociedad $cliente): array
    {
        $facturas = Factura::query()
            ->where('fk_soc', $cliente->rowid)
            ->where('paye', 0)
            ->whereNotIn('fk_statut', [0, 3])
            ->whereRaw("NOT (fk_statut = 2 AND close_code = 'replaced')")
            ->withSum('pagos as total_pagos', 'amount')
            ->withSum('descuentosExcepcionales as total_notas_credito', 'amount_ttc')
            ->orderByDesc('rowid')
            ->get(['rowid', 'ref', 'total_ttc', 'datef', 'date_lim_reglement']);

        $hoy = Carbon::today();
        $totalOutstanding = 0;
        $facturasPendientes = [];

        foreach ($facturas as $factura) {
            $abonos = (float) ($factura->total_pagos ?? 0) + (float) ($factura->total_notas_credito ?? 0);
            $saldoPendiente = (float) $factura->total_ttc - $abonos;

            if ($saldoPendiente <= 1) {
                continue;
            }

            $diasVencidos = 0;
            if (! empty($factura->date_lim_reglement)) {
                $fechaVenc = Carbon::parse($factura->date_lim_reglement)->startOfDay();
                // Carbon 3 cambio diffInDays() para devolver un valor CON SIGNO por
                // defecto (antes, en Carbon 2, siempre era absoluto); absolute:true
                // fuerza el valor absoluto explicito para no depender de esa version.
                $dias = $hoy->diffInDays($fechaVenc, absolute: true);
                $diasVencidos = $fechaVenc->lt($hoy) ? $dias : -$dias;
            }

            $totalOutstanding += $saldoPendiente;

            $facturasPendientes[] = [
                'factura_id' => (int) $factura->rowid,
                'ref' => (string) $factura->ref,
                'total_factura' => (float) $factura->total_ttc,
                'abonos' => round($abonos, 2),
                'saldo_pendiente' => round($saldoPendiente, 2),
                'fecha_factura' => (string) $factura->datef,
                'fecha_vencimiento' => (string) $factura->date_lim_reglement,
                'dias_vencidos' => $diasVencidos,
            ];
        }

        $cupoAutorizado = (float) ($cliente->extrafields?->cupo_base ?? 0);
        $cupoUtilizado = round($totalOutstanding, 2);

        return [
            'cliente' => [
                'rowid' => (int) $cliente->rowid,
                'nombre' => (string) $cliente->nom,
                'direccion' => (string) ($cliente->address ?? ''),
                'telefono' => (string) ($cliente->phone ?? ''),
                'cupo_autorizado' => $cupoAutorizado,
                'cupo_utilizado' => $cupoUtilizado,
                'cupo_disponible' => round($cupoAutorizado - $cupoUtilizado, 2),
                'diasPlazoCupo' => $this->calcularDiasPlazoCupo($cliente->terminoPago?->nbjour),
            ],
            'facturas_pendientes' => $facturasPendientes,
        ];
    }

    private function calcularDiasPlazoCupo(?int $nbjour): int
    {
        $nbjour = (int) $nbjour;

        return $nbjour <= 1 ? 0 : $nbjour;
    }

    /**
     * Replica redimirPuntos(): descuenta puntos de fidelizacion de un cliente,
     * tomandolos de los lotes de pos_puntos_fidelizacion_detalle mas proximos
     * a vencer primero (FIFO por fecha_vencimiento), hasta cubrir la cantidad
     * solicitada. A diferencia del original, esto va dentro de una transaccion
     * para que las actualizaciones de detalle/acumulado/redimidos sean atomicas.
     */
    public function redimirPuntos(Request $request): array
    {
        $datos = $request->all();

        $puntosObjetivo = (int) ($datos['puntos'] ?? 0);
        $idCliente = (int) ($datos['idCliente'] ?? 0);
        $idProducto = (int) ($datos['idProducto'] ?? 0);
        $idUser = (int) ($datos['idUser'] ?? 0);

        $fecha = now();

        $puntosAcumulados = (int) (PuntoFidelizacion::query()
            ->where('fk_client', $idCliente)
            ->value('puntos_acomulados') ?? 0);

        if ($puntosObjetivo > $puntosAcumulados) {
            return [
                'message' => 'No hay suficientes puntos para redimir',
                'status' => 1,
                'fecha' => $fecha->format('Y-m-d g:ia'),
            ];
        }

        $detalles = PuntoFidelizacionDetalle::query()
            ->whereIn('estado', [1, 4])
            ->where('fk_client', $idCliente)
            ->where('puntos_restante', '>', 0)
            ->orderBy('fecha_vencimiento')
            ->get(['id', 'puntos_restante', 'puntos_redimidos']);

        $pendiente = $puntosObjetivo;
        $lineasUsadas = [];

        foreach ($detalles as $detalle) {
            if ($pendiente <= 0) {
                break;
            }

            $disponible = (int) $detalle->puntos_restante;
            if ($disponible <= 0) {
                continue;
            }

            $aUsar = min($disponible, $pendiente);
            $lineasUsadas[] = [
                'id' => (int) $detalle->id,
                'a_usar' => $aUsar,
                'restante_antes' => $disponible,
                'redimidos_antes' => (int) $detalle->puntos_redimidos,
            ];

            $pendiente -= $aUsar;
        }

        if ($pendiente > 0) {
            return [
                'message' => 'Puntos insuficientes para redimir la cantidad solicitada',
                'status' => 1,
                'fecha' => $fecha->format('Y-m-d g:ia'),
            ];
        }

        DB::transaction(function () use ($lineasUsadas, $idUser, $idCliente, $idProducto, $puntosObjetivo, $fecha) {
            foreach ($lineasUsadas as $linea) {
                $nuevoRestante = $linea['restante_antes'] - $linea['a_usar'];
                $nuevoRedimidos = $linea['redimidos_antes'] + $linea['a_usar'];

                PuntoFidelizacionDetalle::query()->where('id', $linea['id'])->update([
                    'puntos_restante' => $nuevoRestante,
                    'puntos_redimidos' => $nuevoRedimidos,
                    'estado' => $nuevoRestante <= 0 ? 3 : 4,
                ]);
            }

            $redencion = new PuntoFidelizacionRedimido;
            $redencion->fk_user = $idUser;
            $redencion->fk_client = $idCliente;
            $redencion->fk_product = $idProducto;
            $redencion->fecha = $fecha;
            $redencion->puntos = $puntosObjetivo;
            $redencion->save();

            PuntoFidelizacion::query()
                ->where('fk_client', $idCliente)
                ->decrement('puntos_acomulados', $puntosObjetivo);

            $fechaMinima = PuntoFidelizacionDetalle::query()
                ->where('fk_client', $idCliente)
                ->whereIn('estado', [1, 4])
                ->where('puntos_restante', '>', 0)
                ->min('fecha_vencimiento');

            $proximosVencer = $fechaMinima
                ? (int) PuntoFidelizacionDetalle::query()
                    ->where('fk_client', $idCliente)
                    ->whereIn('estado', [1, 4])
                    ->where('puntos_restante', '>', 0)
                    ->where('fecha_vencimiento', $fechaMinima)
                    ->sum('puntos_restante')
                : 0;

            PuntoFidelizacion::query()
                ->where('fk_client', $idCliente)
                ->update(['proximos_vencer' => $proximosVencer]);
        });

        return [
            'message' => "{$puntosObjetivo} puntos redimidos",
            'status' => 0,
            'fecha' => $fecha->format('Y-m-d g:ia'),
        ];
    }

    /**
     * Replica productoPuntos(): datos minimos de un producto redimible por
     * puntos, buscado primero por referencia exacta y luego por codigo de
     * barras exacto (el de product_extrafields.codigo_barras, no la tabla
     * product_barcode). "price_min_ttc" replica tal cual el nombre de campo
     * del original aunque el valor real que trae es product.price.
     */
    public function buscarProductoPuntos(Request $request): array
    {
        $producto = trim((string) $request->input('producto', ''));

        $datos = [
            'existe' => false,
            'label' => '',
            'rowid' => 0,
            'price_min_ttc' => 0,
            'cantidad_puntos' => 0,
        ];

        $fila = Producto::query()
            ->where('ref', $producto)
            ->select(['rowid', 'label', 'price'])
            ->with(['extrafields' => fn ($q) => $q->select('fk_object', 'cantidad_puntos')])
            ->first();

        if (! $fila) {
            $fila = Producto::query()
                ->whereHas('extrafields', fn ($q) => $q->where('codigo_barras', $producto))
                ->select(['rowid', 'label', 'price'])
                ->with(['extrafields' => fn ($q) => $q->select('fk_object', 'cantidad_puntos')])
                ->first();
        }

        if ($fila) {
            $datos = [
                'existe' => true,
                'label' => $fila->label,
                'rowid' => (int) $fila->rowid,
                'price_min_ttc' => (int) $fila->price,
                'cantidad_puntos' => (int) ($fila->extrafields?->cantidad_puntos ?? 0),
            ];
        }

        return $datos;
    }

    private function nombreClasificacionTributaria(int $rowid): string
    {
        $this->nombresClasificacionTributaria ??= DB::table('c_clasificacion_tributaria')
            ->pluck('label', 'rowid')
            ->mapWithKeys(fn ($label, $id) => [(int) $id => (string) $label])
            ->all();

        return $this->nombresClasificacionTributaria[$rowid] ?? '';
    }

    private function formatearCliente(Sociedad $cliente, array $exclusionesPorClasificacion = []): array
    {
        $ciudad = $cliente->ciudadFe;
        $puntos = $cliente->puntosFidelizacion;
        $clasifTribCliente = (int) ($cliente->extrafields?->clasificacion_tributaria ?? 0);

        return [
            'rowid' => (int) $cliente->rowid,
            'nom' => $cliente->nom,
            'typent' => (int) $cliente->fk_typent,
            'address' => $cliente->address ?? '',
            'phone' => $cliente->phone ? (int) $cliente->phone : 0,
            'email' => $cliente->email ?? '',
            'siren' => $cliente->siren,
            'zipcode' => $cliente->zip ? (int) $cliente->zip : 0,
            'fk_departement' => $cliente->fk_departement ? (int) $cliente->fk_departement : 0,
            'iva' => $cliente->tva_assuj ? (int) $cliente->tva_assuj : 0,
            'depto' => $ciudad?->depto ?? '',
            'code' => $ciudad?->code ?? '',
            'code_depto' => $ciudad?->code_depto ?? '',
            'ciudad' => $ciudad?->ciudad ?? '',
            // rowid de c_ciudades_fe (mismo espacio que idCiudad en getConfiguracion,
            // via societe.town) - necesario para la regla de ICA "misma ciudad que la
            // empresa" (custom/integros/lib/productos.lib.php::calcularRetencionIca()).
            'idCiudad' => $ciudad?->rowid ? (int) $ciudad->rowid : 0,
            'ciuu' => $cliente->ape ?? '',
            'agrupacion' => $cliente->ciiu?->agrupacion !== null ? (int) $cliente->ciiu->agrupacion : null,
            'clasificacion' => $this->nombreClasificacionTributaria($clasifTribCliente),
            // Ya viene con la exclusion de tercero aplicada (ambito='tercero', aplica_venta=1
            // en integros_exclusiones_grupo_cuenta): si el extrafield dice que aplica pero la
            // clasificacion tributaria de este cliente esta excluida, se entrega false, para
            // que el POS no tenga que cruzar el extrafield con una exclusion aparte - ver
            // custom/integros/lib/movimientosContables.lib.php::getGruposCuentaExcluidos() y
            // productos.lib.php::calcularRetencionesFactura() (alli "clasifTribProv" es en
            // realidad la clasificacion del tercero de la factura, el cliente en una venta).
            'aplicaRetefuente' => (bool) ($cliente->extrafields?->retefuente) && ! ($exclusionesPorClasificacion[$clasifTribCliente]['fuente'] ?? false),
            'aplicaReteica' => (bool) ($cliente->extrafields?->reteica) && ! ($exclusionesPorClasificacion[$clasifTribCliente]['ica'] ?? false),
            'aplicaReteiva' => (bool) ($cliente->extrafields?->reteiva) && ! ($exclusionesPorClasificacion[$clasifTribCliente]['iva'] ?? false),
            'fk_reteiva' => $cliente->extrafields?->fk_reteiva ? (int) $cliente->extrafields->fk_reteiva : null,
            'tipoDocumento' => $cliente->siret ?? '',
            'aplicaPuntos' => $cliente->extrafields?->aplica_puntos !== null,
            'venderSinCupon' => (bool) ($cliente->extrafields?->vender_sin_cupon),
            'venderConFacturasVencidas' => (bool) ($cliente->extrafields?->vender_con_facturas_vencidas),
            'historicoPuntos' => (int) ($puntos->historico_puntos ?? 0),
            'puntosRedimibles' => (int) ($puntos->puntos_acomulados ?? 0),
            'proximosVencer' => (int) ($puntos->proximos_vencer ?? 0),
            'fechaProximosVencer' => $puntos->fecha_vencimiento ?? date('Y-m-t 23:59:59'),
            'descuentoGlobal' => (float) $cliente->remise_client,
            'listaPrecios' => $cliente->price_level ? (int) $cliente->price_level : 0,
            'medioPagoId' => $cliente->mode_reglement ? (int) $cliente->mode_reglement : null,
            'medioPago' => $cliente->medioPago?->libelle,
            'terminoPagoId' => $cliente->cond_reglement ? (int) $cliente->cond_reglement : null,
            'terminoPago' => $cliente->terminoPago?->libelle,
        ];
    }

    /**
     * Proveedores (terceros con fournisseur=1) activos, para el modulo de
     * negociacion. Replica getProveedores() (api_app.class.php, modulo
     * integrosapk).
     */
    public function proveedores(Request $request): array
    {
        $search = trim((string) $request->input('search', ''));
        $limit = (int) $request->input('limit', 100);
        $limit = $limit > 0 ? $limit : 100;

        return Sociedad::query()
            ->where('fournisseur', 1)
            ->where('status', 1)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('nom', 'like', "%{$search}%")
                        ->orWhere('tva_intra', 'like', "%{$search}%");
                });
            })
            ->orderBy('nom')
            ->limit($limit)
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
     * Ficha completa de todos los clientes activos asignados al vendedor
     * autenticado (societe_commerciaux): datos fiscales/cupo/condiciones de
     * pago, mas cartera vencida, contactos y ultimos 20 movimientos por
     * cliente. Replica clientesCompletos() (api_app.class.php, modulo
     * integrosapk) - el fk_user siempre es el del DOLAPIKEY autenticado.
     *
     * Forma de salida verificada contra la respuesta real del endpoint
     * legacy: son los nombres de columna de societe/societe_extrafields
     * casi sin traducir (documento=siren, tipo_documento=siret, town=el
     * rowid de c_ciudades_fe resuelto por zip, fk_reteiva/fk_depto pasan
     * "" crudo cuando son null, etc.) - no la forma "amigable" de
     * formatearCliente()/proveedores().
     *
     * $usuario null significa que quien llama se autentico con la DOLAPIKEY
     * de un proveedor (no de un vendedor): en ese caso no hay fk_user con
     * el que filtrar, asi que se devuelven todos los clientes activos.
     */
    public function clientesCompletos(?Usuario $usuario): array
    {
        if ($usuario !== null) {
            $idsClientes = SociedadComercial::query()
                ->where('fk_user', $usuario->rowid)
                ->pluck('fk_soc')
                ->unique()
                ->values()
                ->all();

            if (empty($idsClientes)) {
                return ['ok' => true, 'total' => 0, 'clientes' => []];
            }
        }

        $clientes = Sociedad::query()
            ->when($usuario !== null, fn ($q) => $q->whereIn('rowid', $idsClientes))
            ->where('status', 1)
            ->whereIn('client', [1, 2, 3])
            ->where('entity', 1)
            ->select([
                'rowid', 'nom', 'name_alias', 'code_client', 'siren', 'siret',
                'address', 'zip', 'phone', 'phone_mobile', 'fax', 'email', 'url',
                'client', 'fournisseur', 'remise_client', 'price_level', 'outstanding_limit',
                'cond_reglement', 'mode_reglement', 'tva_assuj', 'note_private', 'note_public', 'status', 'ape',
            ])
            ->with([
                'ciudadFe' => fn ($q) => $q->select('rowid', 'code', 'ciudad'),
                'ciiu' => fn ($q) => $q->select('codigo', 'agrupacion'),
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'clasificacion_tributaria', 'fk_reteiva', 'retefuente', 'reteica', 'reteiva', 'cupo_base', 'cupo_actual', 'dias_plazo_cupo', 'fk_depto', 'aplica_puntos', 'por_actualizar'),
            ])
            ->get();

        $idsFiltrados = $clientes->pluck('rowid')->all();

        $carteraPorCliente = $this->carteraVencidaPorCliente($idsFiltrados);
        $contactosPorCliente = $this->contactosPorCliente($idsFiltrados);
        $movimientosPorCliente = $this->movimientosPorCliente($idsFiltrados);

        $clientesArr = $clientes->map(function ($cliente) use ($carteraPorCliente, $contactosPorCliente, $movimientosPorCliente) {
            $extra = $cliente->extrafields;

            return [
                'rowid' => (int) $cliente->rowid,
                'nom' => $cliente->nom,
                'name_alias' => $cliente->name_alias ?? '',
                'code_client' => $cliente->code_client ?? '',
                'documento' => $cliente->siren ?? '',
                'tipo_documento' => $cliente->siret ?? '',
                'address' => $cliente->address ?? '',
                'zip' => $cliente->zip ?? '',
                'town' => $cliente->ciudadFe ? (string) $cliente->ciudadFe->rowid : '',
                'phone' => $cliente->phone ?? '',
                'phone_mobile' => $cliente->phone_mobile ?? '',
                'fax' => $cliente->fax ?? '',
                'email' => $cliente->email ?? '',
                'url' => $cliente->url ?? '',
                'client' => (int) $cliente->client,
                'fournisseur' => (int) $cliente->fournisseur,
                'descuento' => (float) $cliente->remise_client,
                'nivel_precio' => (int) $cliente->price_level,
                'cupo_limite' => (float) $cliente->outstanding_limit,
                'fk_cond_reglement' => (int) $cliente->cond_reglement,
                'fk_mode_reglement' => (int) $cliente->mode_reglement,
                'tva_assuj' => (int) $cliente->tva_assuj,
                'note_private' => $cliente->note_private ?? '',
                'note_public' => $cliente->note_public ?? '',
                'status' => (int) $cliente->status,
                'clasificacion_tributaria' => $extra?->clasificacion_tributaria ?? '',
                'ciuu' => $cliente->ape ?? '',
                'ciudad' => $cliente->ciudadFe?->ciudad ?? '',
                'agrupacion' => $cliente->ciiu?->agrupacion !== null ? (int) $cliente->ciiu->agrupacion : null,
                'clasificacion' => $this->nombreClasificacionTributaria((int) ($extra?->clasificacion_tributaria ?? 0)),
                'fk_reteiva' => $extra?->fk_reteiva ?? '',
                'aplicaRetefuente' => (bool) ($extra?->retefuente),
                'aplicaReteica' => (bool) ($extra?->reteica),
                'aplicaReteiva' => (bool) ($extra?->reteiva),
                'cupo_base' => (float) ($extra?->cupo_base ?? 0),
                'cupo_actual' => (float) ($extra?->cupo_actual ?? 0),
                'dias_plazo_cupo' => (int) ($extra?->dias_plazo_cupo ?? 0),
                'fk_depto' => $extra?->fk_depto ?? '',
                'aplica_puntos' => (int) ($extra?->aplica_puntos ?? 0),
                'por_actualizar' => (int) ($extra?->por_actualizar ?? 0),
                'contactos' => $contactosPorCliente[$cliente->rowid] ?? [],
                'movimientos' => $movimientosPorCliente[$cliente->rowid] ?? [],
                'cartera_vencida' => $carteraPorCliente[$cliente->rowid] ?? [],
            ];
        })->values()->all();

        return [
            'ok' => true,
            'total' => count($clientesArr),
            'clientes' => $clientesArr,
        ];
    }

    /**
     * Grupos de retencion (ICA/IVA/Fuente) excluidos en VENTAS por cada clasificacion
     * tributaria de TERCERO (integros_exclusiones_grupo_cuenta, ambito='tercero',
     * aplica_venta=1) - replica getGruposCuentaExcluidos() de
     * custom/integros/lib/movimientosContables.lib.php para el caso ambito='tercero',
     * ya que alli se evalua junto con 'empresa' en el mismo query. Se trae una sola vez
     * para toda la lista de clientes en vez de una consulta por cliente.
     *
     * @return array<int, array{ica: bool, iva: bool, fuente: bool}> indexado por
     *         fk_clasificacion_tributaria
     */
    private function exclusionesRetencionVentaTerceroPorClasificacion(): array
    {
        return DB::table('integros_exclusiones_grupo_cuenta')
            ->where('active', 1)
            ->where('aplica_venta', 1)
            ->where('ambito', 'tercero')
            ->get(['fk_clasificacion_tributaria', 'fk_grupo_cuenta'])
            ->groupBy('fk_clasificacion_tributaria')
            ->map(function ($filas) {
                $gruposExcluidos = $filas->pluck('fk_grupo_cuenta')->map(fn ($v) => (int) $v)->all();

                return [
                    'ica' => in_array(9, $gruposExcluidos, true),
                    'iva' => in_array(10, $gruposExcluidos, true),
                    'fuente' => in_array(11, $gruposExcluidos, true),
                ];
            })
            ->all();
    }

    /**
     * Facturas validadas (fk_statut=1) y no pagadas (paye=0) cuya fecha
     * limite ya paso, con saldo = total_ttc - suma(pagos). Se descartan las
     * que ya quedaron cubiertas por abonos parciales aunque paye siga en 0.
     *
     * @return array<int, array<int, array>>
     */
    private function carteraVencidaPorCliente(array $idsClientes): array
    {
        $hoy = Carbon::today();

        return Factura::query()
            ->whereIn('fk_soc', $idsClientes)
            ->where('fk_statut', 1)
            ->where('paye', 0)
            ->where('date_lim_reglement', '<', $hoy)
            ->with(['pagos' => fn ($q) => $q->select('rowid', 'fk_facture', 'amount')])
            ->get(['rowid', 'ref', 'fk_soc', 'datef', 'date_lim_reglement', 'total_ttc'])
            ->map(function ($factura) use ($hoy) {
                $pagado = (float) $factura->pagos->sum('amount');

                return [
                    'fk_soc' => (int) $factura->fk_soc,
                    'rowid' => (int) $factura->rowid,
                    'ref' => $factura->ref,
                    'fecha' => $factura->datef,
                    'fecha_vencimiento' => $factura->date_lim_reglement,
                    'total_ttc' => (float) $factura->total_ttc,
                    'total_pagado' => $pagado,
                    'saldo' => (float) $factura->total_ttc - $pagado,
                    'dias_vencido' => (int) Carbon::parse($factura->date_lim_reglement)->diffInDays($hoy),
                ];
            })
            ->filter(fn ($f) => $f['saldo'] > 0.01)
            ->groupBy('fk_soc')
            ->map(fn ($grupo) => $grupo->map(fn ($f) => collect($f)->except('fk_soc')->all())->values()->all())
            ->all();
    }

    /**
     * Contactos activos (socpeople) de los clientes dados.
     *
     * @return array<int, array<int, array>>
     */
    private function contactosPorCliente(array $idsClientes): array
    {
        return Contacto::query()
            ->whereIn('fk_soc', $idsClientes)
            ->where('statut', 1)
            ->with(['extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'dni')])
            ->get(['rowid', 'fk_soc', 'lastname', 'firstname', 'poste', 'phone', 'phone_mobile', 'email'])
            ->groupBy('fk_soc')
            ->map(fn ($grupo) => $grupo->map(fn ($c) => [
                'rowid' => (int) $c->rowid,
                'nombre' => trim($c->firstname.' '.$c->lastname),
                'cargo' => $c->poste ?? '',
                'telefono' => $c->phone ?? '',
                'celular' => $c->phone_mobile ?? '',
                'email' => $c->email ?? '',
                'dni' => $c->extrafields?->dni ?? '',
            ])->values()->all())
            ->all();
    }

    /**
     * Ultimos 20 movimientos por cliente (propuestas + ordenes + facturas,
     * mezclados y ordenados por fecha descendente). El recorte a 20 es en
     * PHP, no por SQL LIMIT, porque es "20 por cliente" sobre un UNION de
     * 3 fuentes distintas. 'fecha' lleva el sufijo "T12:00:00" (verificado
     * contra la respuesta real) porque las columnas fuente son DATE, no
     * DATETIME.
     *
     * @return array<int, array<int, array>>
     */
    private function movimientosPorCliente(array $idsClientes): array
    {
        $propuestas = Propuesta::query()
            ->whereIn('fk_soc', $idsClientes)
            ->with(['estado' => fn ($q) => $q->select('id', 'label')])
            ->get(['rowid', 'ref', 'fk_soc', 'datep', 'fk_statut', 'total_ht', 'total_ttc', 'fk_user_author'])
            ->map(fn ($p) => [
                'fk_soc' => (int) $p->fk_soc,
                'rowid' => (int) $p->rowid,
                'tipo' => 'propuesta',
                'ref' => $p->ref,
                'fecha' => $p->datep,
                'total_ht' => (float) $p->total_ht,
                'total_ttc' => (float) $p->total_ttc,
                'estado' => (int) $p->fk_statut,
                'estado_label' => $p->estado?->label ?? (string) $p->fk_statut,
                'fk_user_author' => (int) $p->fk_user_author,
            ]);

        $ordenes = OrdenVenta::query()
            ->whereIn('fk_soc', $idsClientes)
            ->get(['rowid', 'ref', 'fk_soc', 'date_commande', 'fk_statut', 'total_ht', 'total_ttc', 'fk_user_author'])
            ->map(fn ($o) => [
                'fk_soc' => (int) $o->fk_soc,
                'rowid' => (int) $o->rowid,
                'tipo' => 'orden',
                'ref' => $o->ref,
                'fecha' => $o->date_commande,
                'total_ht' => (float) $o->total_ht,
                'total_ttc' => (float) $o->total_ttc,
                'estado' => (int) $o->fk_statut,
                'estado_label' => self::ESTADOS_ORDEN[(int) $o->fk_statut] ?? (string) $o->fk_statut,
                'fk_user_author' => (int) $o->fk_user_author,
            ]);

        $facturas = Factura::query()
            ->whereIn('fk_soc', $idsClientes)
            ->get(['rowid', 'ref', 'fk_soc', 'datef', 'fk_statut', 'total_ht', 'total_ttc', 'fk_user_author'])
            ->map(fn ($f) => [
                'fk_soc' => (int) $f->fk_soc,
                'rowid' => (int) $f->rowid,
                'tipo' => 'factura',
                'ref' => $f->ref,
                'fecha' => $f->datef,
                'total_ht' => (float) $f->total_ht,
                'total_ttc' => (float) $f->total_ttc,
                'estado' => (int) $f->fk_statut,
                'estado_label' => self::ESTADOS_FACTURA[(int) $f->fk_statut] ?? (string) $f->fk_statut,
                'fk_user_author' => (int) $f->fk_user_author,
            ]);

        $todos = $propuestas->concat($ordenes)->concat($facturas);

        $idsVendedores = $todos->pluck('fk_user_author')->filter()->unique()->values()->all();
        $vendedores = Usuario::query()->whereIn('rowid', $idsVendedores)->get(['rowid', 'firstname', 'lastname'])->keyBy('rowid');

        return $todos
            ->map(function ($mov) use ($vendedores) {
                $vendedor = $vendedores->get($mov['fk_user_author']);
                $mov['vendedor'] = $vendedor ? trim($vendedor->firstname.' '.$vendedor->lastname) : '';
                $mov['fecha'] = $mov['fecha'].'T12:00:00';
                unset($mov['fk_user_author']);

                return $mov;
            })
            ->groupBy('fk_soc')
            ->map(fn ($grupo) => $grupo
                ->sortByDesc('fecha')
                ->take(20)
                ->map(fn ($m) => collect($m)->except('fk_soc')->all())
                ->values()
                ->all())
            ->all();
    }
}
