import { computed, ref } from 'vue'
import api from '@/lib/axios'
import {
    CaseStatus,
    STATUS_POR_ETIQUETA,
    useCaseStatus,
    type TicketStatus,
} from './caseStatus'

/**
 * Bandeja de mensajes: lista de chats, conversación abierta y envío.
 *
 * Reemplaza a useMetaData.ts, que quedó sin uso: su tipo MetaChat solo cubría
 * la línea de preview (nombre, último mensaje, hora) y la bandeja necesita
 * además el canal, el ticket, el contacto y el historial completo.
 *
 * El estado vive fuera de la función a propósito — es un singleton, igual que
 * useDashboard.ts. La lista de chats la pinta el layout y el chat abierto lo
 * pinta un componente hijo distinto; sin estado compartido habría que pasar
 * todo por props (que es justo de donde venimos) o pedir los mismos datos dos
 * veces.
 */

export type Channel = 'whatsapp' | 'instagram' | 'facebook'

/** Estados que puede tener un mensaje saliente. Ver App\Models\Message. */
export type MessageStatus = 'pending' | 'sent' | 'delivered' | 'read' | 'failed'

/**
 * El status crudo y el mapa de traducción viven en caseStatus.ts: el Kanban
 * necesita lo mismo. Se re-exporta el tipo porque varios componentes de la
 * bandeja lo importan desde aquí.
 */
export type { TicketStatus }

export type TicketPriority = 'baja' | 'media' | 'alta' | 'muy_alta'

/** Una fila de la lista. Forma de ConversationController::serializarResumen. */
export type ChatSummary = {
    id: number
    contact_name: string
    contact_avatar: string | null
    last_message: string
    message_time: string | null
    location: string | null
    /** Etiqueta del enum, no el status crudo: el backend ya traduce. */
    case_status: CaseStatus
    channel: Channel | null
}

export type MessageSender = {
    id: number
    name: string
    avatar: string | null
}

export type ChatMessage = {
    id: number
    direction: 'inbound' | 'outbound'
    channel: Channel
    type: string
    body: string | null
    media_url: string | null
    status: MessageStatus
    /** Por qué falló el envío. Solo viene con status 'failed'. */
    failed_reason: string | null
    sender: MessageSender | null
    sent_at: string | null
    delivered_at: string | null
    read_at: string | null
    created_at: string
}

export type ChatContact = {
    id: number
    display_name: string | null
    profile_picture_url: string | null
    channel: Channel
    channel_id: string
    city: string | null
    phone: string | null
    instagram_handle: string | null
    first_seen_at: string | null
}

export type ChatTag = {
    id: number
    name: string
    color: string | null
}

export type ChatTicket = {
    id: number
    status: TicketStatus
    status_label: CaseStatus
    priority: TicketPriority
    city: string | null
    course_interest: string | null
    notes: string | null
    assigned_user: MessageSender | null
    tags: ChatTag[]
}

/** Forma de ConversationController::show. */
export type ChatDetail = {
    id: number
    status: 'open' | 'closed'
    /**
     * Fuera de esta ventana Meta solo acepta plantillas aprobadas, no texto
     * libre. El chat lo usa para avisar antes de que el envío falle.
     */
    within_24h_window: boolean
    last_message_at: string | null
    contact: ChatContact | null
    ticket: ChatTicket | null
    messages: ChatMessage[]
}

export type ChatTemplate = {
    id: number
    name: string
    category: string | null
    body: string
    /** El cuerpo con los datos del contacto ya sustituidos. */
    rendered_body: string
}

export type InboxFilters = {
    search: string
    channel: Channel | ''
    case: CaseStatus | null
    mine: boolean
}

const chats = ref<ChatSummary[]>([])
const detail = ref<ChatDetail | null>(null)
const templates = ref<ChatTemplate[]>([])

const loadingChats = ref(false)
const loadingDetail = ref(false)
const sending = ref(false)

const chatsError = ref<string | null>(null)
const detailError = ref<string | null>(null)
const sendError = ref<string | null>(null)

