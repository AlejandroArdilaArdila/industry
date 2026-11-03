<?php

namespace App\Services\Correo;

use App\Models\Configuracion;
use App\Models\Factura;
use App\Models\Sociedad;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Replica enviarMensajePedido() de api_app.class.php (modulo integrosapk):
 * envia un correo al cliente usando el SMTP configurado en Dolibarr
 * (const MAIN_MAIL_*), con el PDF de la factura adjunto si se pide.
 *
 * A diferencia del legacy, no regenera el PDF (requeriria bootstrapear
 * Dolibarr): solo adjunta el que ya existe en disco.
 */
class CorreoService
{
    /**
     * @return array{ok:bool, to?:string, cliente?:string, adjunto_pdf?:bool, error?:string, status?:int}
     */
    public function enviarMensajePedido(
        int $idCliente,
        int $idFactura,
        bool $enviarPdf,
        ?string $email,
        string $asunto,
        string $cuerpo,
    ): array {
        $email = trim((string) $email);
        $asunto = trim($asunto);
        $cuerpo = trim($cuerpo);

        if ($idCliente <= 0) {
            return ['ok' => false, 'error' => 'id_cliente requerido', 'status' => 401];
        }
        if ($asunto === '') {
            return ['ok' => false, 'error' => 'asunto requerido', 'status' => 401];
        }
        if ($cuerpo === '') {
            return ['ok' => false, 'error' => 'cuerpo requerido', 'status' => 401];
        }

        $cliente = Sociedad::query()
            ->where('rowid', $idCliente)
            ->where('entity', 1)
            ->select(['rowid', 'nom', 'email'])
            ->first();

        if (! $cliente) {
            return ['ok' => false, 'error' => 'Cliente no encontrado', 'status' => 401];
        }

        if ($email !== '') {
            $destinatarios = array_values(array_filter(array_map('trim', explode(';', $email))));
        } elseif (! empty($cliente->email)) {
            $destinatarios = [$cliente->email];
        } else {
            return ['ok' => false, 'error' => 'El cliente no tiene correo electrónico registrado', 'status' => 401];
        }

        if (empty($destinatarios)) {
            return ['ok' => false, 'error' => 'El cliente no tiene correo electrónico registrado', 'status' => 401];
        }

        $toEmail = implode(', ', $destinatarios);

        $consts = Configuracion::valores(['MAIN_MAIL_EMAIL_FROM']);
        $from = $consts['MAIN_MAIL_EMAIL_FROM'] ?? '';

        if ($from === '' || $from === null) {
            return ['ok' => false, 'error' => 'No hay remitente configurado en Dolibarr (MAIN_MAIL_EMAIL_FROM)', 'status' => 401];
        }

        $mensaje = (new Email())
            ->from($from)
            ->to(...$destinatarios)
            ->subject($asunto)
            ->html($cuerpo);

        $adjuntoPdf = false;

        if ($enviarPdf && $idFactura > 0) {
            $rutaPdf = $this->rutaPdfFactura($idFactura);

            if ($rutaPdf !== null) {
                $mensaje->attachFromPath($rutaPdf);
                $adjuntoPdf = true;
            }
        }

        try {
            $this->transport()->send($mensaje);
        } catch (TransportExceptionInterface $e) {
            return ['ok' => false, 'error' => 'Error enviando correo: '.$e->getMessage(), 'status' => 401];
        }

        return [
            'ok' => true,
            'to' => $toEmail,
            'cliente' => $cliente->nom,
            'adjunto_pdf' => $adjuntoPdf,
        ];
    }

    /**
     * Ruta absoluta del PDF ya generado de la factura, o null si no existe.
     * No lo regenera (eso requiere Dolibarr cargado); si aun no se genero
     * desde la validacion de la factura, simplemente no se adjunta.
     */
    private function rutaPdfFactura(int $idFactura): ?string
    {
        $factura = Factura::query()
            ->where('rowid', $idFactura)
            ->select(['rowid', 'ref'])
            ->first();

        if (! $factura || empty($factura->ref)) {
            return null;
        }

        $ref = $this->sanitizarNombreArchivo($factura->ref);
        $rutaRelativa = "compta/facture/{$ref}/{$ref}.pdf";

        if (! Storage::disk('dolibarr_documents')->exists($rutaRelativa)) {
            return null;
        }

        return Storage::disk('dolibarr_documents')->path($rutaRelativa);
    }

    private function sanitizarNombreArchivo(string $nombre): string
    {
        return preg_replace('/[\/:*?"<>|]/', '_', $nombre) ?? $nombre;
    }

    /**
     * Transporte SMTP armado con las const de Dolibarr (MAIN_MAIL_SENDMODE,
     * MAIN_MAIL_SMTP_SERVER/PORT, MAIN_MAIL_SMTPS_ID/PW, MAIN_MAIL_EMAIL_TLS,
     * MAIN_MAIL_EMAIL_STARTTLS). MAIN_MAIL_SENDMODE=mail (o vacio) usa
     * sendmail/mail() nativo; cualquier otro valor (smtps, swiftmailer) usa
     * SMTP con los datos de conexion.
     */
    private function transport(): TransportInterface
    {
        $consts = Configuracion::valores([
            'MAIN_MAIL_SENDMODE',
            'MAIN_MAIL_SMTP_SERVER',
            'MAIN_MAIL_SMTP_PORT',
            'MAIN_MAIL_SMTPS_ID',
            'MAIN_MAIL_SMTPS_PW',
            'MAIN_MAIL_EMAIL_TLS',
            'MAIN_MAIL_EMAIL_STARTTLS',
        ]);

        $sendmode = $consts['MAIN_MAIL_SENDMODE'] ?? 'mail';

        if ($sendmode === 'mail' || $sendmode === '') {
            return Transport::fromDsn('native://default');
        }

        $host = $consts['MAIN_MAIL_SMTP_SERVER'] ?? '';
        $port = $consts['MAIN_MAIL_SMTP_PORT'] ?? '25';
        $user = $consts['MAIN_MAIL_SMTPS_ID'] ?? '';
        $pass = $consts['MAIN_MAIL_SMTPS_PW'] ?? '';
        $tls = ($consts['MAIN_MAIL_EMAIL_TLS'] ?? '0') === '1';

        $scheme = $tls ? 'smtps' : 'smtp';
        $auth = $user !== '' ? rawurlencode($user).($pass !== '' ? ':'.rawurlencode($pass) : '').'@' : '';

        return Transport::fromDsn("{$scheme}://{$auth}{$host}:{$port}");
    }
}
