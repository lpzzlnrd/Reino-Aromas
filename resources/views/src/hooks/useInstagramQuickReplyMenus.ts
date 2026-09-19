import { computed, ref } from 'vue'
import api from '@/lib/axios'
import type { ResponseType } from './useInstagramAutomations'

/**
 * Menús de opciones de Instagram (Quick Replies).
 *
 * Es lo más cercano a un WhatsApp Flow que permite Instagram: un mensaje con
 * hasta 13 burbujas sobre el teclado. Al tocar una, Meta dispara el mismo
 * webhook de postback que los Ice Breakers y el CRM responde con la plantilla
 * o el texto de esa opción.
 *
 * A diferencia de los Ice Breakers, un menú NO se publica en el perfil de
 * Meta: viaja dentro de un mensaje concreto. Por eso acá no hay sincronizar().
 *
 * El estado vive FUERA de la función, como en useAssignableUsers y useStates.
 * Acá es obligatorio y no una preferencia: dos componentes hermanos leen esta
 * misma lista —el selector de «Respuesta a quien comenta» y la sección de
 * menús— y con el estado dentro cada uno se llevaba su copia. Crear un menú
 * abajo no aparecía nunca en el selector de arriba, porque quien recargaba era
 * la otra instancia.
 */

export type QuickReplyOption = {
    id: number
    title: string
    payload: string
    response_type: ResponseType
    template_id: number | null
    template_name: string | null
    response_text: string | null
    position: number
    is_active: boolean
    /** Veces que se tocó esta opción. Meta no da esta métrica; la lleva el CRM. */
    hits: number
    /** Apunta a una plantilla borrada o desactivada: no responderá nada. */
    is_broken: boolean
}

export type QuickReplyMenu = {
    id: number
    name: string
    body: string
    is_active: boolean
    /** Veces que se envió este menú. */
    sends: number
    options: QuickReplyOption[]
    /** Está activo y tiene al menos una opción activa: se puede enviar. */
    is_complete: boolean
    /** Cuántas opciones activas no responden nada. */
    broken_options: number
}

export type MenuPayload = {
    name?: string
    body?: string
    is_active?: boolean
}

export type OptionPayload = {
    title?: string
    response_type?: ResponseType
    template_id?: number | null
    response_text?: string | null
    position?: number
    is_active?: boolean
}

const menus = ref<QuickReplyMenu[]>([])
const limits = ref({ opciones: 13, titulo: 20, cuerpo: 1000 })

const cargando = ref(false)
const guardando = ref(false)
const error = ref<string | null>(null)

/** La petición de carga en curso, para que dos componentes no pidan lo mismo. */
let enVuelo: Promise<void> | null = null

export function useInstagramQuickReplyMenus() {
    /**
     * Menús que se enviarían mal si se usaran ahora mismo.
     *
     * Es lo que la vista pinta en rojo: un menú sin opciones activas llega como
     * un texto suelto, y como Meta permite UN solo DM por comentario, no hay
     * segunda oportunidad de mandarle las opciones a esa persona.
     */
    const menusIncompletos = computed(
        () => menus.value.filter((m) => m.is_active && !m.is_complete),
    )

    /** Menús con alguna opción que no responde nada. */
    const menusConOpcionesRotas = computed(
        () => menus.value.filter((m) => m.broken_options > 0),
    )

    /**
     * Trae la lista del servidor.
     *
     * Si ya hay una petición en vuelo devuelve ESA en vez de lanzar otra: los
     * dos componentes que usan el hook montan a la vez y cada uno llama a
     * cargar() en su onMounted, que sin esto serían dos GET idénticos en el
     * mismo tick. Las recargas posteriores (tras crear o editar) sí piden de
     * nuevo, porque para entonces `enVuelo` ya volvió a null.
     */
    const cargar = async (): Promise<void> => {
        if (enVuelo !== null) return await enVuelo

        cargando.value = true
        error.value = null

        enVuelo = (async (): Promise<void> => {
            try {
                const { data } = await api.get('/instagram/quick-reply-menus')

                menus.value = data.menus ?? []
                limits.value = data.limits ?? limits.value
            } catch (e: unknown) {
                error.value = mensajeDeError(e, 'No se pudieron cargar los menús.')
            } finally {
                cargando.value = false
                enVuelo = null
            }
        })()

        return await enVuelo
    }

    const crearMenu = async (datos: MenuPayload): Promise<boolean> => {
        return await ejecutar(
            () => api.post('/instagram/quick-reply-menus', datos),
            'No se pudo crear el menú.',
        )
    }

    const actualizarMenu = async (id: number, datos: MenuPayload): Promise<boolean> => {
        return await ejecutar(
            () => api.patch(`/instagram/quick-reply-menus/${id}`, datos),
            'No se pudo guardar el cambio.',
        )
    }

    const eliminarMenu = async (id: number): Promise<boolean> => {
        return await ejecutar(
            () => api.delete(`/instagram/quick-reply-menus/${id}`),
            'No se pudo eliminar el menú.',
        )
    }

    const crearOpcion = async (menuId: number, datos: OptionPayload): Promise<boolean> => {
        return await ejecutar(
            () => api.post(`/instagram/quick-reply-menus/${menuId}/options`, datos),
            'No se pudo agregar la opción.',
        )
    }

    const actualizarOpcion = async (
        menuId: number,
        opcionId: number,
        datos: OptionPayload,
    ): Promise<boolean> => {
        return await ejecutar(
            () => api.patch(`/instagram/quick-reply-menus/${menuId}/options/${opcionId}`, datos),
            'No se pudo guardar la opción.',
        )
    }

    const eliminarOpcion = async (menuId: number, opcionId: number): Promise<boolean> => {
        return await ejecutar(
            () => api.delete(`/instagram/quick-reply-menus/${menuId}/options/${opcionId}`),
            'No se pudo eliminar la opción.',
        )
    }

    /**
     * Las seis operaciones comparten forma: llamar, recargar y traducir el
     * error. Sin esto el hook serían seis bloques try/catch idénticos.
     *
     * Recarga todo en vez de parchear el array local a propósito: el backend
     * recalcula `is_complete` y `broken_options`, que dependen de las
     * plantillas y no solo de lo que se acaba de guardar.
     */
    const ejecutar = async (
        operacion: () => Promise<unknown>,
        mensajePorDefecto: string,
    ): Promise<boolean> => {
        guardando.value = true
        error.value = null

        try {
            await operacion()
            await cargar()

            return true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, mensajePorDefecto)

            return false
        } finally {
            guardando.value = false
        }
    }

    return {
        menus,
        limits,
        cargando,
        guardando,
        error,
        menusIncompletos,
        menusConOpcionesRotas,
        cargar,
        crearMenu,
        actualizarMenu,
        eliminarMenu,
        crearOpcion,
        actualizarOpcion,
        eliminarOpcion,
    }
}

/**
 * El primer error de validación, que es el accionable, y si no el mensaje
 * general. Mismo criterio que useInstagramAutomations.
 */
function mensajeDeError(e: unknown, porDefecto: string): string {
    const respuesta = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data

    const primerError = respuesta?.errors ? Object.values(respuesta.errors)[0]?.[0] : undefined

    return primerError ?? respuesta?.message ?? porDefecto
}
