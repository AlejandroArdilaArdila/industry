<?php

namespace App\Services\Panel;

use App\Services\Correo\CorreoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Crear y gestionar solicitudes (port de solicitud_card.php / solicitud.class.php del sistema anterior):
 * ficha por pasos con datos generales, productos, detalle/correo y estado (cierre y reapertura).
 */
class SolicitudFichaService
{
    public const PERMISO_LEER = 600100;
    public const PERMISO_ESCRIBIR = 600101;
    public const ACTIVO = 1;
    public const CERRADA = 9;
    private const PREFIJO_REF = 'RQ';
    private const PLANTILLA_POR_DEFECTO = 'respuesta solicitud cliente';

    private string $p;

    public function __construct(private PermisosDolibarr $permisos, private ActividadesService $actividades, private CorreoService $correo)
    {
        $this->p = DB::getTablePrefix();
    }

    public function puedeEditar(?object $usuario): bool
    {
        return $this->permisos->tiene($usuario, self::PERMISO_ESCRIBIR);
    }

    /** Un usuario que no es administrador solo gestiona las solicitudes que le pertenecen. */
    public function puedeVer(object $usuario, object $sol): bool
    {
        $campo = $this->actividades->campoUsuario();

        return $usuario->isAdmin() || (int) ($sol->{$campo} ?? $sol->fk_user_creat) === (int) $usuario->getKey();
    }

    public function solicitud(int $id): ?object
    {
        return DB::selectOne("SELECT t.*, s.nom cliente FROM {$this->p}solicitudes_solicitud t LEFT JOIN {$this->p}societe s ON s.rowid = t.fk_soc WHERE t.rowid = ?", [$id]);
    }

    // ---------------------------------------------------------------- catálogos

    /** @return array{clientes: array, motivos: array, plantillas: array} */
    public function catalogos(object $usuario): array
    {
        $clientes = DB::select("SELECT rowid id, nom label FROM {$this->p}societe WHERE status = 1 AND client IN (1,2,3) ORDER BY nom");
        $motivos = DB::select("SELECT rowid id, label FROM {$this->p}c_motivo_cierre WHERE active = 1 ORDER BY label");
        $plantillas = DB::select("SELECT rowid id, label, topic, content FROM {$this->p}c_email_templates WHERE active = 1 AND (fk_user IS NULL OR fk_user = 0 OR fk_user = ?) ORDER BY position, label", [$usuario->getKey()]);

        return [
            'clientes' => array_map(fn ($c) => ['id' => (int) $c->id, 'label' => (string) $c->label], $clientes),
            'motivos' => array_map(fn ($m) => ['id' => (int) $m->id, 'label' => (string) $m->label], $motivos),
            'plantillas' => array_map(fn ($t) => ['id' => (int) $t->id, 'label' => (string) $t->label, 'topic' => (string) $t->topic, 'content' => (string) $t->content], $plantillas),
        ];
    }