const filters = ref<InboxFilters>({
    search: '',
    channel: '',
    case: null,
    mine: false,
})

/**
 * Id de la conversación abierta. Se guarda aparte de `detail` para poder
 * marcar la fila seleccionada mientras el detalle todavía está cargando.
 */
const selectedId = ref<number | null>(null)

/**
 * Completa el mensaje que devuelve el POST.
 *
 * La respuesta del envío trae solo 7 campos (id, direction, channel, type,
 * body, status, created_at), no los 13 de la conversación: le faltan
 * failed_reason, sender, media_url y las marcas de tiempo de entrega. Sin
 * rellenarlos, el hilo leería undefined en las plantillas que pintan el
 * estado de entrega.
 */
const normalizarMensaje = (raw: Record<string, any>): ChatMessage => ({
    media_url: null,
    failed_reason: null,
    sender: null,
    sent_at: null,
    delivered_at: null,
    read_at: null,
    ...raw,
} as ChatMessage)

export function useInbox() {
    const { setCounts } = useCaseStatus()

    const selectedChat = computed(
        () => chats.value.find((c) => c.id === selectedId.value) ?? null,
    )

    const hasFilters = computed(
        () =>
            filters.value.search !== '' ||
            filters.value.channel !== '' ||
            filters.value.case !== null ||
            filters.value.mine,
    )

    const loadChats = async () => {
        loadingChats.value = true
        chatsError.value = null

        try {
            const { data } = await api.get<ChatSummary[]>('/meta/chats', {
                params: {
                    // 'all' y no el default 'open': la bandeja filtra por estado
                    // de ticket, y esconder los chats cerrados haría que el
                    // filtro "Cerrado" no devolviera nunca nada.
                    status: 'all',
                    search: filters.value.search || undefined,
                    channel: filters.value.channel || undefined,
                    case: filters.value.case
                        ? STATUS_POR_ETIQUETA[filters.value.case]
                        : undefined,
                    mine: filters.value.mine ? 1 : undefined,
                },
            })

            chats.value = data ?? []
        } catch {
            chats.value = []
            chatsError.value = 'No se pudieron cargar las conversaciones'
        } finally {
            loadingChats.value = false
        }
    }

    /** Alimenta los contadores de los botones de filtro. */
    const loadCounts = async () => {
        try {
            const { data } = await api.get<Record<string, number>>('/tickets/counts')
            setCounts(data ?? {})
        } catch {
            // Los contadores son decorativos: si fallan, los filtros siguen
            // funcionando. No se pisa el error de la lista.
        }
    }

    const openChat = async (id: number) => {
        selectedId.value = id
        loadingDetail.value = true
        detailError.value = null
        sendError.value = null
        // Se limpia el detalle anterior para que no se vea la conversación de
        // otro contacto mientras carga la nueva.
        detail.value = null
        templates.value = []

        try {
            const { data } = await api.get<ChatDetail>(`/meta/conversations/${id}`)
            detail.value = data
        } catch {
            detailError.value = 'No se pudo abrir la conversación'
        } finally {
            loadingDetail.value = false
        }

        // Las plantillas son secundarias y van aparte: si fallan, el agente
        // igual puede escribir a mano.
        void loadTemplates(id)
    }

    const closeChat = () => {
        selectedId.value = null
        detail.value = null
        templates.value = []
    }

    const loadTemplates = async (id: number) => {
        try {
            const { data } = await api.get<ChatTemplate[]>(`/conversations/${id}/templates`)
            templates.value = data ?? []
        } catch {
            templates.value = []
        }
    }

    /**
     * Encola un mensaje de texto.
     *
     * El backend responde 202, no 200: el envío real lo hace la cola y el
     * mensaje nace en 'pending'. Se inserta tal cual en el historial para que
     * el agente vea que salió; el paso a 'sent' llega al refrescar (en Semana 4
     * lo hará el broadcasting).
     */
    const sendMessage = async (body: string): Promise<boolean> => {
        const texto = body.trim()
        if (texto === '' || detail.value === null) return false

        sending.value = true
        sendError.value = null

        const conversationId = detail.value.id

        try {
            const { data } = await api.post(
                `/meta/conversations/${conversationId}/messages`,
                { body: texto },
            )

            if (data.message && detail.value?.id === conversationId) {
                detail.value.messages.push(normalizarMensaje(data.message))
            }

            // La fila de la lista muestra el último mensaje: sin esto el
            // preview seguiría mostrando el mensaje del cliente.
            const fila = chats.value.find((c) => c.id === conversationId)
            if (fila) {
                fila.last_message = texto
                fila.message_time = new Date().toISOString()
            }

            return true
        } catch (e: any) {
            // 422 = canal no soportado o conversación sin contacto. El mensaje
            // del backend explica cuál de los dos, así que se muestra tal cual.
            sendError.value =
                e?.response?.data?.message ?? 'No se pudo enviar el mensaje'
            return false
        } finally {
            sending.value = false
        }
    }

    /** Envía una plantilla. El servidor la renderiza; el front solo manda el id. */
    const sendTemplate = async (templateId: number): Promise<boolean> => {
        if (detail.value === null) return false

        sending.value = true
        sendError.value = null

        const conversationId = detail.value.id

        try {
            const { data } = await api.post(
                `/meta/conversations/${conversationId}/messages/template/${templateId}`,
            )

            if (data.message && detail.value?.id === conversationId) {
                const mensaje = normalizarMensaje(data.message)
                detail.value.messages.push(mensaje)

                const fila = chats.value.find((c) => c.id === conversationId)
                if (fila) {
                    fila.last_message = mensaje.body ?? ''
                    fila.message_time = mensaje.created_at
                }
            }

            return true
        } catch (e: any) {
            sendError.value =
                e?.response?.data?.message ?? 'No se pudo enviar la plantilla'
            return false
        } finally {
            sending.value = false
        }
    }

    /**
     * Cierra o reabre la conversación.
     *
     * Ojo: no toca el ticket. Son ciclos de vida distintos — el chat puede
     * cerrarse con el ticket todavía en seguimiento.
     */
    const setConversationStatus = async (
        id: number,
        accion: 'close' | 'reopen',
    ): Promise<boolean> => {
        try {
            await api.patch(`/meta/conversations/${id}/${accion}`)

            if (detail.value?.id === id) {
                detail.value.status = accion === 'close' ? 'closed' : 'open'
            }

            return true
        } catch {
            detailError.value =
                accion === 'close'
                    ? 'No se pudo cerrar la conversación'
                    : 'No se pudo reabrir la conversación'
            return false
        }
    }

    /** Actualización parcial del ticket (estado, prioridad, notas). */
    const updateTicket = async (
        payload: Partial<Pick<ChatTicket, 'status' | 'priority' | 'notes' | 'city' | 'course_interest'>>,
    ): Promise<boolean> => {
        const ticket = detail.value?.ticket
        if (!ticket) return false

        try {
            const { data } = await api.patch(`/tickets/${ticket.id}`, payload)
            const actualizado = data.ticket

            if (actualizado && detail.value?.ticket) {
                detail.value.ticket = { ...detail.value.ticket, ...actualizado }

                // El badge de la lista lee case_status, no el ticket.
                const fila = chats.value.find((c) => c.id === detail.value?.id)
                if (fila && actualizado.status_label) {
                    fila.case_status = actualizado.status_label
                }
            }

            // El estado del ticket cambió: los contadores quedaron viejos.
            void loadCounts()

            return true
        } catch {
            detailError.value = 'No se pudo actualizar el ticket'
            return false
        }
    }

    const clearFilters = () => {
        filters.value = { search: '', channel: '', case: null, mine: false }
    }

    /*
    |-------------------------------------------------------------------------
    | Entrada de eventos en tiempo real
    |
    | Los llaman los componentes desde useRealtime(). El hook no se suscribe
    | por su cuenta: la suscripción tiene que morir con el componente que la
    | abrió, y este hook es un singleton que nunca se desmonta.
    |-------------------------------------------------------------------------
    */

    /**
     * Llegó un mensaje (evento 'message.created').
     *
     * Cubre los dos casos: uno entrante del cliente y uno saliente enviado por
     * OTRO agente desde otra pestaña.
     */
    const onMessageCreated = (payload: {
        conversation_id: number
        message: ChatMessage
    }): void => {
        const { conversation_id: conversationId, message } = payload

        // Al chat abierto, si es el suyo.
        if (detail.value?.id === conversationId) {
            // El que envía ya insertó su mensaje al recibir el 202, así que
            // cuando el socket lo devuelve ya está en la lista. Sin esta
            // comprobación la burbuja saldría dos veces.
            const yaEsta = detail.value.messages.some((m) => m.id === message.id)

            if (!yaEsta) {
                detail.value.messages.push(message)
            } else {
                // Puede traer datos que el 202 no tenía (sender resuelto,
                // sent_at), así que se completa en vez de ignorarlo.
                const i = detail.value.messages.findIndex((m) => m.id === message.id)
                if (i !== -1) detail.value.messages[i] = message
            }
        }

        // A la fila de la bandeja, que muestra el último mensaje.
        const fila = chats.value.find((c) => c.id === conversationId)

        if (fila) {
            fila.last_message = message.body ?? ''
            fila.message_time = message.created_at

            // La lista va ordenada por actividad: el chat que acaba de recibir
            // sube al principio, como en cualquier bandeja.
            const i = chats.value.indexOf(fila)
            if (i > 0) {
                chats.value.splice(i, 1)
                chats.value.unshift(fila)
            }
        } else {
            // Conversación que no estaba en la lista: un contacto nuevo escribió
            // por primera vez. No se puede construir la fila desde el payload
            // del mensaje (falta el nombre, el canal, la ciudad), así que se
            // recarga. Es el único caso que necesita ir al servidor.
            void loadChats()
        }
    }

    /**
     * Cambió el estado de entrega de un mensaje (evento 'message.status').
     *
     * Es lo que cierra el círculo del envío: el mensaje nace 'pending' y esto
     * lo pasa a 'sent' o 'failed' sin que el agente recargue.
     */
    const onMessageStatus = (payload: {
        conversation_id: number
        id: number
        status: MessageStatus
        failed_reason: string | null
        sent_at: string | null
        delivered_at: string | null
        read_at: string | null
    }): void => {
        if (detail.value?.id !== payload.conversation_id) return

        const mensaje = detail.value.messages.find((m) => m.id === payload.id)
        if (!mensaje) return

        mensaje.status = payload.status
        mensaje.failed_reason = payload.failed_reason
        mensaje.sent_at = payload.sent_at
        mensaje.delivered_at = payload.delivered_at
        mensaje.read_at = payload.read_at
    }

    /**
     * Un ticket cambió (evento 'ticket.updated').
     *
     * Puede venir de otro agente moviendo la tarjeta en el Kanban o editando el
     * panel del chat.
     */
    const onTicketUpdated = (payload: {
        conversation_id: number
        ticket: ChatTicket
    }): void => {
        const { conversation_id: conversationId, ticket } = payload

        if (detail.value?.id === conversationId) {
            detail.value.ticket = ticket
        }

        // El badge de la fila lee case_status, que es la etiqueta.
        const fila = chats.value.find((c) => c.id === conversationId)
        if (fila) fila.case_status = ticket.status_label

        // Los contadores de los filtros quedaron viejos.
        void loadCounts()
    }

    return {
        chats,
        detail,
        templates,
        filters,
        selectedId,
        selectedChat,
        hasFilters,
        loadingChats,
        loadingDetail,
        sending,
        chatsError,
        detailError,
        sendError,
        loadChats,
        loadCounts,
        openChat,
        closeChat,
        sendMessage,
        sendTemplate,
        setConversationStatus,
        updateTicket,
        clearFilters,
        onMessageCreated,
        onMessageStatus,
        onTicketUpdated,
    }
}
