/**
 * Avisos del sistema operativo para mensajes entrantes.
 *
 * El sonido solo sirve si el agente está delante del navegador; esto cubre el
 * caso real de la operación: la pestaña del CRM en segundo plano mientras se
 * trabaja en otra cosa. Usa la Notification API del navegador, así que no hay
 * servicio de push, ni claves VAPID, ni service worker que registrar.
 *
 * Limitación conocida y aceptada: sin service worker el aviso solo aparece
 * mientras la pestaña esté abierta (aunque no visible). Con el navegador
 * cerrado no llega nada. Para eso haría falta Web Push, que exige un endpoint
 * de suscripción en el backend — queda fuera de alcance.
 */

const CLAVE_ALMACENAMIENTO = 'reino:avisos-escritorio'

/**
 * Notificaciones vivas por conversación.
 *
 * Se reemplaza el aviso anterior del mismo chat en vez de apilar uno por
 * mensaje: cinco líneas seguidas del mismo cliente son un aviso, no cinco.
 * El `tag` de la API ya hace esto en Chrome y Firefox, pero Safari lo ignora,
 * de ahí el cierre explícito.
 */
const vivas = new Map<number, Notification>()

const soportado = (): boolean => typeof window !== 'undefined' && 'Notification' in window

/** Lee la preferencia. Por defecto DESACTIVADO: exige permiso del navegador. */
const leerPreferencia = (): boolean => {
    try {
        return window.localStorage.getItem(CLAVE_ALMACENAMIENTO) === 'on'
    } catch {
        return false
    }
}

export function useDesktopNotification() {
    /** 'granted' | 'denied' | 'default', o null si el navegador no soporta. */
    const permiso = (): NotificationPermission | null =>
        soportado() ? Notification.permission : null

    /** true si está activado por el agente Y permitido por el navegador. */
    const activado = (): boolean =>
        soportado() && leerPreferencia() && Notification.permission === 'granted'

    /**
     * Pide permiso al navegador y guarda la preferencia.
     *
     * Hay que llamarlo desde un gesto del usuario (clic en el interruptor):
     * Chrome ignora requestPermission() fuera de uno. Devuelve si quedó activo.
     */
    const solicitar = async (): Promise<boolean> => {
        if (!soportado()) return false

        // Un 'denied' previo no se puede revertir por código: el agente tiene
        // que habilitarlo en la configuración del navegador. Devolver false
        // permite que la UI se lo diga en vez de fallar en silencio.
        if (Notification.permission === 'denied') return false

        const resultado =
            Notification.permission === 'granted'
                ? 'granted'
                : await Notification.requestPermission()

        const ok = resultado === 'granted'

        try {
            window.localStorage.setItem(CLAVE_ALMACENAMIENTO, ok ? 'on' : 'off')
        } catch {
            // Sin persistencia dura lo que la pestaña.
        }

        return ok
    }

    /** Desactiva los avisos sin tocar el permiso del navegador. */
    const desactivar = (): void => {
        try {
            window.localStorage.setItem(CLAVE_ALMACENAMIENTO, 'off')
        } catch {
            // Igual que arriba.
        }
    }

    /**
     * Muestra el aviso de un mensaje entrante.
     *
     * @param conversacionId Para agrupar: un aviso por chat, no por mensaje.
     * @param titulo         Nombre del contacto.
     * @param cuerpo         Texto del mensaje (se recorta).
     * @param alHacerClic    Enfoca la pestaña y abre ese chat.
     */
    const mostrar = (
        conversacionId: number,
        titulo: string,
        cuerpo: string,
        alHacerClic?: () => void,
    ): void => {
        if (!activado()) return

        // Con la pestaña a la vista el aviso del sistema es ruido: el agente ya
        // está viendo llegar el mensaje. El sonido sí suena en los dos casos.
        if (document.visibilityState === 'visible') return

        try {
            vivas.get(conversacionId)?.close()

            const aviso = new Notification(titulo, {
                // El cuerpo se recorta: los sistemas lo truncan igual, y un
                // mensaje largo empuja el nombre fuera de la vista en Windows.
                body: cuerpo.length > 120 ? `${cuerpo.slice(0, 119)}…` : cuerpo,
                icon: '/favicon.ico',
                tag: `reino-conversacion-${conversacionId}`,
                // Sin esto Chrome reproduce SU sonido además del nuestro.
                silent: true,
            })

            vivas.set(conversacionId, aviso)

            aviso.onclick = () => {
                window.focus()
                aviso.close()
                alHacerClic?.()
            }

            aviso.onclose = () => { vivas.delete(conversacionId) }
        } catch {
            // El constructor lanza en algunos Android (exige service worker).
            // Sin aviso visual, pero el sonido y la bandeja siguen igual.
        }
    }

    return { soportado, permiso, activado, solicitar, desactivar, mostrar }
}
