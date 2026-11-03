<?php

namespace App\Services\Mensajes;

use App\Models\Mensaje;
use Illuminate\Http\Request;

class MensajeService
{
    /**
     * Mensajes del chat por usuario (como emisor, receptor, o difusion
     * fk_user_to = 0) y, opcionalmente, por fecha (sin hora). Si 'fecha'
     * no viene, retorna todos.
     */
    public function getMensajes(Request $request): array
    {
        $user = (int) $request->input('user', 0);
        $fecha = trim((string) $request->input('fecha', ''));

        if ($user <= 0) {
            abort(401, "El campo 'user' es obligatorio.");
        }

        $mensajes = Mensaje::query()
            ->where(function ($q) use ($user) {
                $q->where('fk_user_from', $user)
                    ->orWhere('fk_user_to', $user)
                    ->orWhere('fk_user_to', 0);
            })
            ->when($fecha !== '', function ($q) use ($fecha) {
                $q->whereDate('fecha', $fecha);
            })
            ->with([
                'emisor' => fn ($q) => $q->select('rowid', 'firstname', 'lastname'),
                'receptor' => fn ($q) => $q->select('rowid', 'firstname', 'lastname'),
            ])
            ->orderBy('fecha')
            ->get();

        return $mensajes->map(fn ($mensaje) => [
            'rowid' => (int) $mensaje->rowid,
            'fk_user_from' => (int) $mensaje->fk_user_from,
            'fk_user_to' => (int) $mensaje->fk_user_to,
            'mensaje' => $mensaje->mensaje,
            'tipo' => $mensaje->tipo,
            'fecha' => $mensaje->fecha,
            'leido' => (bool) $mensaje->leido,
            'nombre_from' => trim(($mensaje->emisor?->firstname ?? '').' '.($mensaje->emisor?->lastname ?? '')),
            'nombre_to' => trim(($mensaje->receptor?->firstname ?? '').' '.($mensaje->receptor?->lastname ?? '')),
        ])->values()->all();
    }
}
