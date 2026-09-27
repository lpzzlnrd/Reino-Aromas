import { computed, ref } from 'vue'
import api from '@/lib/axios'

/**
 * Respuesta automática a quien escribe un DM a la cuenta de Instagram.
 *
 * Hermano de useInstagramCommentDm: aquél atiende a quien COMENTA un post,
 * éste a quien ESCRIBE al buzón. Tampoco hay nada que sincronizar con Meta —la
 * respuesta sale al recibir el webhook del mensaje, con el topic `messages`
 * que ya está suscrito.
 *
 * OJO: el baseURL de axios ya es `/api`, así que las rutas NO llevan `/api`
 * delante.
 */

export type DmResponseType = 'template' | 'text' | 'menu'

/** A quién se le responde. Ver la migración para el porqué del default. */
export type DmTriggerMode = 'first' | 'always'

export type DmAutoReplySettings = {
    id: number
    is_active: boolean
    response_type: DmResponseType
    template_id: number | null
    quick_reply_menu_id: number | null
    template: { id: number; name: string; is_active: boolean } | null
    response_text: string | null
    /** Vacío = responder a todos los mensajes. */
    keywords: string[]
    trigger_mode: DmTriggerMode
    /** No responder si un agente ya tomó el ticket. */
    skip_if_assigned: boolean
    /** Minutos de silencio antes de volver a responderle al mismo contacto. */
    cooldown_minutes: number
    /** 0 = sin tope. */
    daily_limit: number
    /** Activa pero la plantilla o el menú ya no sirven: no responderá. */
    broken: boolean
}

export type DmAutoReplyStats = {
    sent_today: number
    sent_total: number
    skipped_today: number
    failed_today: number
}

/** Un intento, para depurar desde la UI sin entrar al servidor. */
export type DmAutoReplyAttempt = {
    id: number
    username: string | null
    message: string | null
    status: 'sent' | 'skipped' | 'failed'
    reason: string | null
    created_at: string | null
}

/** Lo que se manda al guardar. Parcial: el backend acepta un PATCH. */
export type DmAutoReplyPayload = {
    is_active?: boolean
    response_type?: DmResponseType
    template_id?: number | null
    quick_reply_menu_id?: number | null
    response_text?: string | null
    keywords?: string[]
    trigger_mode?: DmTriggerMode
    skip_if_assigned?: boolean
    cooldown_minutes?: number
    daily_limit?: number
}

export function useInstagramDmAutoReply() {
    const settings = ref<DmAutoReplySettings | null>(null)
    const stats = ref<DmAutoReplyStats | null>(null)
    const recent = ref<DmAutoReplyAttempt[]>([])

    const cargando = ref(false)
    const guardando = ref(false)
    const error = ref<string | null>(null)

    /** Para el aviso de "guardado" sin tener que inventar un toast. */
    const guardado = ref(false)

    const activa = computed(() => settings.value?.is_active === true)

    const roto = computed(() => settings.value?.broken === true)

    const cargar = async (): Promise<void> => {
        cargando.value = true
        error.value = null

        try {
            const { data } = await api.get('/instagram/dm-settings')

            settings.value = data.settings ?? null
            stats.value = data.stats ?? null
            recent.value = Array.isArray(data.recent) ? data.recent : []
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo cargar la configuración.')
        } finally {
            cargando.value = false
        }
    }

    /**
     * Guarda los cambios y recarga.
     *
     * Se recarga en vez de confiar en la respuesta: las métricas y los últimos
     * intentos cambian por su cuenta (los mueve el webhook), así que un
     * guardado es tan buen momento como cualquiera para refrescarlos.
     */
    const guardar = async (datos: DmAutoReplyPayload): Promise<boolean> => {
        guardando.value = true
        error.value = null
        guardado.value = false

        try {
            await api.patch('/instagram/dm-settings', datos)
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
 * Se prefiere el primer error de validación sobre el `message` general:
 * Laravel manda "The given data was invalid" ahí, que no le dice nada a nadie.
 */
function mensajeDeError(e: unknown, porDefecto: string): string {
    const respuesta = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data

    const primerError = respuesta?.errors ? Object.values(respuesta.errors)[0]?.[0] : undefined

    return primerError ?? respuesta?.message ?? porDefecto
}
