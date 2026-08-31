import { onMounted, readonly, ref } from 'vue'

/**
 * Tema claro / oscuro del CRM.
 *
 * Tres estados, no dos: 'light', 'dark' y 'system'. El tercero no es un capricho
 * — es el que respeta que alguien tenga el sistema en oscuro de noche y en claro
 * de dia. Si solo hubiera dos, esa persona tendria que venir a cambiarlo a mano
 * dos veces al dia.
 *
 * El tema se aplica poniendo data-theme en <html>, y styles.css redefine ahi las
 * variables de color. Por eso NO hace falta tocar los componentes: las 382 clases
 * text-primary del CRM apuntan a --color-primary y cambian solas.
 *
 * La preferencia se guarda en localStorage. Es una comodidad por navegador, no
 * un dato del usuario: no viaja al servidor ni entre dispositivos.
 */

export type Tema = 'light' | 'dark' | 'system'

const CLAVE = 'reino-aromas:tema'

/** Estado compartido: todas las instancias del composable ven el mismo tema. */
const tema = ref<Tema>('system')

/** Lo que se esta pintando de verdad, ya resuelto 'system'. */
const efectivo = ref<'light' | 'dark'>('light')

/** Se registra una sola vez aunque el composable se use en varios componentes. */
let escuchando = false

function esTemaValido(valor: string | null): valor is Tema {
    return valor === 'light' || valor === 'dark' || valor === 'system'
}

function prefiereOscuro(): boolean {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

function aplicar(valor: Tema): void {
    const resuelto = valor === 'system' ? (prefiereOscuro() ? 'dark' : 'light') : valor

    efectivo.value = resuelto
    document.documentElement.setAttribute('data-theme', resuelto)

    // color-scheme le dice al navegador como pintar lo que NO controlamos:
    // scrollbars, autocompletado y los controles nativos de <select> y <input>.
    // Sin esto quedan blancos brillantes sobre el fondo oscuro.
    document.documentElement.style.colorScheme = resuelto
}

export function useTheme() {
    const establecer = (valor: Tema): void => {
        tema.value = valor
        aplicar(valor)

        try {
            localStorage.setItem(CLAVE, valor)
        } catch {
            // Modo privado o almacenamiento bloqueado: el tema sigue funcionando
            // en esta pestana, solo no se recuerda. No es motivo para romper nada.
        }
    }

    /** Alterna entre claro y oscuro tomando como base lo que se ve ahora. */
    const alternar = (): void => establecer(efectivo.value === 'dark' ? 'light' : 'dark')

    onMounted(() => {
        if (escuchando) return
        escuchando = true

        let guardado: string | null = null

        try {
            guardado = localStorage.getItem(CLAVE)
        } catch {
            // Igual que arriba: sin localStorage se arranca en 'system'.
        }

        tema.value = esTemaValido(guardado) ? guardado : 'system'
        aplicar(tema.value)

        // Con 'system' activo, el cambio del SO tiene que reflejarse en vivo:
        // sin esto habria que recargar para que la pagina siga al sistema.
        window.matchMedia?.('(prefers-color-scheme: dark)')
            .addEventListener('change', () => {
                if (tema.value === 'system') aplicar('system')
            })
    })

    return {
        tema: readonly(tema),
        efectivo: readonly(efectivo),
        establecer,
        alternar,
    }
}
