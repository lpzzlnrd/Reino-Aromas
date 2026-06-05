<script setup lang="ts">
    import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
    import { faTriangleExclamation } from '@fortawesome/free-solid-svg-icons'
    import DataTable from './dashboard.table.vue'
</script>

<template>
    <div id="urgent-div" class="glass-card p-6 lg:p-8">
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-2xl font-primary text-primary mb-1">Tickets de Atención</h2>
                <p class="text-xs font-bold text-secondary uppercase tracking-widest opacity-60">Seguimiento de prioridades</p>
            </div>
            <section class="flex items-center gap-3 px-4 py-2 rounded-xl bg-red-50 text-red-700 border border-red-100 shadow-sm animate-pulse">
                <FontAwesomeIcon :icon="faTriangleExclamation" />
                <p class="text-[11px] font-bold uppercase tracking-wider">Atención inmediata requerida</p>
            </section>
        </header>
        
        <div class="overflow-hidden">
            <DataTable />
        </div>
    </div>
</template>
