<?php

namespace App\Services\OrdenVenta;

use App\Models\Configuracion;
use App\Models\Contacto;
use App\Models\Preorden;
use App\Models\PreordenDetalle;
use App\Models\PreordenExtrafields;
use App\Models\Producto;
use App\Models\Sociedad;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Crea una PRE-ORDEN de venta del modulo integrospresta (llx_integros_precommande
 * / llx_integros_precommandedet / llx_integros_precommande_extrafields), el mismo
 * resultado que POST integrospresta/orders/createCustomerOrder pero desde Laravel.
 *
 * La pre-orden nace en borrador (fk_statut=0, preorder_status=0) con ref
 * "PRE-YYYY-NNNN"; convertirla en orden real (llx_commande) sigue siendo un paso
 * aparte del modulo. "order_reference" identifica el pedido origen y es unico:
 * si ya existe se responde 409 con el id existente, lo que hace seguro reintentar.
 */
class PreordenService
{
    public function crear(Request $request, Usuario $usuario): array
    {
        $cliente = $this->resolverCliente($request);
        $employeeId = (int) $request->input('employeeId', 0) ?: (int) $usuario->rowid;

        return $this->crearInterno($request, $cliente, $employeeId);
    }

    /**
     * Crea la preorden autenticado como PROVEEDOR (DOLAPIKEY de
     * llx_integros_usuario_proveedor, no de llx_user). El body debe traer
     * 'customer_id': la preorden siempre queda a nombre de ese cliente
     * (fk_soc = customer_id, mismas reglas que resolverCliente(): debe
     * existir, ser cliente y estar activo). idProveedor solo identifica al
     * proveedor autenticado (debe existir y estar activo), no se usa como
     * fk_soc. Como no hay usuario de sistema detras de este acceso,
     * fk_user_author siempre queda NULL, igual que la sincronizacion de
     * productos por proveedor no referencia ningun llx_user.
     */
    public function crearParaProveedor(Request $request, int $idProveedor): array
    {
        $proveedor = Sociedad::query()->find($idProveedor);
        if (! $proveedor) {
            abort(401, "El proveedor con id [{$idProveedor}] no existe.");
        }
        if ((int) $proveedor->status === 0) {
            abort(401, 'El proveedor está inactivo.');
        }

        if (! $request->filled('customer_id') && ! $request->filled('customerId')) {
            abort(401, "El campo 'customer_id' es obligatorio.");
        }

        $cliente = $this->resolverCliente($request);

        return $this->crearInterno($request, $cliente, null, $idProveedor);
    }

