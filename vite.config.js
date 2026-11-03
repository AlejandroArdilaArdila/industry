import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/panel/layout.js',
                'resources/js/panel/dashboard.js',
                'resources/js/panel/trm-widget.js',
                'resources/js/panel/configuracion.js',
                'resources/js/societe/terceros-grid.js',
                'resources/js/usuarios/usuarios-grid.js',
                'resources/js/productos/productos-grid.js',
                'resources/js/categorias/categorias-grid.js',
                'resources/js/solicitudes/solicitudes-grid.js',
                'resources/js/facturas/facturas-grid.js',
                'resources/js/facturas/facturas-proveedores-grid.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
