<script setup lang="ts">
    import { ref, computed, onMounted } from 'vue';
    import { useRouter } from 'vue-router';
    import api from '@/lib/axios';
    import User from '../../icons/icon.user.vue'
    import DataTable from 'datatables.net-vue3';
    import DataTablesCore from 'datatables.net-dt';
    import 'datatables.net-fixedheader-dt';
    import 'datatables.net-responsive-dt';

    DataTable.use(DataTablesCore)

    /**
     * Tickets urgentes reales, desde GET /api/tickets?status=alta_prioridad.
     *
     * Antes había una sola fila escrita a mano ('Nombre cliente', '#RE-0000',
     * 'Problema del cliente') que parecía un dato real en la demo.
     */

    type UrgentRow = {
        ticketId: number
        conversationId: number | null
        client: string
        ref: string
        interest: string
        wait: string
        assigned: string | null
        status: string
    }

    const router = useRouter()
    const rows = ref<UrgentRow[]>([])
    const loading = ref(true)

    /** Tiempo transcurrido desde el último movimiento del ticket. */
    const waitLabel = (iso: string | null): string => {
        if (!iso) return '—'

        const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
        if (mins < 60) return `${mins} min`

        const hours = Math.floor(mins / 60)
        if (hours < 24) return `${hours} h`

        return `${Math.floor(hours / 24)} d`
    }

    const load = async () => {
        loading.value = true

        try {
            const { data } = await api.get('/tickets', { params: { status: 'alta_prioridad' } })

            rows.value = (data ?? []).map((t: any): UrgentRow => ({
                ticketId: t.id,
                conversationId: t.conversation_id ?? null,
                client: t.contact?.display_name ?? 'Sin nombre',
                // Referencia legible con el id real, en el formato de la maqueta.
                ref: `#RE-${String(t.id).padStart(4, '0')}`,
                interest: t.course_interest || t.contact?.city || 'Sin especificar',
                wait: waitLabel(t.updated_at),
                assigned: t.assigned_user?.name ?? null,
                status: t.status_label ?? 'Urgente',
            }))
        } catch {
            rows.value = []
        } finally {
            loading.value = false
        }
    }

    onMounted(load)

    const columns = [
        { data: 'client',   title: 'Cliente',  render: '#client' },
        { data: 'interest', title: 'Interés' },
        { data: 'wait',     title: 'Espera' },
        { data: 'assigned', title: 'Asignado', render: '#assigned' },
        { data: 'status',   title: 'Estado',   render: '#status' },
        { data: null,       title: 'Acción',   render: '#action' },
    ]

    // DataTables necesita un array estable; se pasa el ref ya resuelto.
    const data = computed(() => rows.value)

    /** Abre la bandeja para atender el caso. */
    const attend = (row: UrgentRow) => {
        router.push({ name: 'Messages Home' })
    }
</script>

<template>
    <div class="overflow-x-auto">
        <!-- Cargando -->
        <div v-if="loading" class="flex flex-col gap-2 py-2">
            <div v-for="n in 3" :key="n" class="h-12 bg-primary/5 rounded-xl animate-pulse"></div>
        </div>

        <!-- Sin urgentes: es una buena noticia, se dice así -->
        <div v-else-if="rows.length === 0" class="py-10 text-center">
            <p class="text-sm text-primary/50">No hay tickets urgentes ahora mismo.</p>
            <p class="text-[11px] text-primary/35 mt-1">Los casos marcados como urgentes aparecerán acá.</p>
        </div>

        <DataTable
            v-else
            id="urgent-table"
            :data="data"
            :columns="columns"
            :options="{ paging: false, searching: false, info: false, ordering: false, responsive: false }"
            class="w-full"
        >
            <template #client="{ rowData }">
                <div class="flex items-center gap-3 py-1">
                    <div class="w-8 h-8 rounded-full bg-accent/20 flex items-center justify-center text-primary shrink-0">
                        <User class="text-lg translate-y-0.5" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-primary leading-tight">{{ rowData.client }}</p>
                        <p class="text-[11px] text-primary/40 font-mono">{{ rowData.ref }}</p>
                    </div>
                </div>
            </template>

            <template #assigned="{ rowData }">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-secondary/30 flex items-center justify-center text-[10px] font-bold text-primary shrink-0">
                        {{ rowData.assigned ? rowData.assigned.charAt(0).toUpperCase() : '?' }}
                    </div>
                    <span class="text-sm text-primary/70">{{ rowData.assigned || 'Sin asignar' }}</span>
                </div>
            </template>

            <template #status="{ rowData }">
                <span class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-red-50 text-red-600 border border-red-100 whitespace-nowrap">
                    {{ rowData.status }}
                </span>
            </template>

            <template #action="{ rowData }">
                <button
                    class="text-[11px] font-bold uppercase tracking-widest px-4 py-1.5 rounded-lg border-2 border-secondary text-primary hover:bg-primary hover:border-primary hover:text-white transition-all cursor-pointer"
                    @click="attend(rowData)"
                >
                    Atender
                </button>
            </template>
        </DataTable>
    </div>
</template>
