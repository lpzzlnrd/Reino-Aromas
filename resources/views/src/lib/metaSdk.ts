/**
 * JS SDK de Facebook, cargado a demanda para el Embedded Signup.
 *
 * El popup de vinculación de Meta NO se puede hacer con una redirección normal:
 * necesita el SDK en la página, porque Meta devuelve los datos del negocio por
 * `postMessage` a la ventana que abrió el popup.
 *
 * La carga es lazy, igual que en lib/echo.ts: el script son ~200KB de un tercero
 * y solo hace falta en una vista. Traerlo en el bundle principal lo pagaría todo
 * el CRM para que lo use el 1% de las visitas.
 */

/** Lo que el SDK cuelga de window. */
declare global {
    interface Window {
        FB?: {
            init: (options: Record<string, unknown>) => void
            login: (
                callback: (response: FbLoginResponse) => void,
                options: Record<string, unknown>,
            ) => void
        }
        fbAsyncInit?: () => void
    }
}

export type FbLoginResponse = {
    authResponse?: {
        /**
         * El código intercambiable. **Vive 30 segundos**, así que hay que
         * mandarlo al backend de inmediato.
         */
        code?: string
    } | null
    status?: string
}

/** Datos del negocio que Meta manda por postMessage al completarse el flujo. */
export type EmbeddedSignupData = {
    /** Solo WhatsApp: la WhatsApp Business Account. */
    waba_id?: string
    /** Solo WhatsApp: el número desde el que se envía. */
    phone_number_id?: string
    /** Instagram y Facebook: id de la cuenta o de la página. */
    business_id?: string
    /** En qué pantalla abandonó, si no completó. */
    current_step?: string
    error_message?: string
}

const SDK_SCRIPT_ID = 'facebook-jssdk'

/**
 * Versión de la Graph API para el SDK.
 *
 * Embedded Signup v4 exige v25.0 o superior, así que este default NO puede
 * seguir a `META_GRAPH_API_VERSION` (que es v21.0 y sirve para el resto de la
 * API). El backend manda la versión buena en /api/meta/accounts.
 */
const VERSION_MINIMA = 'v25.0'

let cargando: Promise<void> | null = null
let inicializadoCon: string | null = null

/**
 * Carga el SDK una sola vez y lo inicializa con el app_id.
 *
 * Devuelve la misma promesa en llamadas concurrentes: dos clics rápidos en dos
 * botones distintos no deben inyectar el script dos veces.
 */
export function loadMetaSdk(appId: string, graphVersion?: string): Promise<void> {
    // Ya inicializado con el mismo app_id: nada que hacer.
    if (inicializadoCon === appId && window.FB) {
        return Promise.resolve()
    }

    if (cargando !== null) {
        return cargando
    }

    // El SDK solo acepta v25.0+ para Embedded Signup v4. Si el backend manda una
    // anterior (v21.0, que es la del resto de la API), se sube al mínimo.
    const version = versionValida(graphVersion) ? graphVersion! : VERSION_MINIMA

    cargando = new Promise<void>((resolve, reject) => {
        const inicializar = () => {
            if (!window.FB) {
                reject(new Error('El SDK de Meta cargó pero no expuso window.FB'))
                return
            }

            window.FB.init({
                appId,
                // cookie:false — no hace falta la sesión de Facebook en el
                // servidor: el token lo intercambia el backend con el code.
                cookie: false,
                xfbml: false,
                version,
            })

            inicializadoCon = appId
            resolve()
        }

        const existente = document.getElementById(SDK_SCRIPT_ID)

        if (existente !== null) {
            // El script ya está en el DOM (navegación entre vistas): puede que
            // window.FB todavía no exista si aún está descargando.
            window.FB ? inicializar() : (window.fbAsyncInit = inicializar)
            return
        }

        const script = document.createElement('script')
        script.id = SDK_SCRIPT_ID
        script.src = 'https://connect.facebook.net/en_US/sdk.js'
        script.async = true
        script.defer = true
        script.crossOrigin = 'anonymous'

        script.onload = inicializar
        script.onerror = () => {
            // Se limpia para que un reintento del usuario vuelva a probar en
            // vez de quedarse pegado con la promesa rechazada.
            cargando = null
            script.remove()
            reject(new Error('No se pudo cargar el SDK de Meta. Revisá la conexión o un bloqueador de anuncios.'))
        }

        document.head.appendChild(script)
    })

    return cargando
}

/** Una versión con forma vNN.N y >= 25. */
function versionValida(version?: string): boolean {
    if (!version) return false

    const match = /^v(\d+)\.(\d+)$/.exec(version)

    return match !== null && Number(match[1]) >= 25
}

/**
 * Abre el popup de Embedded Signup y resuelve con el code y los datos.
 *
 * Los dos llegan por caminos SEPARADOS y hay que esperar los dos:
 *   - el `code`, por el callback de FB.login()
 *   - los ids del negocio (waba_id, phone_number_id), por un postMessage
 *
 * Por eso no basta con el callback: sin el postMessage no se sabe qué número
 * quedó vinculado.
 */
export function launchEmbeddedSignup(configId: string): Promise<{
    code: string
    data: EmbeddedSignupData
}> {
    return new Promise((resolve, reject) => {
        if (!window.FB) {
            reject(new Error('El SDK de Meta no está inicializado.'))
            return
        }

        // Lo que llegue por postMessage antes de que responda el callback.
        let datos: EmbeddedSignupData = {}

        const onMessage = (event: MessageEvent) => {
            // Solo se aceptan mensajes de Facebook: cualquier iframe de la
            // página puede hacer postMessage, y esto trae ids del negocio.
            if (!/^https:\/\/(www\.)?facebook\.com$/.test(event.origin)) {
                return
            }

            try {
                const payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data

                if (payload?.type !== 'WA_EMBEDDED_SIGNUP') return

                if (payload.event === 'FINISH' || payload.data) {
                    datos = { ...datos, ...payload.data }
                }

                if (payload.event === 'CANCEL') {
                    datos.current_step = payload.data?.current_step
                }
            } catch {
                // Un mensaje que no es JSON no es nuestro: se ignora.
            }
        }

        window.addEventListener('message', onMessage)

        const limpiar = () => window.removeEventListener('message', onMessage)

        window.FB.login(
            (response: FbLoginResponse) => {
                limpiar()

                const code = response?.authResponse?.code

                if (!code) {
                    // Sin code: el usuario cerró el popup o lo canceló. No es un
                    // error del sistema, así que el mensaje lo dice así.
                    reject(new Error(
                        datos.error_message
                        ?? 'Vinculación cancelada. No se conectó ninguna cuenta.',
                    ))
                    return
                }

                resolve({ code, data: datos })
            },
            {
                config_id: configId,
                response_type: 'code',
                // Obligatorio: sin esto el SDK devuelve un access_token de
                // cliente en vez del code que el backend necesita.
                override_default_response_type: true,
                extras: { setup: {} },
            },
        )
    })
}
