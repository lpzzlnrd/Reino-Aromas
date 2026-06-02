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
            // Punto de entrada único: el main.ts del Vue arranca toda la SPA.
            input: ['resources/views/src/main.ts'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            // Permite imports con @ dentro del código Vue (ej: @/hooks/caseStatus)
            '@': fileURLToPath(new URL('./resources/views/src', import.meta.url)),
        },
    },

    server: {
        // En Docker debe escuchar en todas las interfaces para ser alcanzable
        // desde el contenedor de Laravel vía la red interna de Compose.
        host: process.env.VITE_HOST || 'localhost',
        port: parseInt(process.env.VITE_PORT || '5173'),

        // Evita que Vite rechace requests que vienen del contenedor app (CORS interno)
        cors: true,

        watch: {
            // Las vistas compiladas de Laravel no deben disparar recargas
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
})
