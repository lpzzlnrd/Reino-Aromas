import { computed, ref } from 'vue'
import api from '@/lib/axios'

/**
 * Botones automáticos de Instagram: Ice Breakers y Persistent Menu.
 *
 * Instagram no tiene WhatsApp Flows. Estos dos mecanismos son lo más cercano:
 * botones que el usuario toca y que disparan un webhook al CRM, que responde
 * con una plantilla o un texto fijo.
 *
 * El estado vive DENTRO de la función, igual que useMetaAccounts: solo la vista
 * de ajustes lo usa y no hay nada que compartir entre componentes.
 */

export type AutomationKind = 'ice_breaker' | 'menu_item'

export type ResponseType = 'template' | 'text' | 'handoff'

export type InstagramAutomation = {
    id: number
    kind: AutomationKind
    title: string
    payload: string
    response_type: ResponseType
    template_id: number | null
    template_name: string | null
    response_text: string | null
    url: string | null
    position: number
    is_active: boolean
    /** Veces que se tocó. Es la métrica que dice qué botón sirve; Meta no la da. */
    hits: number
    synced_at: string | null
    /** Hay cambios locales que Instagram todavía no conoce. */
    needs_sync: boolean
    /** Apunta a una plantilla borrada o desactivada: no responderá nada. */
    broken: boolean
}

/** Lo que se manda al crear o editar. */
export type AutomationPayload = {
    kind?: AutomationKind
    title?: string
    payload?: string
    response_type?: ResponseType
    template_id?: number | null
    response_text?: string | null
    url?: string | null
    position?: number
    is_active?: boolean
}

export function useInstagramAutomations() {
    const iceBreakers = ref<InstagramAutomation[]>([])
    const menuItems = ref<InstagramAutomation[]>([])
    const limits = ref({ ice_breakers: 4, menu_items: 5 })
    const pendingSync = ref(false)

    const cargando = ref(false)
    const sincronizando = ref(false)
    const error = ref<string | null>(null)

    /** Mensaje de Meta tras la última sincronización, para mostrarlo tal cual. */
    const ultimoResultado = ref<string | null>(null)

    const puedeAgregarIceBreaker = computed(
        () => iceBreakers.value.filter((b) => b.is_active).length < limits.value.ice_breakers,
    )

    const puedeAgregarMenuItem = computed(
        () => menuItems.value.filter((b) => b.is_active).length < limits.value.menu_items,
    )

    /** Botones que apuntan a una plantilla que ya no sirve. */
    const roto = computed(
        () => [...iceBreakers.value, ...menuItems.value].some((b) => b.broken),
    )

    const cargar = async (): Promise<void> => {
        cargando.value = true
        error.value = null

        try {
            const { data } = await api.get('/instagram/automations')

            iceBreakers.value = data.ice_breakers ?? []
            menuItems.value = data.menu_items ?? []
            limits.value = data.limits ?? limits.value
            pendingSync.value = data.pending_sync ?? false
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudieron cargar las automatizaciones.')
        } finally {
            cargando.value = false
        }
    }

    const crear = async (datos: AutomationPayload): Promise<boolean> => {
        error.value = null

        try {
            await api.post('/instagram/automations', datos)
            await cargar()

            return true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo crear el botón.')

            return false
        }
    }

    const actualizar = async (id: number, datos: AutomationPayload): Promise<boolean> => {
        error.value = null

        try {
            await api.patch(`/instagram/automations/${id}`, datos)
            await cargar()

            return true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo guardar el cambio.')

            return false
        }
    }

    const eliminar = async (id: number): Promise<boolean> => {
        error.value = null

        try {
            await api.delete(`/instagram/automations/${id}`)
            await cargar()

            return true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo eliminar el botón.')

            return false
        }
    }

    /**
     * Manda la configuración completa a Instagram.
     *
     * Es explícito y no automático al guardar: cada sincronización REEMPLAZA
     * toda la configuración en Meta, así que hacerlo en cada edición dejaría a
     * los clientes viendo botones a medio armar.
     */
    const sincronizar = async (): Promise<boolean> => {
        sincronizando.value = true
        error.value = null
        ultimoResultado.value = null

        try {
            const { data } = await api.post('/instagram/automations/sync')

            await cargar()

            ultimoResultado.value = data.success
                ? 'Configuración enviada a Instagram.'
                : 'Instagram rechazó parte de la configuración.'

            return data.success === true
        } catch (e: unknown) {
            error.value = mensajeDeError(e, 'No se pudo sincronizar con Instagram.')

            return false
        } finally {
            sincronizando.value = false
        }
    }

    return {
        iceBreakers,
        menuItems,
        limits,
        pendingSync,
        cargando,
        sincronizando,
        error,
        ultimoResultado,
        puedeAgregarIceBreaker,
        puedeAgregarMenuItem,
        roto,
        cargar,
        crear,
        actualizar,
        eliminar,
        sincronizar,
    }
}

/**
 * Saca el mensaje útil de un error de axios.
 *
 * Se prefiere el primer error de validación sobre el mensaje genérico: cuando
 * el backend rechaza un payload duplicado o un quinto Ice Breaker, ese detalle
 * es justo lo que el usuario necesita leer.
 */
function mensajeDeError(e: unknown, porDefecto: string): string {
    const respuesta = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data

    const primerError = respuesta?.errors ? Object.values(respuesta.errors)[0]?.[0] : undefined

    return primerError ?? respuesta?.message ?? porDefecto
}
