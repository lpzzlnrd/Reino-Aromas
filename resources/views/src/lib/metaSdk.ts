/**
 * Carga del JS SDK de Facebook y apertura del popup de Embedded Signup.
 *
 * El popup de vinculación NO es un redirect OAuth clásico: Meta exige el SDK
 * cargado en la página porque el `code` de FB.login() y los ids del negocio
 * (waba_id, etc.) llegan por caminos separados — el segundo por postMessage,
 * ver useMetaAccounts.ts. Sin el SDK cargado, FB.login() no existe.
 *
 * El SDK NO se carga al importar este módulo: solo la primera vez que algún
 * canal intenta vincularse. Así Ajustes > Cuentas no dispara una carga externa
 * de facebook.net en cada visita si el usuario no toca ningún botón.
 */

declare global {
    interface Window {
        FB?: {
            init: (params: Record<string, unknown>) => void
            login: (
                callback: (response: FacebookLoginResponse) => void,
                params: Record<string, unknown>,
            ) => void
        }
        fbAsyncInit?: () => void
    }
}

export type FacebookLoginResponse = {
    authResponse: { code?: string } | null
    status: 'connected' | 'not_authorized' | 'unknown'
}

// El SDK exige Graph API v25.0+ para configuraciones de Embedded Signup v4.
// META_GRAPH_API_VERSION del backend sigue en v21.0 (correcta para el resto
// de la API) y no debe subirse solo por esto.
const SDK_GRAPH_VERSION = 'v25.0'

let sdkLoadPromise: Promise<void> | null = null

/**
 * Inyecta el <script> del SDK y resuelve cuando `window.FB` queda listo.
 * Llamadas repetidas reutilizan la misma promesa: solo se carga una vez.
 */
function loadSdk(appId: string): Promise<void> {
    if (sdkLoadPromise !== null) return sdkLoadPromise

    sdkLoadPromise = new Promise((resolve) => {
        window.fbAsyncInit = () => {
            window.FB?.init({
                appId,
                autoLogAppEvents: true,
                xfbml: false,
                version: SDK_GRAPH_VERSION,
            })
            resolve()
        }

        const script = document.createElement('script')
        script.src = 'https://connect.facebook.net/es_LA/sdk.js'
        script.async = true
        script.defer = true
        document.body.appendChild(script)
    })

    return sdkLoadPromise
}

/**
 * Abre el popup de Embedded Signup para un config_id concreto y devuelve el
 * `code` de FB.login() en cuanto llega.
 *
 * override_default_response_type es obligatorio: sin él el SDK devuelve un
 * access_token de cliente en vez del code que el backend necesita para
 * canjear (el intercambio exige el app_secret, que nunca sale al navegador).
 *
 * El code vive 30 segundos — quien reciba esta promesa debe mandarlo al
 * backend de inmediato, no encolarlo ni esperar al postMessage con los ids.
 */
export function abrirPopupSignup(appId: string, configId: string): Promise<string> {
    return loadSdk(appId).then(
        () =>
            new Promise<string>((resolve, reject) => {
                if (!window.FB) {
                    reject(new Error('El SDK de Facebook no cargó.'))
                    return
                }

                window.FB.login(
                    (response: FacebookLoginResponse) => {
                        const code = response.authResponse?.code
                        if (response.status !== 'connected' || !code) {
                            reject(new Error('Vinculación cancelada o rechazada.'))
                            return
                        }
                        resolve(code)
                    },
                    {
                        config_id: configId,
                        response_type: 'code',
                        override_default_response_type: true,
                    },
                )
            }),
    )
}

/** Datos del negocio que Meta manda por postMessage tras el Embedded Signup. */
export type SignupSessionData = {
    waba_id?: string
    phone_number_id?: string
    business_id?: string
}

/**
 * Escucha el postMessage de Meta con los ids del negocio vinculado.
 *
 * Se resuelve al primer mensaje válido y se desconecta el listener. Si nadie
 * llega en `timeoutMs`, resuelve null: el canje del code (vía el `code`) ya
 * puede haberse completado igual, solo faltan los ids específicos.
 *
 * El origin se valida contra facebook.com: cualquier iframe de la página
 * puede hacer postMessage, y sin este filtro un script ajeno podría inyectar
 * ids falsos del negocio.
 */
export function escucharSignupIds(timeoutMs = 15000): Promise<SignupSessionData | null> {
    return new Promise((resolve) => {
        let resuelto = false

        const limpiar = () => {
            window.removeEventListener('message', onMessage)
            clearTimeout(timer)
        }

        const onMessage = (event: MessageEvent) => {
            if (!/\.facebook\.com$/.test(new URL(event.origin).hostname) && event.origin !== 'https://www.facebook.com') {
                return
            }

            try {
                const data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
                if (data?.type !== 'WA_EMBEDDED_SIGNUP' && data?.type !== 'FB_EMBEDDED_SIGNUP') return

                resuelto = true
                limpiar()
                resolve(data.data ?? null)
            } catch {
                // Mensajes que no son JSON del signup se ignoran.
            }
        }

        const timer = setTimeout(() => {
            if (resuelto) return
            limpiar()
            resolve(null)
        }, timeoutMs)

        window.addEventListener('message', onMessage)
    })
}
