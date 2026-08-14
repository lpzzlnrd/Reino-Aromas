/// <reference types="vite/client" />

/**
 * Variables de entorno expuestas al frontend.
 *
 * Solo las VITE_* llegan al navegador: Vite las inyecta en tiempo de build. Sin
 * declararlas aquí, `import.meta.env.VITE_REVERB_HOST` es `any` y un typo pasa
 * el type-check sin avisar.
 */
interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string
    readonly VITE_APP_URL?: string

    /** Credenciales públicas de Reverb. Vacías = sin tiempo real. */
    readonly VITE_REVERB_APP_KEY?: string
    readonly VITE_REVERB_HOST?: string
    readonly VITE_REVERB_PORT?: string
    /** 'http' en local, 'https' en producción (fuerza wss). */
    readonly VITE_REVERB_SCHEME?: string
}

interface ImportMeta {
    readonly env: ImportMetaEnv
}
