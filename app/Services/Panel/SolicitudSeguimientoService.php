<?php

namespace App\Services\Panel;

use App\Services\Correo\CorreoService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seguimiento de una solicitud: bitácora (observación), próximo contacto y, si se quiere, un correo al cliente en el mismo paso
 * (como el formulario de seguimiento de Dolibarr). El correo queda en la trazabilidad de la solicitud.
 */
class SolicitudSeguimientoService
{
    private string $p;

    public function __construct(
        private SolicitudFichaService $ficha,
        private SolicitudAsistenteService $asistente,
        private ActividadesService $actividades,
        private CorreoService $correo,
    ) {
        $this->p = DB::getTablePrefix();
    }

    /**
     * Lo necesario para abrir el formulario: contactos del cliente con correo (los de la solicitud, marcados), plantillas, firma,
     * remitente y los últimos seguimientos.
     *
     * @return array<string, mixed>
     */
    public function contexto(object $usuario, object $sol): array
    {
        $principales = DB::table('destino_solicitud_cotizacion')->where('solicitud_id', $sol->rowid)->whereNull('cotizacion_id')->pluck('id_contacto')->map(fn ($v) => (int) $v)->all();
        $principales[] = (int) $sol->contacto_id;
        $contactos = array_values(array_filter(
            $this->ficha->contactos((int) $sol->fk_soc),
            fn ($c) => $c['email'] !== '',
        ));
        foreach ($contactos as &$c) {
            $c['sugerido'] = in_array($c['id'], $principales, true);
        }
        unset($c);

        $ultimos = array_map(fn ($o) => [
            'fecha' => substr((string) $o->tms, 0, 16),
            'usuario' => $o->usuario,
            'texto' => mb_strimwidth(ActividadesService::decodificar($o->mensaje), 0, 220, '…'),
        ], DB::select("SELECT o.tms, o.mensaje, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) usuario FROM {$this->p}observaciones_solicitudes o
            LEFT JOIN {$this->p}user u ON u.rowid = o.fk_user_creat WHERE o.fk_solicitud = ? ORDER BY o.rowid DESC LIMIT 3", [$sol->rowid]));

        return [
            'solicitud' => ['id' => (int) $sol->rowid, 'ref' => $sol->ref, 'cliente' => $sol->cliente, 'asunto' => trim(strip_tags((string) $sol->asunto)), 'proximoContacto' => $sol->fecha_proximo_contacto ? substr((string) $sol->fecha_proximo_contacto, 0, 10) : null],
            'contactos' => $contactos,
            'ultimos' => $ultimos,
        ] + $this->asistente->contextoCorreo($usuario);
    }

    /**
     * @param  array{observacion?: ?string, fecha?: ?string, hora?: ?string, enviar?: bool, para?: array<int, string>, cc?: ?string, asunto?: ?string, cuerpo?: ?string, incluir_firma?: bool}  $d
     * @return array{message: string, correo: bool}
     */
    public function registrar(object $usuario, object $sol, array $d): array
    {
        $enviar = ! empty($d['enviar']);
        $obs = trim((string) ($d['observacion'] ?? ''));
        $para = [];
        $asunto = trim(str_replace('__REF__', (string) $sol->ref, (string) ($d['asunto'] ?? '')));
        $html = '';

        if ($enviar) {
            $para = $this->correos((array) ($d['para'] ?? []));
            if (! $para) {
                throw new InvalidArgumentException('Elige al menos un destinatario con correo válido.');
            }
            $cc = $this->correos(preg_split('/[;,\s]+/', (string) ($d['cc'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $cuerpo = trim((string) ($d['cuerpo'] ?? ''));
            if ($asunto === '' || trim(strip_tags($cuerpo)) === '') {
                throw new InvalidArgumentException('Escribe el asunto y el mensaje del correo.');
            }
            $html = strip_tags($cuerpo, '<p><br><strong><b><em><i><u><ul><ol><li><a><h3><h4><hr><table><tr><td><th><span><div>');
            if (! empty($d['incluir_firma']) && ($firma = $this->ficha->firmaHtml($usuario)) !== '') {
                $html .= '<br><br>'.$firma;
            }
            $r = $this->correo->enviarMensajePedido((int) $sol->fk_soc, 0, false, implode(';', $para), $asunto, $html, $cc);
            if (! $r['ok']) {
                throw new InvalidArgumentException($r['error'] ?? 'No se pudo enviar el correo.');
            }
            if ($obs === '') {
                $obs = 'Correo de seguimiento enviado a '.implode(', ', $para).': '.$asunto;
            }
        }
        if ($obs === '') {
            throw new InvalidArgumentException('Escribe qué pasó con el cliente.');
        }

        $fecha = ! empty($d['fecha']) ? $d['fecha'] : null;
        $r = $this->actividades->crear($usuario, 'solicitud', (int) $sol->rowid, $obs, $fecha, $d['hora'] ?? null, 'Seguimiento '.$sol->ref);

        if ($enviar) {
            DB::table('observaciones_solicitudes')->where('rowid', $r['observacion'])->update(['para' => mb_substr(implode(', ', $para), 0, 250), 'asunto' => mb_substr($asunto, 0, 250)]);
            DB::table('solicitudes_last_email')->insert([
                'subject' => mb_substr($asunto, 0, 250), 'content' => ActividadesService::codificar($html), 'description' => 'Seguimiento',
                'fk_object' => $sol->rowid, 'fk_user' => $usuario->getKey(),
            ]);
            DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['envio_email' => 1]);
        }

        return ['message' => $enviar ? 'Seguimiento guardado y correo enviado' : ($fecha ? 'Seguimiento guardado y contacto programado' : 'Seguimiento guardado'), 'correo' => $enviar];
    }

    /** @param  array<int, string>  $lista */
    private function correos(array $lista): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $lista), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}
