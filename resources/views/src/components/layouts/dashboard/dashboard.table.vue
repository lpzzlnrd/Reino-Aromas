<script setup lang="ts">
    import { CaseStatus, useCaseStatus } from '@/hooks/caseStatus.ts';

    import User from '../../icons/icon.user.vue'

    import DataTable from 'datatables.net-vue3';
    import DataTablesCore from 'datatables.net-dt';
    import 'datatables.net-fixedheader-dt';
    import 'datatables.net-responsive-dt';

    DataTable.use(DataTablesCore)

    // Delete this in case of using Ajax Data
    const data = [
        {
            client: 'Nombre cliente',
            id: '#RE-0000',
            problem: 'Problema del cliente',
            wait: '0 min',
            assigned: 'Agente asignado',
            status: CaseStatus.HighPriority
        },
    ]

    const columns = [
        { data: 'client', title: 'Cliente', render: '#client' },
        { data: 'problem', title: 'Problema' },
        { data: 'wait', title: 'Tiempo de espera' },
        { data: 'assigned', title: 'Asignado', render: '#assigned' },
        { data: null, title: 'Acción', render: '#action' },
    ]
</script>

<template>
    <div class="scroll overflow-x-auto">
        <!-- Ajax Data Import Example -->
        <!--
            <DataTable
                :columns="columns"
                ajax="/data.json"
                class="display"
            />
         -->
        <DataTable
            id="urgent-table"
            :data="data"
            :columns="columns"
            :options="{
                paging: false,
                searching: false,
                info: false,
                ordering: false,
                responsive: false,
            }"
        >
            <!-- Client Column -->
            <template #client="{ rowData }">
                <div id="cell-client" class="flex flex-row items-center gap-2">
                    <section id="avatar">
                        <User id="user-avatar" />
                    </section>
                    <div id="client-meta">
                        <section id="client-name" class="font-secondary">{{ rowData.client }}</section>
                        <section id="client-id" class="font-secondary">{{ rowData.id }}</section>
                    </div>
                </div>
            </template>

            <!-- Agent Assigned Column -->
            <template #assigned="{ rowData }">
                <div id="cell-assigned" class="flex flex-row gap-1">
                    <div id="assigned-avatar">
                        <img v-if="rowData.assignedAvatar" :src="rowData.assignedAvatar" class="rounded-full w-6 h-6" />
                        <div v-else class="rounded-full w-6 h-6 bg-accent flex items-center justify-center txt-small">
                            {{ rowData.assigned ? rowData.assigned.charAt(0) : '' }}
                        </div>
                    </div>
                    <div class="assigned-name txt-small">
                        {{ rowData.assigned || 'Sin asignar' }}
                    </div>
                </div>
            </template>

            <!-- Action/Status Column -->
            <template #action="{ rowData }">
                <div class="cell-action">
                    <button id="btn-attend" class="py-2 px-4 border-2 border-secondary bg-background hover:cursor-pointer hover:bg-primary hover:border-primary hover:text-secondary rounded-2xl" @click="$emit('attend', rowData)">
                        ATENDER
                    </button>
                </div>
            </template>
        </DataTable>
    </div>
</template>

<style>
</style>
