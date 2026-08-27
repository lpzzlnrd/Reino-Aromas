import { onBeforeUnmount, watch, type Ref } from 'vue'

/**
 * Comportamiento de teclado y foco que todo modal necesita y ninguno tenía.
 *
 * Los cuatro modales del CRM (ficha de cliente, alta de usuario, editor de
 * plantillas, confirmación de borrado) se cerraban solo con click en el
 * backdrop. Eso deja tres problemas:
 *
 *   1. Escape no cerraba. Es el gesto que todo el mundo intenta primero.
 *   2. El fondo seguía scrolleando: en móvil el modal se queda quieto y la
 *      página se mueve por detrás.
 *   3. El foco quedaba en la página de atrás. Con teclado se podía tabular
 *      hacia los botones ocultos detrás del backdrop y activarlos a ciegas.
 *
 * Se usa pasando el ref que controla la visibilidad:
 *
 *   const abierto = ref(false)
 *   useModal(abierto, () => cerrar())
 *
 * El scroll-lock cuenta cuántos modales hay abiertos (`abiertos`) en vez de
 * poner y quitar el overflow directamente: con dos modales apilados, cerrar el
 * de arriba devolvería el scroll al body mientras el de abajo sigue abierto.
 */

/** Modales abiertos ahora mismo, para no soltar el scroll antes de tiempo. */
let abiertos = 0

/** Valor original del overflow, para restaurarlo tal como estaba. */
let overflowPrevio = ''

function bloquearScroll(): void {
    if (abiertos === 0) {
        overflowPrevio = document.body.style.overflow
        document.body.style.overflow = 'hidden'
    }

    abiertos += 1
}

function liberarScroll(): void {
    abiertos = Math.max(0, abiertos - 1)

    if (abiertos === 0) {
        document.body.style.overflow = overflowPrevio
    }
}

/** Elementos que pueden recibir foco dentro del modal. */
const FOCUSABLES = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',')

export type OpcionesModal = {
    /**
     * Contenedor del modal. Si se pasa, el foco entra al abrir y queda
     * atrapado dentro mientras esté abierto.
     */
    panel?: Ref<HTMLElement | null>

    /** false para que Escape no cierre (confirmaciones destructivas). */
    cerrarConEscape?: boolean
}

export function useModal(
    visible: Ref<unknown>,
    cerrar: () => void,
    opciones: OpcionesModal = {},
): void {
    const { panel, cerrarConEscape = true } = opciones

    /** Quién tenía el foco antes de abrir, para devolvérselo al cerrar. */
    let focoPrevio: HTMLElement | null = null

    const alPresionarTecla = (evento: KeyboardEvent): void => {
        if (evento.key === 'Escape' && cerrarConEscape) {
            evento.preventDefault()
            cerrar()

            return
        }

        if (evento.key !== 'Tab' || panel?.value == null) return

        const elementos = Array.from(
            panel.value.querySelectorAll<HTMLElement>(FOCUSABLES),
        ).filter((el) => el.offsetParent !== null)

        if (elementos.length === 0) return

        const primero = elementos[0]!
        const ultimo = elementos[elementos.length - 1]!
        const activo = document.activeElement

        // Tab circular: del último vuelve al primero y al revés. Sin esto el
        // foco se escapa a la página de atrás, que está tapada por el backdrop.
        if (evento.shiftKey && (activo === primero || !panel.value.contains(activo))) {
            evento.preventDefault()
            ultimo.focus()
        } else if (!evento.shiftKey && activo === ultimo) {
            evento.preventDefault()
            primero.focus()
        }
    }

    const abrir = (): void => {
        focoPrevio = document.activeElement as HTMLElement | null

        bloquearScroll()
        document.addEventListener('keydown', alPresionarTecla)

        // El panel no existe en el DOM hasta que Vue pinta el v-if.
        requestAnimationFrame(() => {
            if (panel?.value == null) return

            const primero = panel.value.querySelector<HTMLElement>(FOCUSABLES)

            // Si no hay nada enfocable, el propio panel recibe el foco para que
            // el lector de pantalla anuncie el diálogo.
            if (primero !== null) {
                primero.focus()
            } else {
                panel.value.setAttribute('tabindex', '-1')
                panel.value.focus()
            }
        })
    }

    const limpiar = (): void => {
        document.removeEventListener('keydown', alPresionarTecla)
        liberarScroll()

        // Devolver el foco a donde estaba: sin esto, cerrar un modal deja el
        // foco en el body y el siguiente Tab empieza desde el principio de la
        // página.
        focoPrevio?.focus()
        focoPrevio = null
    }

    watch(
        () => Boolean(visible.value),
        (estaAbierto, estabaAbierto) => {
            if (estaAbierto && !estabaAbierto) {
                abrir()
            } else if (!estaAbierto && estabaAbierto) {
                limpiar()
            }
        },
        { immediate: true },
    )

    // Un modal abierto cuya vista se desmonta (navegación) dejaría el body sin
    // scroll para siempre.
    onBeforeUnmount(() => {
        if (Boolean(visible.value)) limpiar()
    })
}
