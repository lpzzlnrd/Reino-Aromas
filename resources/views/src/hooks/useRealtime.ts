import { onBeforeUnmount, ref } from 'vue'
import { getEcho, realtimeConfigured } from '@/lib/echo'

/**
 * Suscripciones a canales privados, atadas al ciclo de vida del componente.
 *
 * El problema que resuelve: si un componente hace
 * `echo.private('x').listen(...)` y no se da de baja al desmontarse, el callback
 * sigue vivo. Al navegar entre chats se acumularían suscripciones y el mismo
 * mensaje se pintaría varias veces.
 *
 * Aquí se registra a qué canales se entró y se sale de todos en
 * onBeforeUnmount, sin que cada vista tenga que acordarse.
 */

export type RealtimeHandler = (payload: any) => void

export function useRealtime() {
    /** Canales a los que este componente está suscrito. */
    const suscritos = new Set<string>()

    /**
     * true cuando el socket está conectado.
     *
     * Las vistas lo usan para avisar de que están "en vivo" o de que hay que
     * refrescar a mano. Sin esto el agente no sabe si lo que ve está al día.
     */
    const conectado = ref(false)

    const disponible = realtimeConfigured()

    /**
     * Escucha varios eventos de un canal privado.
     *
     * @param canal  Nombre sin el prefijo 'private-' (lo añade Echo).
     * @param eventos Mapa de nombre de evento → handler. Los nombres son los
     *                cortos que declara broadcastAs() en PHP ('message.created'),
     *                no el FQCN de la clase.
     */
    const escuchar = (canal: string, eventos: Record<string, RealtimeHandler>): void => {
        const echo = getEcho()
        if (echo === null) return

        const channel = echo.private(canal)
        suscritos.add(canal)

        for (const [evento, handler] of Object.entries(eventos)) {
            channel.listen(`.${evento}`, handler)
        }

        // El '.' del prefijo es obligatorio: sin él Echo asume el namespace de
        // Laravel ('App\\Events\\') y nunca encuentra el evento.

        conectado.value = true
    }

    /** Deja un canal concreto. Útil al cambiar de chat sin desmontar la vista. */
    const dejar = (canal: string): void => {
        const echo = getEcho()
        if (echo === null) return

        echo.leave(canal)
        suscritos.delete(canal)
    }

    const dejarTodos = (): void => {
        const echo = getEcho()
        if (echo === null) return

        for (const canal of suscritos) {
            echo.leave(canal)
        }

        suscritos.clear()
        conectado.value = false
    }

    onBeforeUnmount(dejarTodos)

    return {
        disponible,
        conectado,
        escuchar,
        dejar,
        dejarTodos,
    }
}
