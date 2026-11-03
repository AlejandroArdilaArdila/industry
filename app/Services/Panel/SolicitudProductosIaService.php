<?php

namespace App\Services\Panel;

use App\Services\Ia\IaClient;
use App\Services\Ia\IaConfigService;
use App\Services\Ia\IaException;
use App\Services\Ia\SolicitudPromptsService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inteligencia de abastecimiento para los productos de una solicitud: qué dice el historial de Dolibarr
 * (proveedores del catálogo, compras, cotizaciones a clientes) y, con IA, dónde conseguirlos.
 */
class SolicitudProductosIaService
{
    private string $p;

    public function __construct(private IaClient $ia, private IaConfigService $iaConfig, private SolicitudPromptsService $prompts)
    {
        $this->p = DB::getTablePrefix();
    }

    /**
     * @param  bool  $conIa  false = solo el historial interno (respuesta inmediata, sin gastar IA)
     * @param  array<int, array{key: string, fk_product?: ?int, description: string, model?: ?string, qty?: mixed}>  $lineas
     * @return array{items: array, ia_error: ?string}
     */
    public function analizar(array $lineas, bool $conIa = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['fk_product'] ?? 0), $lineas))));
        $historial = $ids ? $this->historial($ids) : [];

        $items = [];
        foreach ($lineas as $l) {
            $id = (int) ($l['fk_product'] ?? 0);
            $items[$l['key']] = ['key' => $l['key'], 'fk_product' => $id ?: null, 'historial' => $historial[$id] ?? null, 'resumen' => null, 'donde_conseguir' => []];
        }

        if (! $conIa) {
            return ['items' => array_values($items), 'ia_error' => null];
        }

        $error = null;
        try {
            foreach ($this->consultarIa($lineas, $items) as $r) {
                if (isset($items[$r['key'] ?? ''])) {
                    $items[$r['key']]['resumen'] = is_string($r['resumen'] ?? null) ? $r['resumen'] : null;
                    $items[$r['key']]['donde_conseguir'] = $this->opciones((array) ($r['donde_conseguir'] ?? []));
                }
            }
        } catch (IaException $e) {
            $error = $e->getMessage();
        } catch (Throwable) {
            $error = 'La IA no devolvió una respuesta válida. Inténtalo de nuevo.';
        }

        return ['items' => array_values($items), 'ia_error' => $error];
    }

    /** Normaliza las opciones de compra de la IA; la url solo se acepta si es http(s). */
    public function opciones(array $lista): array
    {
        $txt = fn ($v, int $max = 200) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $out = [];
        foreach (array_slice($lista, 0, 5) as $o) {
            if (is_string($o)) {
                $o = ['proveedor' => $o];
            }
            if (! is_array($o) || $txt($o['proveedor'] ?? '') === '') {
                continue;
            }
            $url = $txt($o['url'] ?? '', 500);
            $out[] = [
                'proveedor' => $txt($o['proveedor']),
                'pais' => $txt($o['pais'] ?? '', 60),
                'precio' => $txt($o['precio'] ?? '', 80),
                'url' => preg_match('#^https?://#i', $url) ? $url : '',
                'nota' => $txt($o['nota'] ?? '', 200),
            ];
        }

        return $out;
    }

    private function consultarIa(array $lineas, array $items): array
    {
        $proveedor = $this->iaConfig->predeterminado();
        $envio = array_map(fn ($l) => [
            'key' => $l['key'],
            'descripcion' => $l['description'],
            'modelo' => $l['model'] ?? null,
            'cantidad' => $l['qty'] ?? null,
            'historial' => $items[$l['key']]['historial'] ? ['cotizaciones' => $items[$l['key']]['historial']['cotizaciones'], 'ordenes' => $items[$l['key']]['historial']['ordenes'], 'precios' => $items[$l['key']]['historial']['resumen'], 'ultimos' => array_slice($items[$l['key']]['historial']['precios'], 0, 5)] : null,
        ], $lineas);
        $texto = $this->ia->chat($proveedor, $this->prompts->sistema('productos'), [['role' => 'user', 'content' => json_encode($envio, JSON_UNESCAPED_UNICODE)]], true);

        $ini = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        $json = $ini !== false && $fin !== false ? json_decode(substr($texto, $ini, $fin - $ini + 1), true) : null;
        if (! is_array($json) || ! is_array($json['productos'] ?? null)) {
            throw new IaException('La IA no devolvió una respuesta válida. Inténtalo de nuevo.');
        }

        return $json['productos'];
    }

    /** tipo_moneda de la cotización: 1 = dólares, 2 = pesos colombianos. */
    private const MONEDAS = [1 => 'USD', 2 => 'COP'];

    /**
     * Historial interno por producto: precios de las cotizaciones (líneas de solicitud con su cotización) y de las órdenes
     * de compra del cliente, cada uno en su moneda y convertido a USD y COP con la TRM de la cotización.
     * Cada consulta falla en silencio (sin ella la ficha sigue funcionando).
     */
    public function historial(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $filas = [];

        $consultas = [
            ['Cotización', "SELECT sl.fk_product, cc.rowid doc_id, NULL imp_id, cc.ref, s.nom tercero, DATE(cc.fecha) fecha, cc.status estado, sl.qty, sl.subprice valor, cc.tipo_moneda, cc.trm, NULL importacion
                FROM {$this->p}solicitudes_solicitudline sl
                JOIN {$this->p}cotizaciones_clientecotizacion cc ON cc.rowid = sl.fk_cotizacion
                LEFT JOIN {$this->p}societe s ON s.rowid = cc.fk_soc
                WHERE sl.fk_product IN ($in) AND sl.subprice > 0 ORDER BY cc.fecha DESC"],
            ['Orden', "SELECT pt.fk_product, o.rowid doc_id, o.fk_importacion imp_id, o.ref, s.nom tercero, DATE(o.date_creation) fecha, o.status estado, pt.cantidad qty, pt.precio valor, cc.tipo_moneda, cc.trm, im.ref importacion
                FROM {$this->p}ordencompra_product_trm pt
                JOIN {$this->p}ordencompra_orden o ON o.rowid = pt.fk_orden
                LEFT JOIN {$this->p}societe s ON s.rowid = o.fk_soc
                LEFT JOIN {$this->p}cotizaciones_clientecotizacion cc ON cc.rowid = o.fk_cliente_cotizacion
                LEFT JOIN {$this->p}importacion_importaciones im ON im.rowid = o.fk_importacion
                WHERE pt.fk_product IN ($in) AND pt.precio > 0 ORDER BY o.date_creation DESC"],
        ];
        foreach ($consultas as [$tipo, $sql]) {
            try {
                foreach (DB::select($sql, $ids) as $r) {
                    $filas[(int) $r->fk_product][] = $this->precio($tipo, $r);
                }
            } catch (Throwable) {
                // tabla o columna ausente en esta instalación
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $precios = $filas[$id] ?? [];
            usort($precios, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));
            $out[$id] = [
                'precios' => array_slice($precios, 0, 60),
                'total_precios' => count($precios),
                'cotizaciones' => count(array_filter($precios, fn ($p) => $p['tipo'] === 'Cotización')),
                'ordenes' => count(array_filter($precios, fn ($p) => $p['tipo'] === 'Orden')),
                'resumen' => $this->resumenPrecios($precios),
            ];
        }

        return $out;
    }

    /** Un precio con su moneda de origen y el equivalente en USD y COP (null si no hay TRM para convertir). */
    private function precio(string $tipo, object $r): array
    {
        $moneda = self::MONEDAS[(int) $r->tipo_moneda] ?? null;
        $valor = (float) $r->valor;
        $trm = (float) $r->trm > 1000 ? (float) $r->trm : null; // una TRM en cero o absurda no sirve para convertir
        $usd = $moneda === 'USD' ? $valor : ($moneda === 'COP' && $trm ? $valor / $trm : null);
        $cop = $moneda === 'COP' ? $valor : ($moneda === 'USD' && $trm ? $valor * $trm : null);

        $base = config('services.dolibarr.url');

        return [
            'url' => $r->doc_id ? $base.($tipo === 'Orden' ? '/custom/ordencompra/orden_card.php?id=' : '/custom/cotizaciones/clientecotizacion_card.php?id=').(int) $r->doc_id : null,
            'url_importacion' => $r->imp_id ? $base.'/custom/importacion/importaciones_card.php?id='.(int) $r->imp_id : null,
            'tipo' => $tipo, 'ref' => $r->ref, 'tercero' => $r->tercero, 'fecha' => $r->fecha, 'estado' => (int) $r->estado,
            'cantidad' => (float) $r->qty, 'importacion' => $r->importacion, 'moneda' => $moneda, 'valor' => $valor,
            'usd' => $usd !== null ? round($usd, 2) : null, 'cop' => $cop !== null ? round($cop) : null,
        ];
    }

    /** Promedio, mínimo, máximo y último precio, en USD y en COP. */
    private function resumenPrecios(array $precios): array
    {
        $res = [];
        foreach (['usd', 'cop'] as $m) {
            $v = array_values(array_filter(array_column($precios, $m), fn ($x) => $x !== null));
            $res[$m] = $v ? ['promedio' => round(array_sum($v) / count($v), $m === 'usd' ? 2 : 0), 'min' => min($v), 'max' => max($v), 'n' => count($v)] : null;
        }

        return $res;
    }
}
