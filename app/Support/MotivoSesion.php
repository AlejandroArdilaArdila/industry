<?php

namespace App\Support;

class MotivoSesion
{
    /** Texto para quien llega al login porque su sesión ya no es válida. */
    public static function mensaje(): string
    {
        $min = (int) config('session.lifetime', 120);
        $tiempo = $min >= 60 && $min % 60 === 0
            ? ($min / 60).($min === 60 ? ' hora' : ' horas')
            : $min.' minutos';

        return "Tu sesión se cerró por seguridad: pasaron más de {$tiempo} sin actividad, o se cerró desde otro lugar. Inicia sesión de nuevo para continuar.";
    }
}