    private function crearInterno(Request $request, Sociedad $cliente, ?int $employeeId, ?int $idProveedor = null): array
    {
        $orderReference = $this->resolverOrderReference($request);
        $lineas = $this->validarLineas($request->input('lines'));

        $existente = PreordenExtrafields::query()->where('order_reference', $orderReference)->value('fk_object');
        if ($existente) {
            abort(response()->json([
                'success' => false,
                'error' => "Ya existe una pre-orden con la referencia: {$orderReference}",
                'code' => 'DUPLICATE_ORDER_REFERENCE',
                'existing_preorder_id' => (int) $existente,
            ], 409));
        }

        $productos = $this->resolverProductos($lineas);

        $origenOrden = trim((string) $request->input('origen_orden', ''));
        $origenOrden = $origenOrden !== '' ? $origenOrden : '1';
        $customerEmail = trim((string) $request->input('customer_email', ''));
        if (strlen($customerEmail) > 255) {
            abort(401, 'Campo customer_email no puede exceder 255 caracteres.');
        }
        $contactId = $customerEmail !== ''
            ? (int) Contacto::query()->where('email', $customerEmail)->where('statut', 1)->value('rowid')
            : 0;

        $moneda = DB::table('const')->where('name', 'MAIN_MONNAIE')->where('entity', 1)->value('value') ?: 'COP';
        $notaPrivada = $request->input('notaPrivada');
        $notaPublica = $request->input('notaPublica');
        $ahora = now();

        return DB::transaction(function () use ($cliente, $orderReference, $lineas, $productos, $origenOrden, $customerEmail, $contactId, $employeeId, $idProveedor, $moneda, $notaPrivada, $notaPublica, $ahora) {
            $preorden = Preorden::create([
                'ref' => '(tmp)',
                'entity' => 1,
                'fk_soc' => $cliente->rowid,
                'fk_statut' => 0,
                'preorder_status' => 0,
                'date_creation' => $ahora->format('Y-m-d H:i:s'),
                'date_commande' => $ahora->format('Y-m-d'),
                'fk_user_author' => $employeeId,
                'note_private' => $notaPrivada,
                'note_public' => $notaPublica,
                'multicurrency_code' => $moneda,
                'multicurrency_tx' => 1,
            ]);

            $ref = 'PRE-'.$ahora->format('Y').'-'.str_pad((string) $preorden->rowid, 4, '0', STR_PAD_LEFT);

            $totalHt = 0.0;
            $totalTva = 0.0;
            $totalTtc = 0.0;
            $lineasCreadas = [];

            foreach ($lineas as $i => $linea) {
                $producto = $productos[$linea['idProduct']];
                $cant = (float) $linea['cant'];
                $tvaTx = $linea['tva_tx'] !== null ? (float) $linea['tva_tx'] : (float) $producto->tva_tx;
                $remise = (float) $linea['discount'];
                $subprice = $linea['precio'] !== null ? (float) $linea['precio'] : $this->precioSinIvaDelProducto($producto);
                $subpriceTtc = round($subprice * (1 + $tvaTx / 100), 8);

                $lineTotalHt = round($subprice * $cant * (1 - $remise / 100), 2);
                $lineTotalTva = round($lineTotalHt * $tvaTx / 100, 2);
                $lineTotalTtc = round($lineTotalHt + $lineTotalTva, 2);

                $detalle = PreordenDetalle::create([
                    'fk_precommande' => $preorden->rowid,
                    'fk_product' => $producto->rowid,
                    'label' => $producto->label,
                    'description' => $linea['description'] ?? ($producto->description ?: $producto->label),
                    'qty' => $cant,
                    'tva_tx' => $tvaTx,
                    'remise_percent' => $remise,
                    'price' => $subprice,
                    'subprice' => $subprice,
                    'subprice_ttc' => $subpriceTtc,
                    'total_ht' => $lineTotalHt,
                    'total_tva' => $lineTotalTva,
                    'total_ttc' => $lineTotalTtc,
                    'product_type' => (int) $producto->fk_product_type,
                    'rang' => $i + 1,
                    'multicurrency_code' => $moneda,
                    'multicurrency_subprice' => $subprice,
                    'multicurrency_subprice_ttc' => $subpriceTtc,
                    'multicurrency_total_ht' => $lineTotalHt,
                    'multicurrency_total_tva' => $lineTotalTva,
                    'multicurrency_total_ttc' => $lineTotalTtc,
                ]);

                $totalHt += $lineTotalHt;
                $totalTva += $lineTotalTva;
                $totalTtc += $lineTotalTtc;

                $lineasCreadas[] = [
                    'idLinea' => (int) $detalle->rowid,
                    'idProduct' => (int) $producto->rowid,
                    'ref' => $producto->ref,
                    'label' => $producto->label,
                    'cant' => $cant,
                    'precio' => $subprice,
                    'tva_tx' => $tvaTx,
                    'discount' => $remise,
                    'total_ht' => $lineTotalHt,
                    'total_tva' => $lineTotalTva,
                    'total_ttc' => $lineTotalTtc,
                ];
            }

            $preorden->update([
                'ref' => $ref,
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
                'amount_ht' => $totalHt,
                'multicurrency_total_ht' => $totalHt,
                'multicurrency_total_tva' => $totalTva,
                'multicurrency_total_ttc' => $totalTtc,
            ]);

            PreordenExtrafields::create([
                'fk_object' => $preorden->rowid,
                'order_reference' => $orderReference,
                'origen_orden' => $origenOrden,
                'customer_email' => $customerEmail,
                'contact_id' => $contactId,
                'fk_proveedor' => $idProveedor,
            ]);

            return [
                'success' => true,
                'id' => (int) $preorden->rowid,
                'ref' => $ref,
                'status' => 'draft',
                'customer_id' => (int) $cliente->rowid,
                'customer_siren' => $cliente->siren,
                'order_reference' => $orderReference,
                'origen_orden' => $origenOrden,
                'customer_email' => $customerEmail,
                'contact_id' => $contactId,
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
                'lines_added' => count($lineasCreadas),
                'lines' => $lineasCreadas,
            ];
        });
    }

