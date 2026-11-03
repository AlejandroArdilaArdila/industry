<?php

namespace App\Services\Carrusel;

use App\Models\PosImgCp;

class CarruselService
{
    /**
     * Carpeta fisica donde custom/integrospos/admin/carruselPos.php (legacy
     * Dolibarr) guarda las imagenes del carrusel del Price Checker. Este
     * Laravel no sube/guarda nada: solo lee lo que ya existe ahi.
     */
    private const CARPETA_RELATIVA = 'htdocs/custom/integrospos/frontend/img/carrouselPriceChecker';

    /**
     * Imagenes vigentes hoy (date_start <= hoy <= date_end, active = 1), en
     * base64 para quien las solicite. Replica el SELECT que usa priceChecker.php
     * / cp.php en el terminal del Price Checker. Siempre consulta la base de
     * datos y el disco en el momento, sin cache: la subida/edicion/borrado la
     * hace el panel legacy de Dolibarr, fuera de este Laravel.
     */
    public function obtenerActivas(): array
    {
        $registros = PosImgCp::query()
            ->whereDate('date_start', '<=', now())
            ->whereDate('date_end', '>=', now())
            ->where('active', 1)
            ->orderByDesc('rowid')
            ->get();

        $carpeta = $this->carpetaImagenes();

        $imagenes = $registros
            ->map(function (PosImgCp $registro) use ($carpeta) {
                $ruta = $carpeta.DIRECTORY_SEPARATOR.$registro->filename;

                if (!is_file($ruta)) {
                    return null;
                }

                $mime = match (true) {
                    str_ends_with($registro->filename, '.webp') => 'image/webp',
                    str_ends_with($registro->filename, '.png') => 'image/png',
                    default => 'image/jpeg',
                };

                return [
                    'rowid' => (int) $registro->rowid,
                    'label' => $registro->label,
                    'date_start' => (string) $registro->date_start,
                    'date_end' => (string) $registro->date_end,
                    'imagen_base64' => 'data:'.$mime.';base64,'.base64_encode(file_get_contents($ruta)),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'total' => count($imagenes),
            'imagenes' => $imagenes,
        ];
    }

    /**
     * DOL_DOCUMENTS_ROOT apunta a ".../documents/"; el htdocs de Dolibarr es
     * el directorio hermano de ese "documents" (mismo raiz del sitio).
     */
    private function carpetaImagenes(): string
    {
        $documentsRoot = rtrim((string) config('filesystems.disks.dolibarr_documents.root'), '/');
        $raizSitio = dirname($documentsRoot);

        return $raizSitio.DIRECTORY_SEPARATOR.self::CARPETA_RELATIVA;
    }
}
