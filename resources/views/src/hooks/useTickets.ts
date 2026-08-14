import { computed, reactive, ref } from 'vue'
import api from '@/lib/axios'
import {
    CaseStatus,
    ETIQUETA_POR_STATUS,
    STATUS_POR_ETIQUETA,
    useCaseStatus,
    type TicketStatus,
} from './caseStatus'
import type { ChatTag, MessageSender, TicketPriority } from './useInbox'

/**
 * Tablero Kanban de tickets: carga el embudo, mueve tarjetas entre columnas y
 * escucha los cambios que hacen otros agentes.
 *
 * A diferencia de useInbox, el estado vive DENTRO de la función: el tablero lo
 * pinta un solo componente, así que no hay nada que compartir. Un singleton
 * aquí solo serviría para conservar tarjetas viejas al volver a entrar.
 */

/** Contacto tal como lo trae TicketController::serializar(). */
export type TicketContact = {
    id: number
    display_name: string | null
    profile_picture_url: string | null
    channel: 'whatsapp' | 'instagram' | 'facebook'
    city: string | null
}

/** Una tarjeta del tablero. Forma de TicketController::serializar(). */
export type BoardTicket = {
    id: number
    conversation_id: number
    status: TicketStatus
    status_label: CaseStatus
    priority: TicketPriority
    city: string | null
    course_interest: string | null
    notes: string | null
    reserved_at: string | null
    closed_at: string | null
    updated_at: string | null
    contact: TicketContact | null
    assigned_user: MessageSender | null
    tags: ChatTag[]
}

export type BoardFilters = {
    priority: TicketPriority | ''
    mine: boolean
}

/** Prioridades de mayor a menor, en el mismo orden que ordena el backend. */
export const PRIORIDADES: { valor: TicketPriority; etiqueta: string }[] = [
    { valor: 'muy_alta', etiqueta: 'Muy alta' },
    { valor: 'alta',     etiqueta: 'Alta' },
    { valor: 'media',    etiqueta: 'Media' },
    { valor: 'baja',     etiqueta: 'Baja' },
]

/** Columnas vacías, una por estado del embudo. */
const tableroVacio = (): Record<CaseStatus, BoardTicket[]> => ({
    [CaseStatus.New]: [],
    [CaseStatus.Interested]: [],
    [CaseStatus.HighPriority]: [],
    [CaseStatus.Following]: [],
    [CaseStatus.Reserved]: [],
    [CaseStatus.Closed]: [],
})

