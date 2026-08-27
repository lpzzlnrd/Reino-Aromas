/**
 * Sonido de aviso para mensajes entrantes.
 *
 * Se sintetiza con la Web Audio API en vez de cargar un archivo. Razones:
 *
 * - No suma peso al bundle, que ya va en 1.59 MB sin code-splitting.
 * - No hay un `fetch` que pueda fallar justo cuando llega el mensaje: el tono
 *   suena aunque el disco del VPS esté lleno o el CDN caído.
 * - Es código propio, sin licencia de terceros que arrastrar (la alternativa
 *   era un .mp3 de un banco de sonidos y su atribución).
 *
 * El timbre son dos senoidales en intervalo de quinta justa (E6 → B6) con
 * caída exponencial, que es lo que hace que se lea como "campanita" y no como
 * un beep de error. Se eligió agudo y corto a propósito: tiene que cortar por
 * encima del ruido de una oficina sin ser el chirrido de una alarma.
 */

/** Preferencia del agente, compartida por toda la app. */
const CLAVE_ALMACENAMIENTO = 'reino:sonido-notificacion'

/**
 * Mínimo entre dos avisos, en milisegundos.
 *
 * Sin esto, una ráfaga de mensajes (un cliente que manda cinco líneas seguidas,
 * o el `history` de coexistencia de WhatsApp volcando el historial del teléfono)
 * suena como una ametralladora. El primero avisa; los siguientes ya no aportan.
 */
const MS_ENTRE_AVISOS = 2_000

/*
 * El AudioContext es único para toda la sesión.
 *
 * Los navegadores limitan cuántos se pueden crear (Chrome corta cerca de 6) y
 * crear uno por mensaje los agota en una jornada de trabajo. Se crea perezoso:
 * antes del primer gesto del usuario nacería 'suspended' y sin utilidad.
 */
let contexto: AudioContext | null = null

let ultimoAviso = 0

/** Lee la preferencia. Sin valor guardado el sonido va ACTIVADO. */
const leerPreferencia = (): boolean => {
    try {
        return window.localStorage.getItem(CLAVE_ALMACENAMIENTO) !== 'off'
    } catch {
        // Safari en navegación privada lanza al tocar localStorage. Que el
        // sonido no funcione no puede tumbar la bandeja.
        return true
    }
}

const obtenerContexto = (): AudioContext | null => {
    if (contexto !== null) return contexto

    // Safari < 14.1 sólo trae el prefijado.
    const Constructor =
        window.AudioContext ??
        (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext

    if (Constructor === undefined) return null

    try {
        contexto = new Constructor()
    } catch {
        return null
    }

    return contexto
}

/**
 * Toca una senoidal con envolvente de ataque/caída.
 *
 * La rampa de subida de 8 ms existe para evitar el 'click' que produce
 * arrancar una onda desde amplitud 0 de golpe.
 */
const tocarTono = (
    ctx: AudioContext,
    frecuencia: number,
    inicio: number,
    duracion: number,
    volumen: number,
): void => {
    const oscilador = ctx.createOscillator()
    const ganancia = ctx.createGain()

    oscilador.type = 'sine'
    oscilador.frequency.value = frecuencia

    ganancia.gain.setValueAtTime(0, inicio)
    ganancia.gain.linearRampToValueAtTime(volumen, inicio + 0.008)
    // exponentialRampToValueAtTime no acepta 0 como destino: de ahí el 0.0001.
    ganancia.gain.exponentialRampToValueAtTime(0.0001, inicio + duracion)

    oscilador.connect(ganancia)
    ganancia.connect(ctx.destination)

    oscilador.start(inicio)
    oscilador.stop(inicio + duracion)
}

export function useNotificationSound() {
    /**
     * Suena el aviso, si el agente lo tiene activado y no acaba de sonar.
     *
     * Nunca lanza: se llama desde el handler del WebSocket, y una excepción ahí
     * abortaría el resto del procesamiento del mensaje (la burbuja, el
     * reordenamiento de la bandeja). El sonido es lo prescindible de los tres.
     */
    const reproducir = (): void => {
        if (!leerPreferencia()) return

        const ahora = performance.now()
        if (ahora - ultimoAviso < MS_ENTRE_AVISOS) return

        const ctx = obtenerContexto()
        if (ctx === null) return

        try {
            /*
             * El contexto nace 'suspended' hasta que el usuario interactúa con
             * la página (política de autoplay). resume() sólo funciona si ya
             * hubo un gesto; si no, la promesa se rechaza y no pasa nada más.
             * En la práctica el agente ya hizo clic para entrar a la bandeja.
             */
            if (ctx.state === 'suspended') void ctx.resume()

            const t = ctx.currentTime

            // E6 y B6: quinta justa. El segundo tono entra a los 110 ms, ya
            // cayendo el primero, así que se solapan y suena a una sola
            // campanita de dos notas en vez de a dos pitidos.
            tocarTono(ctx, 1318.5, t, 0.18, 0.14)
            tocarTono(ctx, 1975.5, t + 0.11, 0.22, 0.10)

            ultimoAviso = ahora
        } catch {
            // Contexto cerrado por el navegador tras mucha inactividad. Se
            // olvida para que el siguiente aviso cree uno nuevo.
            contexto = null
        }
    }

    /** true si el agente tiene el aviso sonoro activado. */
    const activado = (): boolean => leerPreferencia()

    /**
     * Guarda la preferencia y, al activar, toca una muestra.
     *
     * La muestra no es un adorno: activar en un dispositivo cuyo navegador
     * bloquea el audio dejaría al agente creyendo que va a oír los mensajes.
     * Al oírla sabe que funciona de verdad.
     */
    const alternar = (valor?: boolean): boolean => {
        const nuevo = valor ?? !leerPreferencia()

        try {
            window.localStorage.setItem(CLAVE_ALMACENAMIENTO, nuevo ? 'on' : 'off')
        } catch {
            // Sin persistencia el ajuste dura lo que la pestaña. Aceptable.
        }

        if (nuevo) {
            // Se salta el antirrebote: el agente acaba de pedir oírlo.
            ultimoAviso = 0
            reproducir()
        }

        return nuevo
    }

    return { reproducir, activado, alternar }
}
