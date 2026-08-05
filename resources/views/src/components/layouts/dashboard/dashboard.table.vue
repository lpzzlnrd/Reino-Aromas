<script setup lang="ts">
    import { CaseStatus } from '@/hooks/caseStatus.ts';
    import User from '../../icons/icon.user.vue'
    import DataTable from 'datatables.net-vue3';
    import DataTablesCore from 'datatables.net-dt';
    import 'datatables.net-fixedheader-dt';
    import 'datatables.net-responsive-dt';

    DataTable.use(DataTablesCore)

    const data = [
        { client: 'Nombre cliente', id: '#RE-0000', problem: 'Problema del cliente', wait: '0 min', assigned: 'Agente asignado', status: CaseStatus.HighPriority },
    ]

    const columns = [
        { data: 'client',  title: 'Cliente',           render: '#client' },
        { data: 'problem', title: 'Problema' },
        { data: 'wait',    title: 'Espera' },
        { data: 'assigned',title: 'Asignado',           render: '#assigned' },
        { data: 'status',  title: 'Estado',             render: '#status' },
        { data: null,      title: 'Acción',             render: '#action' },
    ]
</script>

<template>
    <div class="overflow-x-auto">
        <DataTable
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
                        <p class="text-[11px] text-primary/40 font-mono">{{ rowData.id }}</p>
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
                    @click="$emit('attend', rowData)"
                >
                    Atender
                </button>
            </template>
        </DataTable>
    </div>
</template>
