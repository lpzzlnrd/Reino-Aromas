import { computed, ref } from 'vue'
import api from '@/lib/axios'

/**
 * DM de bienvenida a quien comenta un post de Instagram.
 *
 * A diferencia de los botones (useInstagramAutomations) acá NO hay nada que
 * sincronizar con Meta: el mensaje se manda en el momento en que llega el
 * webhook del comentario. Lo único que hace falta en Meta es la suscripción al
 * topic `comments`, que se configura una vez desde el panel.
 *
 * El estado vive dentro de la función: solo la vista de ajustes lo usa.
 *
 * OJO: el baseURL de axios ya es `/api`, así que las rutas NO llevan `/api`
 * delante. Es la trampa que ya mordió una vez en useInstagramAutomations.
 */

export type CommentResponseType = 'template' | 'text'

export type CommentDmSettings = {
    id: number
    is_active: boolean
    response_type: CommentResponseType
    template_id: number | null
    template: { id: number; name: string; is_active: boolean } | null
    response_text: string | null
    /** Vacío = responder a todos los comentarios. */
    keywords: string[]
    /** 0 = sin tope. */
    daily_limit: number
    /** Está activa pero la plantilla se borró o se desactivó: no responderá. */
    broken: boolean
}

export type CommentDmStats = {
    sent_today: number
    sent_total: number
    skipped_today: number
    failed_today: number
}

/** Un intento de envío, para depurar desde la UI sin entrar al servidor. */
export type CommentDmAttempt = {
    id: number
    username: string | null
    comment: string | null
    status: 'sent' | 'skipped' | 'failed'
    reason: string | null
    created_at: string | null
}

/** Lo que se manda al guardar. Parcial: el backend acepta un PATCH. */
export type CommentDmPayload = {
    is_active?: boolean
    response_type?: CommentResponseType
    template_id?: number | null
    response_text?: string | null
    keywords?: string[]
    daily_limit?: number
}

export function useInstagramCommentDm() {
    const settings = ref<CommentDmSettings | null>(null)
    const stats = ref<CommentDmStats | null>(null)
    const recent = ref<CommentDmAttempt[]>([])

    const cargando = ref(false)
    const guardando = ref(false)
    const error = ref<string | null>(null)

    /** Para el aviso de "guardado" sin tener que inventar un toast. */
    const guardado = ref(false)

    const activa = computed(() => settings.value?.is_active === true)

    /** Apunta a una plantilla que ya no sirve. */
    const roto = computed(() => settings.value?.broken === true)

    const cargar = async (): Promise<void> => {
        cargando.value = true
        error.value = null

        try {
            const { data } = await api.get('/instagram/comment-settings')

            settings.value = data.settings ?? null
            stats.value = data.stats ?? null
            recent.value = data.recent ?? []
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo cargar la configuración.')
        } finally {
            cargando.value = false
        }
    }

    /**
     * Guarda los cambios.
     *
     * Recarga todo al terminar en vez de confiar en la respuesta: las métricas
     * y los últimos intentos cambian por su cuenta (los manda el webhook), así
     * que un guardado es tan buen momento como cualquiera para refrescarlos.
     */
    const guardar = async (datos: CommentDmPayload): Promise<boolean> => {
        guardando.value = true
        error.value = null
        guardado.value = false

        try {
            await api.patch('/instagram/comment-settings', datos)
            await cargar()

            guardado.value = true
            window.setTimeout(() => (guardado.value = false), 2500)

            return true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo guardar la configuración.')

            return false
        } finally {
            guardando.value = false
        }
    }

    /**
     * Enciende o apaga la automatización.
     *
     * Va aparte de guardar() porque es el interruptor que más se usa y no debe
     * arrastrar el resto del formulario: si alguien está editando el texto y
     * apaga el interruptor, no queremos guardarle un borrador a medias.
     */
    const alternar = async (): Promise<boolean> => {
        if (settings.value === null) return false

        return guardar({ is_active: !settings.value.is_active })
    }

    return {
        settings,
        stats,
        recent,
        cargando,
        guardando,
        guardado,
        error,
        activa,
        roto,
        cargar,
        guardar,
        alternar,
    }
}

/**
 * El mensaje del backend si vino, el genérico si no.
 *
 * Se prefiere el primer error de validación sobre el `message` general: Laravel
 * manda "The given data was invalid" ahí, que no le dice nada a nadie.
 */
function mensajeDeError(e: unknown, porDefecto: string): string {
    const respuesta = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data

    const primerError = respuesta?.errors ? Object.values(respuesta.errors)[0]?.[0] : undefined

    return primerError ?? respuesta?.message ?? porDefecto
}
