import { computed, ref } from 'vue'

export enum CaseStatus {
  New = 'Nuevo',
  Interested = 'Interesado',
  HighPriority = 'Urgente',
  Following = 'En seguimiento',
  Reserved = 'Reservado',
  Closed = 'Cerrado',
}

/** Status crudo del ticket, tal como lo guarda la columna `status`. */
export type TicketStatus =
  | 'nuevo'
  | 'interesado'
  | 'alta_prioridad'
  | 'en_seguimiento'
  | 'reservado'
  | 'cerrado'

/**
 * El backend trabaja con el status crudo ('alta_prioridad') pero el enum del
 * front usa etiquetas ('Urgente'), que es lo que ve el agente. Estos dos mapas
 * traducen en ambas direcciones.
 *
 * Viven aquí y no en useInbox porque el Kanban necesita lo mismo: sin esto
 * cada vista se inventaría su propia tabla y una de las dos se desincronizaría.
 */
export const STATUS_POR_ETIQUETA: Record<CaseStatus, TicketStatus> = {
  [CaseStatus.New]: 'nuevo',
  [CaseStatus.Interested]: 'interesado',
  [CaseStatus.HighPriority]: 'alta_prioridad',
  [CaseStatus.Following]: 'en_seguimiento',
  [CaseStatus.Reserved]: 'reservado',
  [CaseStatus.Closed]: 'cerrado',
}

/** Inverso de STATUS_POR_ETIQUETA: del status crudo a la columna del tablero. */
export const ETIQUETA_POR_STATUS = Object.fromEntries(
  Object.entries(STATUS_POR_ETIQUETA).map(([etiqueta, status]) => [status, etiqueta]),
) as Record<TicketStatus, CaseStatus>

const casesByStatus = ref<Record<CaseStatus, number>>({
  [CaseStatus.New]: 0,
  [CaseStatus.Interested]: 0,
  [CaseStatus.HighPriority]: 0,
  [CaseStatus.Following]: 0,
  [CaseStatus.Reserved]: 0,
  [CaseStatus.Closed]: 0,
})

export function useCaseStatus() {
//   const casesByStatus = ref<Record<CaseStatus, number>>({
//     [CaseStatus.New]: 0,
//     [CaseStatus.Interested]: 0,
//     [CaseStatus.HighPriority]: 0,
//     [CaseStatus.Following]: 0,
//     [CaseStatus.Reserved]: 0,
//     [CaseStatus.Closed]: 0,
//   })

  const statuses = Object.values(CaseStatus)

  const setCounts = (counts: Partial<Record<CaseStatus, number>>) => {
    casesByStatus.value = {
      ...casesByStatus.value,
      ...counts,
    }
  }

  const total = computed(() =>
    Object.values(casesByStatus.value).reduce((sum, value) => sum + value, 0)
  )

  return {
    statuses,
    casesByStatus,
    total,
    setCounts,
  }
}
