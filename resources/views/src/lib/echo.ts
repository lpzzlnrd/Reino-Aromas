import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * Cliente de WebSockets contra Laravel Reverb.
 *
 * Reverb habla el protocolo de Pusher, así que el cliente es pusher-js aunque
 * el servidor no sea Pusher. De ahí que las variables se llamen VITE_REVERB_*
 * pero el broadcaster sea 'reverb'.
 *
 * La conexión NO se abre al importar este módulo: se crea la primera vez que
 * alguien llama a getEcho(). Así las vistas que no escuchan nada (Clientes,
 * Reportes, Ajustes) no abren un socket que no van a usar.
 */

type EchoClient = Echo<'reverb'>

/**
 * Lo que /broadcasting/auth devuelve y pusher-js espera.
 *
 * Es el tipo ChannelAuthorizationData de pusher-js. Se declara aquí en vez de
 * importarlo de sus rutas internas (`pusher-js/types/src/core/auth/options`),
 * que no forman parte de su API pública y cambiarían sin avisar.
 */
type AuthData = {
    auth: string
    channel_data?: string
    shared_secret?: string
}

let echo: EchoClient | null = null

/** true si el build trae credenciales de Reverb configuradas. */
export const realtimeConfigured = (): boolean =>
    Boolean(import.meta.env.VITE_REVERB_APP_KEY)

/**
 * Instancia compartida de Echo, o null si el tiempo real no está configurado.
 *
 * Devolver null en vez de lanzar es deliberado: el CRM tiene que seguir
 * funcionando sin Reverb levantado. En el VPS las credenciales pueden no estar
 * todavía, y en ese caso las vistas simplemente no se suscriben a nada — no se
 * rompe ninguna pantalla.
 */
export function getEcho(): EchoClient | null {
    if (!realtimeConfigured()) return null
    if (echo !== null) return echo

    // pusher-js se cuelga en window porque laravel-echo lo busca ahí.
    ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],

        /*
         * Los canales son privados: cada suscripción pasa por
         * POST /broadcasting/auth, que va detrás del middleware web.
         *
         * Se usa fetch con credentials en vez del axios de la app porque esa
         * ruta NO está bajo /api (el baseURL de axios), y necesita el token
         * CSRF del meta tag igual que hace useAuth().logout().
         */
        authorizer: (channel: { name: string }) => ({
            authorize: (
                socketId: string,
                // La firma la fija laravel-echo 2.x: un Error o null, y los
                // datos de autorización o null.
                callback: (error: Error | null, data: AuthData | null) => void,
            ) => {
                const token = document
                    .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                    ?.content ?? ''

                fetch('/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                })
                    .then((res) => {
                        // Un 403 acá significa que el canal rechazó la
                        // suscripción: sesión caída o conversación inexistente.
                        if (!res.ok) throw new Error(`No autorizado en ${channel.name} (${res.status})`)
                        return res.json() as Promise<AuthData>
                    })
                    .then((data) => callback(null, data))
                    .catch((error: Error) => callback(error, null))
            },
        }),
    })

    return echo
}

/**
 * Cierra la conexión y olvida la instancia.
 *
 * Se llama al cerrar sesión: sin esto el socket sigue abierto y autenticado
 * como el usuario anterior.
 */
export function disconnectEcho(): void {
    if (echo === null) return

    echo.disconnect()
    echo = null
}
