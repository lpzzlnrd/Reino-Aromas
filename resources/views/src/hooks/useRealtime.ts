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

    /** Para no volver a enganchar los listeners del socket en cada canal. */
    let estadoEnganchado = false

    /** Loaders que hay que correr cuando el socket vuelve. */
    const alReconectar = new Set<() => void>()

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

        // Entrar dos veces al mismo canal registra los handlers DOS veces, y
        // cada evento se procesa doble. Pasa de verdad: la bandeja y el tablero
        // escuchan los dos el canal 'tickets', y con ambas vistas montadas un
        // ticket movido se aplicaba dos veces.
        //
        // El Set servía solo para darse de baja; ahora también corta la entrada
        // repetida, que es lo que este hook decía prevenir.
        if (suscritos.has(canal)) return

        const channel = echo.private(canal)
        suscritos.add(canal)

        for (const [evento, handler] of Object.entries(eventos)) {
            channel.listen(`.${evento}`, handler)
        }

        // El '.' del prefijo es obligatorio: sin él Echo asume el namespace de
        // Laravel ('App\\Events\\') y nunca encuentra el evento.

        escucharEstadoDeConexion(echo)
    }

    /**
     * Refleja el estado real del socket en `conectado`.
     *
     * Antes se ponía en true al suscribirse, sin mirar el socket: con Reverb
     * caído la UI diría "en vivo" igual, que es justo cuando el agente necesita
     * saber que tiene que refrescar a mano.
     */
    const escucharEstadoDeConexion = (echo: NonNullable<ReturnType<typeof getEcho>>): void => {
        if (estadoEnganchado) return

        // pusher-js expone el conector; puede no estar en entornos de prueba.
        const conector = (echo as unknown as {
            connector?: { pusher?: { connection?: { bind: (e: string, cb: () => void) => void } } }
        }).connector

        const conexion = conector?.pusher?.connection

        if (conexion === undefined) {
            // Sin acceso al socket se asume conectado: es lo que había antes y
            // es mejor que marcar "sin conexión" por no poder comprobarlo.
            conectado.value = true

            return
        }

        conexion.bind('connected', () => {
            const seHabiaCaido = conectado.value === false
            conectado.value = true

            // Los eventos emitidos mientras el socket estuvo caído se perdieron
            // para siempre: Reverb no los reencola. Sin este aviso la vista se
            // queda con datos viejos y la insignia en verde diciendo que está
            // al día.
            if (seHabiaCaido) alReconectar.forEach((fn) => fn())
        })

        conexion.bind('disconnected', () => { conectado.value = false })
        conexion.bind('unavailable', () => { conectado.value = false })
        conexion.bind('failed', () => { conectado.value = false })

        estadoEnganchado = true
    }

    /**
     * Registra qué recargar cuando el socket vuelve.
     *
     * La vista pasa su propio loader (loadChats, loadBoard…): este hook no sabe
     * qué datos quedaron rancios.
     */
    const alVolverLaConexion = (fn: () => void): void => {
        alReconectar.add(fn)
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
        alVolverLaConexion,
    }
}
