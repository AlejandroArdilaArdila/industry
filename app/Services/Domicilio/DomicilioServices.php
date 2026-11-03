<?php

namespace App\Services\Domicilio;

use App\Models\Domicilio;
use App\Models\Factura;
use App\Models\Usuario;
use App\Traits\AplicaFiltrosSyncfusion;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DomicilioServices
{
    use AplicaFiltrosSyncfusion;

    private const CAMPOS_ORDEN_PERMITIDOS = [
        'rowid', 'estado', 'numero_bolsas', 'fecha_asignacion', 'fecha_entrega',
    ];

    /**
     * Replica getDomiciliosPos(): listado paginado de domicilios con filtros
     * de fecha de factura y domiciliario. El original recibia el filtro
     * "where" como un fragmento SQL crudo concatenado a la consulta (riesgo
     * de inyeccion SQL); aqui se usa el mismo formato de filtros tipo
     * Syncfusion (where: [{field, operator, value}]) que ya usa SorteosService.
     */
    public function listarDomiciliosPos(Request $request): array
    {
        $data = $request->all();

        $skip = (int) ($data['skip'] ?? 0);
        $take = (int) ($data['take'] ?? 0);
        $where = is_array($data['where'] ?? null) ? $data['where'] : [];
        $sorted = is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        $dateValidIni = trim((string) ($data['date_valid_ini'] ?? ''));
        $dateValid = trim((string) ($data['date_valid'] ?? ''));
        $domiciliario = array_key_exists('domiciliario', $data) ? (string) $data['domiciliario'] : null;

        $aplicarFiltros = function (Builder $query) use ($where, $dateValidIni, $dateValid, $domiciliario) {
            $this->aplicarFiltrosSyncfusion($query, $where, self::CAMPOS_ORDEN_PERMITIDOS);

            $query
                ->when($dateValidIni !== '' && $dateValid !== '', function ($q) use ($dateValidIni, $dateValid) {
                    $q->whereHas('factura', fn ($q2) => $q2->whereBetween('datec', ["{$dateValidIni} 00:00:00", "{$dateValid} 23:59:59"]));
                })
                ->when($domiciliario !== null, function ($q) use ($domiciliario) {
                    $q->where('estado', '!=', 0);
                    if ($domiciliario !== 'T') {
                        $q->where('fk_domiciliario', (int) $domiciliario);
                    }
                });
        };

        $total = Domicilio::query()->tap($aplicarFiltros)->count();

        $ordenCampo = 'rowid';
        $ordenDireccion = 'asc';

        if (!empty($sorted[0]['name']) && in_array($sorted[0]['name'], self::CAMPOS_ORDEN_PERMITIDOS, true)) {
            $ordenCampo = $sorted[0]['name'];
            $ordenDireccion = ($sorted[0]['direction'] ?? '') === 'descending' ? 'desc' : 'asc';
        }

        $domicilios = Domicilio::query()
            ->tap($aplicarFiltros)
            ->with([
                'factura' => fn ($q) => $q->select(['rowid', 'ref', 'datec', 'total_ttc', 'fk_user_valid'])
                    ->with(['vendedor' => fn ($q2) => $q2->select('rowid', 'firstname', 'lastname')]),
                'domiciliario' => fn ($q) => $q->select('rowid', 'firstname', 'lastname'),
            ])
            ->orderBy($ordenCampo, $ordenDireccion)
            ->skip($skip)
            ->when($take > 0, fn ($q) => $q->take($take))
            ->get();

        $rowData = $domicilios->map(fn (Domicilio $d) => $this->formatearDomicilio($d))->values()->all();

        return ['rowData' => $rowData, 'rowCount' => $total];
    }

    private function formatearDomicilio(Domicilio $domicilio): array
    {
        $factura = $domicilio->factura;
        $vendedor = $factura?->vendedor;
        $domiciliarioUsuario = $domicilio->domiciliario;

        return [
            'rowid' => (int) $domicilio->rowid,
            'fk_facture' => (int) $domicilio->fk_facture,
            'direccion_entrega' => $domicilio->direccion_entrega,
            'telefono_entrega' => $domicilio->telefono_entrega,
            'detalle_entrega' => $domicilio->detalle_entrega,
            'numero_bolsas' => (int) $domicilio->numero_bolsas,
            'fk_tipo_domicilio' => $domicilio->fk_tipo_domicilio ? (int) $domicilio->fk_tipo_domicilio : null,
            'estado' => (int) $domicilio->estado,
            'fk_domiciliario' => $domicilio->fk_domiciliario ? (int) $domicilio->fk_domiciliario : null,
            'fecha_asignacion' => $domicilio->fecha_asignacion,
            'fecha_entrega' => $domicilio->fecha_entrega,
            'comentario' => $domicilio->comentario,
            'auditoria' => $domicilio->auditoria,
            'ref' => $factura?->ref,
            'datec' => $factura?->datec,
            'total_ttc' => $factura ? (float) $factura->total_ttc : 0.0,
            'idVendedor' => $factura?->fk_user_valid ? (int) $factura->fk_user_valid : null,
            'nomVendedor' => $vendedor ? trim($vendedor->firstname.' '.$vendedor->lastname) : null,
            'nomDomiciliario' => $domiciliarioUsuario ? trim($domiciliarioUsuario->firstname.' '.$domiciliarioUsuario->lastname) : null,
        ];
    }

    /**
     * Replica postPedidoDomicilio(): confirma un pedido facturado como domicilio,
     * guardando la direccion/telefono de entrega y el numero de bolsas.
     */
    public function confirmarDomicilio(Request $request, Usuario $usuario): array
    {
        /**if (!$usuario->tieneDerecho('backend', 'read')) {
            abort(401, 'Este usuario no tiene permisos para esta acción');
        }*/

        $idFacture = trim((string) $request->input('fk_facture', ''));

        
        
        $direccionEntrega = $request->input('domiDireccion');
        if ($direccionEntrega === null || $direccionEntrega === '') {
            abort(401, 'La dirección del pedido es obligatoria.');
        }

        $telefonoEntrega = $request->input('domiTelefono');
        if ($telefonoEntrega === null || $telefonoEntrega === '') {
            abort(401, 'El teléfono de contacto es obligatorio.');
        }

        $numeroBolsas = $request->input('domiBolsas');
        if ($numeroBolsas === null || $numeroBolsas === '') {
            abort(401, 'La cantidad de bolsas es obligatoria.');
        }
        if (!is_numeric($numeroBolsas)) {
            abort(401, 'La cantidad de bolsas debe ser un valor numérico.');
        }

        $detalleEntrega = $request->input('domiObservaciones') ?? '';
        $tipoDomicilio = $request->input('domiTipo');

        $domicilio = Domicilio::query()->where('fk_facture', $idFacture)->first();
        if ($domicilio){
            if ($domicilio->estado != 0){
                abort(401, 'No se puede editar un domicilio asignado o entregado.');
            }
        }else{
            $domicilio = new Domicilio();    
            $domicilio->fk_facture = $idFacture;
        }
                
        $domicilio->direccion_entrega = $direccionEntrega;
        $domicilio->telefono_entrega = $telefonoEntrega;
        $domicilio->detalle_entrega = $detalleEntrega;
        $domicilio->numero_bolsas = $numeroBolsas;
        $domicilio->fk_tipo_domicilio = $tipoDomicilio !== null && $tipoDomicilio !== '' ? (int) $tipoDomicilio : null;
        $domicilio->save();

        return [
            'message' => 'Domicilio confirmado',
            'status' => 0,
        ];
    }
}
