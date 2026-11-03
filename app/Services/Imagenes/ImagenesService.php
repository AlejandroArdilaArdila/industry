<?php

namespace App\Services\Imagenes;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Storage;

class ImagenesService
{
    /**
     * Mapeo tipo -> constante de configuración (const), igual que el switch del legacy.
     */
    private const CAMPO_POR_TIPO = [
        1 => 'MAIN_INFO_POS_IMAGE_LOGO',
        2 => 'MAIN_INFO_POS_IMAGE_HOME',
        3 => 'MAIN_INFO_POS_IMAGE_OTHER',
    ];

    /**
     * Resuelve la ruta absoluta en disco de la imagen configurada para el tipo dado.
     * Réplica de ImagePathApi::getImagePath().
     */
    public function resolverRutaImagen(?int $tipo): string
    {
        if ($tipo === null) {
            abort(400, 'Parámetro "tipo" no especificado.');
        }

        $campo = self::CAMPO_POR_TIPO[$tipo] ?? null;

        if ($campo === null) {
            abort(400, 'Tipo no válido.');
        }

        $filename = Configuracion::valores([$campo])[$campo] ?? null;

        if (!$filename) {
            abort(404, "Archivo no configurado para el tipo: {$tipo}");
        }

        $rutaRelativa = 'integrospos/images/'.$filename;
        

        if (!Storage::disk('dolibarr_documents')->exists($rutaRelativa)) {
            abort(404, 'Archivo no encontrado: '.$rutaRelativa);
        }

        return Storage::disk('dolibarr_documents')->path($rutaRelativa);
    }
}
