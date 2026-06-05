<script setup lang="ts">
    import UserIcon from '../../icons/icon.user.vue'
    import DataTable from 'datatables.net-vue3';
    import DataTablesCore from 'datatables.net-dt';
    import 'datatables.net-fixedheader-dt';
    import 'datatables.net-responsive-dt';

    DataTable.use(DataTablesCore)

    // Delete this in case of using Ajax Data
    const data = [
        {
            client: 'María González',
            id: '#RE-8829',
            problem: 'Error en pago móvil',
            wait: '4 min',
            assigned: 'Luis Pérez',
            assignedAvatar: '',
            status: 'Urgente'
        },
        {
            client: 'Juan Rodriguez',
            id: '#RE-8830',
            problem: 'Consulta fragancia lavanda',
            wait: '12 min',
            assigned: 'Sofía Castro',
            assignedAvatar: '',
            status: 'Urgente'
        },
    ]

    const columns = [
        { data: 'client', title: 'Cliente', render: '#client' },
        { data: 'problem', title: 'Problema' },
        { data: 'wait', title: 'Espera' },
        { data: 'assigned', title: 'Asignado', render: '#assigned' },
        { data: null, title: 'Acción', render: '#action' },
    ]
</script>

<template>
    <div class="overflow-x-auto custom-scrollbar">
        <DataTable
            id="urgent-table"
            :data="data"
            :columns="columns"
            :options="{
                paging: false,
                searching: false,
                info: false,
                ordering: false,
                responsive: true,
                autoWidth: false
            }"
        >
            <!-- Client Column -->
            <template #client="{ rowData }">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-accent/20 flex items-center justify-center text-primary overflow-hidden border border-white/50">
                        <UserIcon class="text-3xl translate-y-1 opacity-80" />
                    </div>
                    <div>
                        <div class="font-bold text-primary text-sm leading-tight">{{ rowData.client }}</div>
                        <div class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-60">{{ rowData.id }}</div>
                    </div>
                </div>
            </template>

            <!-- Agent Assigned Column -->
            <template #assigned="{ rowData }">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center text-primary text-[10px] font-bold border border-white/50">
                        {{ rowData.assigned ? rowData.assigned.charAt(0) : '?' }}
                    </div>
                    <div class="text-xs font-medium text-primary/80">
                        {{ rowData.assigned || 'Sin asignar' }}
                    </div>
                </div>
            </template>

            <!-- Action Column -->
            <template #action="{ rowData }">
                <div class="flex justify-end">
                    <button class="btn-primary text-[10px] py-1.5 px-4 rounded-lg shadow-sm" @click="$emit('attend', rowData)">
                        ATENDER
                    </button>
                </div>
            </template>
        </DataTable>
    </div>
</template>

<style>
/* Global overrides for DataTables to match our glassy theme */
.dataTables_wrapper .dataTables_scroll {
    border-radius: 1rem;
    overflow: hidden;
}

table.dataTable {
    border-collapse: collapse !important;
}

table.dataTable thead th {
    border-bottom: 1px solid rgba(109, 18, 63, 0.1) !important;
}

table.dataTable td {
    border-bottom: 1px solid rgba(109, 18, 63, 0.05) !important;
}
</style>