export function useTickets() {
    const { statuses, setCounts } = useCaseStatus()

    /**
     * Las columnas. `reactive` y no `ref` porque vue-draggable-plus recibe cada
     * array con v-model y los muta en sitio al arrastrar; con un ref habría que
     * reasignar el objeto entero en cada movimiento.
     */
    const board = reactive<Record<CaseStatus, BoardTicket[]>>(tableroVacio())

    const loading = ref(false)
    const error = ref<string | null>(null)

    /**
     * Id del ticket que está guardando su cambio de columna. Sirve para atenuar
     * la tarjeta mientras el PATCH viaja, sin bloquear el resto del tablero.
     */
    const moviendo = ref<number | null>(null)

    const filters = ref<BoardFilters>({ priority: '', mine: false })

    const total = computed(() =>
        statuses.reduce((suma, status) => suma + board[status].length, 0),
    )

    const hasFilters = computed(
        () => filters.value.priority !== '' || filters.value.mine,
    )

    /** Vacía las columnas sin perder la referencia de cada array. */
    const limpiar = (): void => {
        for (const status of statuses) board[status].length = 0
    }

    /** Reparte los tickets en sus columnas respetando el orden del backend. */
    const repartir = (tickets: BoardTicket[]): void => {
        limpiar()

        for (const ticket of tickets) {
            // Se usa el status crudo y no status_label: la etiqueta la calcula
            // el backend y un valor inesperado dejaría la tarjeta sin columna.
            const columna = ETIQUETA_POR_STATUS[ticket.status]
            if (columna) board[columna].push(ticket)
        }
    }

    const loadBoard = async (): Promise<void> => {
        loading.value = true
        error.value = null

        try {
            const { data } = await api.get<BoardTicket[]>('/tickets', {
                params: {
                    priority: filters.value.priority || undefined,
                    mine: filters.value.mine ? 1 : undefined,
                },
            })

            repartir(data ?? [])
        } catch {
            limpiar()
            error.value = 'No se pudo cargar el tablero'
        } finally {
            loading.value = false
        }
    }

    /** Alimenta los contadores compartidos con la bandeja. */
    const loadCounts = async (): Promise<void> => {
        try {
            const { data } = await api.get<Record<string, number>>('/tickets/counts')
            setCounts(data ?? {})
        } catch {
            // Decorativos: el tablero ya muestra su propio contador por columna.
        }
    }

    /** Saca un ticket de todas las columnas y devuelve dónde estaba. */
    const extraer = (id: number): { columna: CaseStatus; indice: number; ticket: BoardTicket } | null => {
        for (const status of statuses) {
            const indice = board[status].findIndex((t) => t.id === id)

            if (indice !== -1) {
                const extraidos = board[status].splice(indice, 1)
                const ticket = extraidos[0]

                if (ticket) return { columna: status, indice, ticket }
            }
        }

        return null
    }

    const buscar = (id: number): BoardTicket | null => {
        for (const status of statuses) {
            const ticket = board[status].find((t) => t.id === id)
            if (ticket) return ticket
        }

        return null
    }

    /**
     * Persiste el cambio de columna tras un arrastre.
     *
     * vue-draggable-plus ya movió la tarjeta en el array antes de llamar aquí,
     * así que la actualización es optimista por construcción: el agente ve el
     * movimiento al instante. Lo que hace esta función es confirmarlo contra el
     * servidor y devolver la tarjeta a su columna si el PATCH falla — esperar
     * la respuesta antes de mover haría que el arrastre se sintiera roto.
     */
    const moveTicket = async (id: number, destino: CaseStatus): Promise<boolean> => {
        const ticket = buscar(id)
        if (!ticket) return false

        // De dónde venía, para poder revertir. Se lee del ticket y no de la
        // columna donde está ahora: draggable ya lo movió.
        const origen = ETIQUETA_POR_STATUS[ticket.status]
        const status = STATUS_POR_ETIQUETA[destino]

        // Soltar la tarjeta en su propia columna (reordenar) no es un cambio de
        // estado: el backend lo ignoraría y sobra el request.
        if (status === ticket.status) return true

        // Se adelanta el estado local para que el badge de la tarjeta concuerde
        // con la columna donde ya está dibujada.
        ticket.status = status
        ticket.status_label = destino

        moviendo.value = id
        error.value = null

        try {
            const { data } = await api.patch(`/tickets/${id}`, { status })
            const actualizado = data.ticket as BoardTicket | undefined

            // El servidor devuelve el ticket serializado con reserved_at y
            // closed_at ya calculados, que el front no sabe deducir.
            if (actualizado) Object.assign(ticket, actualizado)

            void loadCounts()

            return true
        } catch {
            // Reversión: vuelve a su columna de origen.
            ticket.status = STATUS_POR_ETIQUETA[origen]
            ticket.status_label = origen

            const extraido = extraer(id)
            if (extraido && origen) board[origen].push(extraido.ticket)

            error.value = 'No se pudo mover la tarjeta. Volvió a su columna.'

            return false
        } finally {
            moviendo.value = null
        }
    }

    const clearFilters = (): void => {
        filters.value = { priority: '', mine: false }
    }

    /*
    |-------------------------------------------------------------------------
    | Entrada de eventos en tiempo real
    |
    | Lo llama el componente desde useRealtime(), igual que en la bandeja.
    |-------------------------------------------------------------------------
    */

    /**
     * Un ticket cambió (evento 'ticket.updated'), normalmente porque otro
     * agente movió su tarjeta.
     *
     * El payload trae `previous` con los valores anteriores, pero no se usa
     * para localizar la tarjeta: se busca por id en las seis columnas, que
     * funciona igual y no depende de que el estado local coincida con lo que
     * el otro agente tenía.
     *
     * Ojo con la forma: TicketUpdated::broadcastWith() sigue a
     * serializarTicket() (la del panel del chat), que NO incluye contact,
     * conversation_id ni las marcas de tiempo. Por eso el payload se fusiona
     * sobre la tarjeta existente en vez de reemplazarla — si no, la tarjeta se
     * quedaría sin nombre ni avatar en cuanto otro agente la moviera.
     */
    const onTicketUpdated = (payload: {
        conversation_id?: number
        ticket: Partial<BoardTicket> & { id: number; status: TicketStatus }
        previous?: { status?: TicketStatus } | null
    }): void => {
        const { ticket } = payload
        if (!ticket) return

        const destino = ETIQUETA_POR_STATUS[ticket.status]
        if (!destino) return

        const existente = buscar(ticket.id)

        // Evento propio: la tarjeta ya está donde debe y moveTicket ya la
        // completó con la respuesta del PATCH. Sin esta salida se re-insertaría
        // encima del arrastre que el agente acaba de hacer.
        if (existente && existente.status === ticket.status) {
            Object.assign(existente, ticket)
            return
        }

        // Respeta los filtros activos: si el tablero filtra por prioridad, un
        // ticket que ya no encaja se va del tablero en vez de saltar de columna.
        const encaja =
            filters.value.priority === '' || ticket.priority === filters.value.priority

        if (existente) {
            // Se conservan los campos que el evento no manda (contact,
            // conversation_id, updated_at) fusionando sobre lo que ya había.
            Object.assign(existente, ticket)

            extraer(ticket.id)
            if (encaja) board[destino].unshift(existente)
        } else if (encaja) {
            // Tarjeta que el tablero no tenía: el payload no alcanza para
            // pintarla (le falta el contacto), así que se recarga. Es el único
            // caso que necesita ir al servidor, igual que en la bandeja.
            void loadBoard()
        }

        void loadCounts()
    }

    return {
        board,
        statuses,
        filters,
        loading,
        error,
        moviendo,
        total,
        hasFilters,
        loadBoard,
        loadCounts,
        moveTicket,
        clearFilters,
        onTicketUpdated,
    }
}
