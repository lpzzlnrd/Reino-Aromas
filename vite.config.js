import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

/**
 * Vite unificado: Laravel sirve el bundle de Vue a través de laravel-vite-plugin.
 *
 * Flujo:
 *  - dev:  Vite corre en :5173, Laravel en :8000. El Blade incluye el HMR script
 *          via @vite(). Los assets se sirven en caliente desde Vite.
 *  - prod: `npm run build` genera public/build/. Laravel resuelve los hashes
 *          automáticamente con el helper @vite().
 *
 * En Docker el servidor Vite escucha en 0.0.0.0 para que el contenedor de
 * Laravel pueda alcanzarlo. VITE_PORT se inyecta desde docker-compose.yml.
 */
export default defineConfig({
    plugins: [
        laravel({
            // Dos entradas:
            //  - main.ts: arranca toda la SPA de Vue (todo /app/*).
            //  - app.css: Tailwind para las vistas Blade puras (login), que no
            //    cargan el bundle Vue. Sin esta entrada, @vite('resources/css/app.css')
            //    revienta con "Unable to locate file in Vite manifest".
            input: ['resources/views/src/main.ts', 'resources/css/app.css'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/views/src', import.meta.url)),
        },
    },

    server: {
        host: process.env.VITE_HOST || 'localhost',
        port: parseInt(process.env.VITE_PORT || '5173'),
        cors: true,

        // HMR: el browser debe conectar a localhost, no a 0.0.0.0
        hmr: {
            host: 'localhost',
            port: parseInt(process.env.VITE_PORT || '5173'),
            clientPort: parseInt(process.env.VITE_PORT || '5173'),
        },

        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
})