    /**
     * PDF de una preorden creada por el proveedor autenticado (idProveedor
     * debe coincidir con el fk_proveedor guardado al crearla; si no
     * coincide o la preorden no existe, no se distingue el motivo para no
     * filtrar si el ref existe). No depende de Dolibarr: genera el HTML acá
     * mismo y lo renderiza con dompdf, igual que TomaFisicaService::
     * generarPdfToma().
     *
     * @return array{success: bool, message?: string, filename?: string, contenido?: string}
     */
    public function generarPdfPreorden(string $ref, int $idProveedor): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return ['success' => false, 'message' => "El campo 'ref' es obligatorio."];
        }

        $preorden = Preorden::query()
            ->where('ref', $ref)
            ->where('entity', 1)
            ->with(['sociedad', 'extrafields'])
            ->first();

        if (! $preorden || (int) ($preorden->extrafields?->fk_proveedor ?? 0) !== $idProveedor) {
            return ['success' => false, 'message' => 'Pre-orden no encontrada.'];
        }

        $lineas = PreordenDetalle::query()
            ->where('fk_precommande', $preorden->rowid)
            ->with('producto')
            ->orderBy('rang')
            ->get();

        $empresa = Configuracion::valores([
            'MAIN_INFO_SOCIETE_NOM',
            'MAIN_INFO_SOCIETE_ADDRESS',
            'MAIN_INFO_SOCIETE_ZIP',
            'MAIN_INFO_SOCIETE_TOWN',
            'MAIN_INFO_SOCIETE_TEL',
            'MAIN_INFO_SOCIETE_MAIL',
            'MAIN_INFO_SOCIETE_WEB',
        ]);

        $dompdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($this->htmlPdfPreorden($preorden, $lineas, $empresa));
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return [
            'success' => true,
            'filename' => preg_replace('/[^A-Za-z0-9_-]/', '_', $preorden->ref).'.pdf',
            'contenido' => $dompdf->output(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PreordenDetalle>  $lineas
     * @param  array<string, string>  $empresa
     */
    private function htmlPdfPreorden(Preorden $preorden, $lineas, array $empresa): string
    {
        $cliente = $preorden->sociedad;
        $orderReference = $preorden->extrafields?->order_reference;

        $tasasIva = [];

        $filas = '';
        foreach ($lineas as $linea) {
            $tasasIva[(string) $linea->tva_tx] = true;

            $descripcion = $linea->description ?: ($linea->producto?->label ?? '');
            $referencia = $linea->producto?->ref ?? '';

            $filas .= '<tr>'
                .'<td>'.e($referencia).' - '.e($descripcion).'</td>'
                .'<td class="num">'.number_format((float) $linea->tva_tx, 0, ',', '.').'%</td>'
                .'<td class="num">'.number_format((float) $linea->subprice, 2, ',', '.').'</td>'
                .'<td class="num">'.number_format((float) $linea->qty, 0, ',', '.').'</td>'
                .'<td class="num">'.number_format((float) $linea->total_ht, 2, ',', '.').'</td>'
                .'</tr>';
        }

        $etiquetaIva = count($tasasIva) === 1 ? 'Total IVA '.number_format((float) array_key_first($tasasIva), 0, ',', '.').'%' : 'Total IVA';

        $empresaNombre = e($empresa['MAIN_INFO_SOCIETE_NOM'] ?? '');
        $empresaDireccion = e(trim(($empresa['MAIN_INFO_SOCIETE_ADDRESS'] ?? '').' '.($empresa['MAIN_INFO_SOCIETE_ZIP'] ?? '').' '.($empresa['MAIN_INFO_SOCIETE_TOWN'] ?? '')));
        $empresaTel = e($empresa['MAIN_INFO_SOCIETE_TEL'] ?? '');
        $empresaMail = e($empresa['MAIN_INFO_SOCIETE_MAIL'] ?? '');
        $empresaWeb = e($empresa['MAIN_INFO_SOCIETE_WEB'] ?? '');

        $clienteNombre = e($cliente->nom ?? '');
        $clienteDireccion = e(trim(($cliente->address ?? '').' '.($cliente->zip ?? '')));
        $clienteTel = e($cliente->phone ?? '');
        $clienteEmail = e($cliente->email ?? '');

        $refPedido = $orderReference ? '<div class="ref">Ref. pedido: '.e($orderReference).'</div>' : '';

        return <<<HTML
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #282828; }
            .titulo { background: #1F3B4D; color: #fff; padding: 8px; text-align: center; font-size: 14px; font-weight: bold; }
            .ref { text-align: center; font-size: 9px; color: #555; margin: 4px 0 10px; }
            .cajas { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .cajas td { width: 50%; vertical-align: top; padding: 8px; border: 1px solid #ccc; background: #F5F5F5; }
            .cajas .etiqueta { font-weight: bold; color: #555; font-size: 8px; text-transform: uppercase; margin-bottom: 3px; }
            table.lineas { width: 100%; border-collapse: collapse; }
            table.lineas th { background: #3BBFA8; color: #fff; padding: 4px; border: 1px solid #999; font-size: 9px; text-align: left; }
            table.lineas td { border: 1px solid #999; padding: 4px; font-size: 9px; }
            table.lineas th.num, table.lineas td.num { text-align: right; }
            table.totales { width: 45%; margin-left: 55%; margin-top: 8px; border-collapse: collapse; }
            table.totales td { padding: 3px 4px; font-size: 9.5px; }
            table.totales td.label { color: #555; }
            table.totales td.valor { text-align: right; }
            table.totales tr.total td { font-weight: bold; font-size: 11px; background: #EFF5F4; }
        </style>
        </head>
        <body>
            <div class="titulo">PRE-ORDEN DE VENTA — {$preorden->ref}</div>
            {$refPedido}
            <table class="cajas">
                <tr>
                    <td>
                        <div class="etiqueta">De</div>
                        <strong>{$empresaNombre}</strong><br>
                        {$empresaDireccion}<br>
                        Tel: {$empresaTel}<br>
                        Correo: {$empresaMail}<br>
                        {$empresaWeb}
                    </td>
                    <td>
                        <div class="etiqueta">A</div>
                        <strong>{$clienteNombre}</strong><br>
                        {$clienteDireccion}<br>
                        Tel: {$clienteTel}<br>
                        Correo: {$clienteEmail}
                    </td>
                </tr>
            </table>
            <table class="lineas">
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th class="num">IVA</th>
                        <th class="num">P.U.</th>
                        <th class="num">Cant.</th>
                        <th class="num">Total (sin imp.)</th>
                    </tr>
                </thead>
                <tbody>
                    {$filas}
                </tbody>
            </table>
            <table class="totales">
                <tr><td class="label">Total (sin imp.)</td><td class="valor">{$this->fmt((float) $preorden->total_ht)}</td></tr>
                <tr><td class="label">{$etiquetaIva}</td><td class="valor">{$this->fmt((float) $preorden->total_tva)}</td></tr>
                <tr class="total"><td class="label">Total</td><td class="valor">{$this->fmt((float) $preorden->total_ttc)}</td></tr>
            </table>
        </body>
        </html>
        HTML;
    }

    private function fmt(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    private function resolverCliente(Request $request): Sociedad
    {
        if ($request->filled('customerId') || $request->filled('customer_id')) {
            $customerId = (int) ($request->input('customerId') ?? $request->input('customer_id'));
            $cliente = Sociedad::query()->find($customerId);
            $identificador = "con id [{$customerId}]";
        } elseif ($request->filled('siren')) {
            $siren = trim((string) $request->input('siren'));
            $cliente = Sociedad::query()->where('siren', $siren)->first();
            $identificador = "con documento [{$siren}]";
        } else {
            abort(401, "Debe enviar 'customerId' o 'siren' (documento del cliente).");
        }

        if (! $cliente) {
            abort(401, "El cliente {$identificador} no existe.");
        }
        if (! $cliente->client) {
            abort(401, "La entidad {$identificador} no es un cliente.");
        }
        if ((int) $cliente->status === 0) {
            abort(401, 'El cliente está inactivo.');
        }

        return $cliente;
    }

    private function resolverOrderReference(Request $request): string
    {
        $orderReference = trim((string) ($request->input('order_reference') ?? $request->input('refLocal', '')));

        if ($orderReference === '') {
            abort(401, "El campo 'order_reference' es obligatorio (identifica el pedido origen y evita duplicados).");
        }
        if (strlen($orderReference) > 255) {
            abort(401, "El campo 'order_reference' no puede exceder 255 caracteres.");
        }

        return $orderReference;
    }

    /**
     * Normaliza y valida las lineas. Cada linea debe traer idProduct o ref.
     *
     * @return array<int, array{idProduct: ?int, ref: ?string, cant: float, precio: ?float, tva_tx: ?float, discount: float, description: ?string}>
     */
    private function validarLineas(mixed $lineas): array
    {
        if (! is_array($lineas) || count($lineas) === 0) {
            abort(401, "El campo 'lines' es obligatorio y debe ser un arreglo con al menos un elemento.");
        }

        $normalizadas = [];
        $vistos = [];

        foreach ($lineas as $i => $linea) {
            $n = $i + 1;

            if (! is_array($linea)) {
                abort(401, "Línea {$n}: debe ser un objeto.");
            }

            $idProduct = (int) ($linea['idProduct'] ?? 0);
            $ref = trim((string) ($linea['ref'] ?? ''));
            if ($idProduct <= 0 && $ref === '') {
                abort(401, "Línea {$n}: debe enviar 'idProduct' o 'ref' del producto.");
            }

            $cant = $linea['cant'] ?? $linea['qty'] ?? null;
            if (! is_numeric($cant) || (float) $cant <= 0) {
                abort(401, "Línea {$n}: 'cant' es obligatorio y debe ser mayor a 0.");
            }

            $precio = $linea['nivelPrecioSinIva'] ?? $linea['precio'] ?? null;
            if ($precio !== null && (! is_numeric($precio) || (float) $precio < 0)) {
                abort(401, "Línea {$n}: 'precio' debe ser numérico y >= 0.");
            }

            $tvaTx = $linea['tva_tx'] ?? null;
            if ($tvaTx !== null && (! is_numeric($tvaTx) || $tvaTx < 0 || $tvaTx > 100)) {
                abort(401, "Línea {$n}: 'tva_tx' debe estar entre 0 y 100.");
            }

            $discount = $linea['discount'] ?? $linea['remise_percent'] ?? 0;
            if (! is_numeric($discount) || $discount < 0 || $discount > 100) {
                abort(401, "Línea {$n}: 'discount' debe estar entre 0 y 100.");
            }

            $clave = $idProduct > 0 ? "id:{$idProduct}" : "ref:{$ref}";
            if (isset($vistos[$clave])) {
                abort(401, "Producto duplicado en las líneas {$vistos[$clave]} y {$n}. Para pedir más cantidad ajuste 'cant' en una sola línea.");
            }
            $vistos[$clave] = $n;

            $normalizadas[] = [
                'idProduct' => $idProduct > 0 ? $idProduct : null,
                'ref' => $ref !== '' ? $ref : null,
                'cant' => (float) $cant,
                'precio' => $precio !== null ? (float) $precio : null,
                'tva_tx' => $tvaTx !== null ? (float) $tvaTx : null,
                'discount' => (float) $discount,
                'description' => isset($linea['description']) && $linea['description'] !== '' ? (string) $linea['description'] : null,
            ];
        }

        return $normalizadas;
    }

    /**
     * Carga los productos de las lineas (por idProduct o ref) y deja el idProduct
     * resuelto en cada linea. Devuelve los productos indexados por rowid.
     */
    private function resolverProductos(array &$lineas): \Illuminate\Support\Collection
    {
        $ids = collect($lineas)->pluck('idProduct')->filter()->unique()->values();
        $refs = collect($lineas)->whereNull('idProduct')->pluck('ref')->unique()->values();

        $productos = Producto::query()
            ->where(fn ($q) => $q->whereIn('rowid', $ids)->orWhereIn('ref', $refs))
            ->get();

        $porId = $productos->keyBy('rowid');
        $porRef = $productos->keyBy('ref');

        $vistos = [];
        foreach ($lineas as $i => &$linea) {
            $n = $i + 1;
            $producto = $linea['idProduct'] !== null ? $porId->get($linea['idProduct']) : $porRef->get($linea['ref']);

            if (! $producto) {
                $que = $linea['idProduct'] !== null ? "con id [{$linea['idProduct']}]" : "con referencia [{$linea['ref']}]";
                abort(401, "Línea {$n}: el producto {$que} no existe.");
            }

            $linea['idProduct'] = (int) $producto->rowid;
            if (isset($vistos[$linea['idProduct']])) {
                abort(401, "Producto duplicado en las líneas {$vistos[$linea['idProduct']]} y {$n}. Para pedir más cantidad ajuste 'cant' en una sola línea.");
            }
            $vistos[$linea['idProduct']] = $n;
        }
        unset($linea);

        return $porId;
    }

    private function precioSinIvaDelProducto(Producto $producto): float
    {
        $tva = (float) $producto->tva_tx;
        $precioTtc = (float) $producto->price_ttc;
        $precioHt = (float) $producto->price;

        if ($precioTtc <= 0 && $precioHt <= 0) {
            abort(401, "El producto {$producto->ref} no tiene precio de venta configurado; envíe 'precio' en la línea.");
        }

        return $precioTtc > 0 ? $precioTtc / (1 + $tva / 100) : $precioHt;
    }
}
