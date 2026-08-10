import { ref } from 'vue'
import api from '@/lib/axios'
import { CaseStatus } from './caseStatus'

/**
 * Datos reales del panel de control.
 *
 * Antes cada componente del dashboard traía sus números escritos a mano:
 * dashboard.cities.vue tenía 400/205/182 clientes fijos (y solo 3 de las 5
 * sedes) y la tabla de urgentes mostraba una fila de ejemplo. Este hook los
 * reemplaza por GET /api/reports/summary.
 *
 * El estado vive fuera de la función a propósito: es un singleton, así que si
 * dos componentes lo usan comparten la misma carga en vez de pedir lo mismo
 * dos veces.
 */

export type CityStat = {
    city: string
    label: string
    clients: number
    tickets: number
    percentage: number
}

export type ChannelStat = {
    channel: 'whatsapp' | 'instagram' | 'facebook'
    label: string
    contacts: number
    percentage: number
}

export type DashboardTotals = {
    contacts: number
    open_conversations: number
    tickets: number
    urgent_tickets: number
    unassigned_tickets: number
}

export type ActivityItem = {
    id: number
    action: string
    description: string
    actor: string | null
    actor_avatar: string | null
    /** true = lo hizo una automatización (un Flow), no una persona. */
    automatic: boolean
    created_at: string
}

/** Las claves son las etiquetas del enum CaseStatus. */
type StatusCounts = Record<string, number>

const byStatus = ref<StatusCounts>({})
const byCity = ref<CityStat[]>([])
const byChannel = ref<ChannelStat[]>([])
const totals = ref<DashboardTotals>({
    contacts: 0,
    open_conversations: 0,
    tickets: 0,
    urgent_tickets: 0,
    unassigned_tickets: 0,
})
const activity = ref<ActivityItem[]>([])

const loading = ref(false)
const error = ref<string | null>(null)

export function useDashboard() {
    /** Cuenta de un estado concreto, 0 si el backend no lo devolvió. */
    const countFor = (status: CaseStatus): number => byStatus.value[status] ?? 0

    const loadSummary = async () => {
        loading.value = true
        error.value = null

        try {
            const { data } = await api.get('/reports/summary')

            byStatus.value = data.by_status ?? {}
            byCity.value = data.by_city ?? []
            byChannel.value = data.by_channel ?? []
            if (data.totals) totals.value = data.totals
        } catch (e) {
            error.value = 'No se pudieron cargar las métricas del panel'
        } finally {
            loading.value = false
        }
    }

    const loadActivity = async () => {
        try {
            const { data } = await api.get('/reports/activity')
            activity.value = data ?? []
        } catch {
            // La actividad es secundaria: si falla, el resto del panel sigue
            // siendo útil. No se pisa el error principal.
            activity.value = []
        }
    }

    return {
        byStatus,
        byCity,
        byChannel,
        totals,
        activity,
        loading,
        error,
        countFor,
        loadSummary,
        loadActivity,
    }
}