    /** Contactos del cliente (con correo cuando lo tienen). */
    public function contactos(int $fkSoc): array
    {
        $rows = DB::select("SELECT rowid, TRIM(CONCAT_WS(' ', firstname, lastname)) nombre, email, poste, phone, phone_mobile, phone_perso, birthday
            FROM {$this->p}socpeople WHERE fk_soc = ? AND statut = 1 ORDER BY lastname, firstname", [$fkSoc]);

        return array_map(fn ($c) => [
            'id' => (int) $c->rowid, 'label' => trim(($c->nombre ?: 'Sin nombre').($c->email ? ' — '.$c->email : '')), 'email' => (string) $c->email,
            'nombre' => $c->nombre ?: 'Sin nombre', 'cargo' => trim((string) $c->poste),
            // Fijo, móvil y personal, sin repetidos ni vacíos.
            'telefonos' => array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), [$c->phone_mobile, $c->phone, $c->phone_perso])))),
            'cumple' => $c->birthday ? substr((string) $c->birthday, 0, 10) : null,
        ], $rows);
    }

    public function productos(string $q, int $limite = 12): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($q)).'%';
        $rows = DB::select("SELECT rowid, ref, label FROM {$this->p}product WHERE tosell = 1 AND (ref LIKE ? OR label LIKE ?) ORDER BY ref LIMIT {$limite}", [$like, $like]);

        return array_map(fn ($r) => ['id' => (int) $r->rowid, 'ref' => $r->ref, 'label' => $r->label], $rows);
    }

    // ---------------------------------------------------------------- lectura

    /** @return array<string, mixed> */
    public function datosFicha(?object $sol): array
    {
        if (! $sol) {
            return ['lineas' => [], 'relacionados' => [], 'contactos' => [], 'historial' => [], 'motivo' => null, 'creador' => null];
        }
        $lineas = DB::select("SELECT l.rowid, l.fk_product, pr.ref producto_ref, pr.label producto_label, l.description, l.model, l.qty
            FROM {$this->p}solicitudes_solicitudline l LEFT JOIN {$this->p}product pr ON pr.rowid = l.fk_product
            WHERE l.fk_solicitud = ? AND l.fk_cotizacion IS NULL ORDER BY l.rang, l.rowid", [$sol->rowid]);
        $guardados = $this->analisisGuardado(array_map(fn ($l) => (int) $l->rowid, $lineas));
        foreach ($lineas as $l) {
            $l->analisis = $guardados[(int) $l->rowid] ?? null;
        }
        $relacionados = DB::table('destino_solicitud_cotizacion')->where('solicitud_id', $sol->rowid)->whereNull('cotizacion_id')->pluck('id_contacto')->map(fn ($v) => (int) $v)->all();
        $historial = DB::select("SELECT h.fecha, h.estado, CONCAT_WS(' ', u.firstname, u.lastname) usuario FROM {$this->p}solicitudes_historico_estados h
            LEFT JOIN {$this->p}user u ON u.rowid = h.fk_user WHERE h.fk_solicitud = ? ORDER BY h.rowid DESC LIMIT 50", [$sol->rowid]);
        $motivo = $sol->motivo_cierre ? DB::table('c_motivo_cierre')->where('rowid', $sol->motivo_cierre)->value('label') : null;
        $creador = DB::selectOne("SELECT CONCAT_WS(' ', firstname, lastname) n FROM {$this->p}user WHERE rowid = ?", [$sol->fk_user_creat])->n ?? null;

        return [
            'lineas' => $lineas,
            'relacionados' => $relacionados,
            'contactos' => $this->contactos((int) $sol->fk_soc),
            'historial' => $historial,
            'motivo' => $motivo,
            'creador' => $creador,
        ];
    }

    // ---------------------------------------------------------------- escritura

    /** @param array<string, mixed> $d */
    public function crear(object $usuario, array $d): int
    {
        // El consecutivo se calcula con MAX(): se serializa para que dos usuarios no obtengan la misma referencia.
        DB::select("SELECT GET_LOCK('solicitud_ref', 10)");
        try {
            $id = $this->insertar($usuario, $d);
        } finally {
            DB::select("SELECT RELEASE_LOCK('solicitud_ref')");
        }

        if (! empty($d['fecha_proximo_contacto'])) {
            try {
                $ref = DB::table('solicitudes_solicitud')->where('rowid', $id)->value('ref');
                $this->actividades->crear($usuario, 'solicitud', $id, 'Próximo contacto programado al crear la solicitud.', Carbon::parse($d['fecha_proximo_contacto'])->format('Y-m-d'), null, 'Próximo contacto '.$ref);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $id;
    }

    /** @param array<string, mixed> $d */
    private function insertar(object $usuario, array $d): int
    {
        return DB::transaction(function () use ($usuario, $d) {
            $ahora = now();
            $proximo = $d['fecha_proximo_contacto'] ?? null;
            $fila = [
                'fk_soc' => $d['fk_soc'],
                'date_creation' => $ahora,
                'fk_user_creat' => $usuario->getKey(),
                'ref' => $this->siguienteRef($ahora),
                'contacto_id' => $d['contacto_id'],
                'fecha_solicitud' => $d['fecha_solicitud'],
                'fecha_proximo_contacto' => $proximo ?: null,
                'detalle' => '',
                'pendiente' => $proximo ? 0 : 1,
                'asunto' => $d['asunto'],
                'status' => self::ACTIVO,
                'envio_email' => 0,
            ];
            if (Schema::hasColumn('solicitudes_solicitud', 'fk_user_gestion')) {
                $fila['fk_user_gestion'] = $usuario->getKey();
            }
            $id = DB::table('solicitudes_solicitud')->insertGetId($fila);
            $this->guardarRelacionados($id, (int) $d['contacto_id'], $d['relacionados'] ?? []);
            $this->historial($id, (int) $usuario->getKey(), 'Activo');

            return $id;
        });
    }

    /**
     * Guarda un paso de la ficha.
     *
     * @param array<string, mixed> $d
     */
    /** @return array<string, mixed>|null  con `lineas` (ids en el orden enviado) al guardar los productos */
    public function guardar(object $usuario, object $sol, string $seccion, array $d): ?array
    {
        if ((int) $sol->status !== self::ACTIVO) {
            throw new InvalidArgumentException('Solo se puede modificar una solicitud activa.');
        }

        return match ($seccion) {
            'general' => $this->guardarGeneral($sol, $d),
            'productos' => ['lineas' => $this->guardarLineas($usuario, (int) $sol->rowid, $d['lineas'] ?? [])],
            'detalle' => $this->guardarDetalle($usuario, $sol, $d),
            default => throw new InvalidArgumentException('Paso inválido.'),
        };
    }

    private function guardarGeneral(object $sol, array $d): void
    {
        DB::transaction(function () use ($sol, $d) {
            $proximo = $d['fecha_proximo_contacto'] ?? null;
            DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update([
                'fk_soc' => $d['fk_soc'],
                'contacto_id' => $d['contacto_id'],
                'fecha_solicitud' => $d['fecha_solicitud'],
                'fecha_proximo_contacto' => $proximo ?: null,
                'pendiente' => $proximo ? 0 : 1,
                'asunto' => $d['asunto'],
                'fk_user_modif' => auth('web')->id(),
            ]);
            $this->guardarRelacionados((int) $sol->rowid, (int) $d['contacto_id'], $d['relacionados'] ?? []);
        });
    }

    /** Contactos relacionados: el principal siempre queda dentro (como setContacts del sistema anterior). */
    private function guardarRelacionados(int $id, int $principal, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge([$principal], $ids)))));
        DB::table('destino_solicitud_cotizacion')->where('solicitud_id', $id)->whereNull('cotizacion_id')->delete();
        DB::table('destino_solicitud_cotizacion')->insert(array_map(fn ($c) => [
            'solicitud_id' => $id, 'cotizacion_id' => null, 'id_contacto' => $c, 'principal' => $c === $principal ? 1 : 0,
        ], $ids));
    }

    /** Actualiza, agrega y quita líneas conservando los rowid de las que siguen (otros documentos pueden referirlas). */
    /** @return array<int, int>  ids de las líneas, en el orden recibido */
    private function guardarLineas(object $usuario, int $id, array $lineas): array
    {
        $analisis = [];
        DB::transaction(function () use ($id, $lineas, &$analisis) {
            $existentes = DB::table('solicitudes_solicitudline')->where('fk_solicitud', $id)->whereNull('fk_cotizacion')->pluck('rowid')->map(fn ($v) => (int) $v)->all();
            $conservar = [];
            foreach (array_values($lineas) as $rang => $l) {
                $fila = [
                    'fk_product' => ! empty($l['fk_product']) ? (int) $l['fk_product'] : null,
                    'description' => trim((string) $l['description']),
                    'label' => mb_substr(trim((string) $l['description']), 0, 250),
                    'model' => ($l['model'] ?? '') !== '' ? mb_substr((string) $l['model'], 0, 40) : null,
                    'qty' => (float) $l['qty'],
                    'rang' => $rang + 1,
                ];
                $lid = (int) ($l['id'] ?? 0);
                if ($lid && in_array($lid, $existentes, true)) {
                    DB::table('solicitudes_solicitudline')->where('rowid', $lid)->update($fila);
                } else {
                    $lid = DB::table('solicitudes_solicitudline')->insertGetId($fila + [
                        'fk_solicitud' => $id, 'tva_tx' => 0, 'subprice' => 0, 'total_ht' => 0, 'total_tva' => 0, 'total_ttc' => 0, 'product_type' => 0,
                    ]);
                }
                $conservar[] = $lid;
                $analisis[$lid] = ['fk_product' => $fila['fk_product'], 'datos' => (string) ($l['analisis'] ?? '')];
            }
            $quitar = array_diff($existentes, $conservar);
            if ($quitar) {
                DB::table('solicitudes_solicitudline')->whereIn('rowid', $quitar)->delete();
                if (Schema::hasTable(self::TABLA_ANALISIS)) {
                    DB::table(self::TABLA_ANALISIS)->whereIn('fk_solicitudline', $quitar)->delete();
                }
            }
        });
        $this->guardarAnalisis($usuario, $analisis);

        return array_keys($analisis);
    }

    // ---------------------------------------------------------------- análisis de productos (IA + precios)

    /** Tabla propia del panel: una fila por línea con el análisis de la última vez que se hizo. */
    public const TABLA_ANALISIS = 'solicitudes_analisis_linea';

    private function asegurarTablaAnalisis(): bool
    {
        try {
            if (! Schema::hasTable(self::TABLA_ANALISIS)) {
                Schema::create(self::TABLA_ANALISIS, function ($t) {
                    $t->charset = 'utf8mb4';
                    $t->collation = 'utf8mb4_unicode_ci';
                    $t->increments('rowid');
                    $t->unsignedInteger('fk_solicitudline')->unique();
                    $t->longText('datos');
                    $t->dateTime('fecha_analisis');
                    $t->unsignedInteger('fk_user')->nullable();
                });
            }

            return true;
        } catch (\Throwable) {
            return false; // sin permiso para crearla: las líneas se guardan igual, solo que sin el análisis
        }
    }

    /**
     * Guarda el análisis de cada línea que lo trae. El cliente solo aporta lo que dijo la IA; el historial de precios
     * se vuelve a consultar aquí, para que lo guardado sea siempre el dato real del sistema en ese momento.
     *
     * @param  array<int, array{fk_product: ?int, datos: string}>  $analisis  por id de línea
     */
    private function guardarAnalisis(object $usuario, array $analisis): void
    {
        $analisis = array_filter($analisis, fn ($a) => trim($a['datos']) !== '');
        if (! $analisis || ! $this->asegurarTablaAnalisis()) {
            return;
        }
        $ia = app(SolicitudProductosIaService::class);
        $ids = array_values(array_unique(array_filter(array_map(fn ($a) => (int) $a['fk_product'], $analisis))));
        $historial = $ids ? $ia->historial($ids) : [];
        foreach ($analisis as $lid => $a) {
            $d = json_decode($a['datos'], true);
            if (! is_array($d)) {
                continue;
            }
            $datos = [
                'historial' => $a['fk_product'] ? ($historial[(int) $a['fk_product']] ?? null) : null,
                'resumen' => is_string($d['resumen'] ?? null) ? mb_substr($d['resumen'], 0, 1500) : null,
                'donde_conseguir' => $ia->opciones((array) ($d['donde_conseguir'] ?? [])),
                'guardado_en' => now()->format('Y-m-d H:i'),
            ];
            DB::table(self::TABLA_ANALISIS)->updateOrInsert(
                ['fk_solicitudline' => (int) $lid],
                ['datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'fecha_analisis' => now(), 'fk_user' => $usuario->getKey()]
            );
        }
    }

    /** @param  array<int, int>  $ids  ids de línea  @return array<int, array> */
    private function analisisGuardado(array $ids): array
    {
        if (! $ids || ! Schema::hasTable(self::TABLA_ANALISIS)) {
            return [];
        }
        $out = [];
        foreach (DB::table(self::TABLA_ANALISIS)->whereIn('fk_solicitudline', $ids)->get(['fk_solicitudline', 'datos']) as $r) {
            $d = json_decode((string) $r->datos, true);
            if (is_array($d)) {
                $out[(int) $r->fk_solicitudline] = $d;
            }
        }

        return $out;
    }

    /**
     * Firma del usuario (campo «signature» de Dolibarr) como HTML limpio para un correo: texto plano pasa a párrafos, y del HTML
     * se quitan scripts, manejadores de eventos y enlaces javascript:. Conserva imágenes (logos) y enlaces.
     */
    public function firmaHtml(object $usuario): string
    {
        try {
            $f = trim((string) (DB::selectOne("SELECT signature FROM {$this->p}user WHERE rowid = ?", [$usuario->getKey()])->signature ?? ''));
        } catch (\Throwable) {
            return '';
        }
        if ($f === '') {
            return '';
        }
        if (! preg_match('/<[a-z][\s\S]*>/i', $f)) {
            return nl2br(e($f));
        }
        $f = strip_tags($f, '<p><br><div><span><b><strong><i><em><u><a><img><table><tbody><tr><td><font>');
        $f = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $f);
        $f = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $f);

        // Dolibarr guarda las imágenes con ruta relativa a su servidor («/viewimage.php?…»): aquí y en el correo que recibe el cliente
        // no existirían, así que se completan con la dirección de Dolibarr.
        $base = rtrim((string) config('services.dolibarr.url'), '/');
        if ($base !== '') {
            $f = preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(\/(?!\/)[^"\']*)\2/i', fn ($m) => $m[1].$m[2].$base.$m[3].$m[2], $f);
        }

        return trim($f);
    }

    private function guardarDetalle(object $usuario, object $sol, array $d): void
    {
        // El detalle es HTML escrito en el editor de la ficha: se conservan solo las etiquetas de formato básicas.
        $detalle = strip_tags((string) ($d['detalle'] ?? ''), '<p><br><div><span><b><strong><i><em><u><ul><ol><li><a><h1><h2><h3><h4><table><thead><tbody><tr><td><th>');
        // Con «incluir_firma» (asistente de creación) la firma del asesor se añade al final, ya limpia, tal como se vio en la vista previa.
        if (! empty($d['incluir_firma']) && ($firma = $this->firmaHtml($usuario)) !== '') {
            $detalle .= '<br><br>'.$firma;
        }
        DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['detalle' => $detalle, 'fk_user_modif' => $usuario->getKey()]);

        if (! empty($d['enviar_correo'])) {
            $this->enviarCorreo($usuario, $sol, (string) ($d['asunto_correo'] ?? ''), $detalle);
        }
    }

    /** Envía el detalle al contacto principal y a los relacionados (los que tengan correo). */
    public function enviarCorreo(object $usuario, object $sol, string $asunto, string $cuerpo): void
    {
        $ids = DB::table('destino_solicitud_cotizacion')->where('solicitud_id', $sol->rowid)->whereNull('cotizacion_id')->pluck('id_contacto')->all();
        $ids[] = $sol->contacto_id;
        $correos = DB::table('socpeople')->whereIn('rowid', array_unique($ids))->whereNotNull('email')->where('email', '<>', '')->pluck('email')->unique()->all();
        if (! $correos) {
            throw new InvalidArgumentException('Los contactos de la solicitud no tienen correo electrónico.');
        }
        $asunto = str_replace('__REF__', (string) $sol->ref, $asunto !== '' ? $asunto : 'SOLICITUD CREADA __REF__');
        $html = preg_match('/<[a-z][\s\S]*>/i', $cuerpo) ? $cuerpo : nl2br(e($cuerpo));

        $r = $this->correo->enviarMensajePedido((int) $sol->fk_soc, 0, false, implode(';', $correos), $asunto, $html);
        if (! $r['ok']) {
            throw new InvalidArgumentException($r['error'] ?? 'No se pudo enviar el correo.');
        }
        DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['envio_email' => 1]);
    }

    public function cerrar(object $usuario, object $sol, int $motivo): void
    {
        if ((int) $sol->status !== self::ACTIVO) {
            throw new InvalidArgumentException('Solo se puede cerrar una solicitud activa.');
        }
        DB::transaction(function () use ($usuario, $sol, $motivo) {
            DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['status' => self::CERRADA, 'motivo_cierre' => $motivo, 'fk_user_modif' => $usuario->getKey()]);
            $this->historial((int) $sol->rowid, (int) $usuario->getKey(), 'Cerrada');
        });
    }

    public function reabrir(object $usuario, object $sol): void
    {
        if ((int) $sol->status !== self::CERRADA) {
            throw new InvalidArgumentException('Solo se puede reabrir una solicitud cerrada.');
        }
        DB::transaction(function () use ($usuario, $sol) {
            DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['status' => self::ACTIVO, 'fecha_reapertura' => now(), 'fk_user_modif' => $usuario->getKey()]);
            // Igual que el sistema anterior: si la cotización exterior ligada estaba cancelada, se reactiva.
            try {
                DB::table('cotiext_exterior')->where('fk_solicitud', $sol->rowid)->where('status', 9)->update(['status' => 1]);
            } catch (\Throwable $e) {
                report($e);
            }
            $this->historial((int) $sol->rowid, (int) $usuario->getKey(), 'Activo');
        });
    }

    private function historial(int $id, int $uid, string $estado): void
    {
        DB::table('solicitudes_historico_estados')->insert(['fk_solicitud' => $id, 'fk_user' => $uid, 'fecha' => now(), 'estado' => $estado]);
    }

    /** RQ + AAMM + "-" + consecutivo de 4 dígitos (el consecutivo es global, como mod_solicitud_standard). */
    private function siguienteRef(Carbon $fecha): string
    {
        $pos = strlen(self::PREFIJO_REF) + 6;
        $max = (int) (DB::selectOne("SELECT MAX(CAST(SUBSTRING(ref FROM {$pos}) AS SIGNED)) m FROM {$this->p}solicitudes_solicitud WHERE ref LIKE ?", [self::PREFIJO_REF.'____-%'])->m ?? 0);
        $num = $max >= 9999 ? $max + 1 : sprintf('%04d', $max + 1);

        return self::PREFIJO_REF.$fecha->format('ym').'-'.$num;
    }
}
