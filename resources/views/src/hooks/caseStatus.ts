import { computed, ref } from 'vue'

export enum CaseStatus {
  New = 'Nuevo',
  Interested = 'Interesado',
  HighPriority = 'Urgente',
  Following = 'En seguimiento',
  Reserved = 'Reservado',
  Closed = 'Cerrado',
}

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
