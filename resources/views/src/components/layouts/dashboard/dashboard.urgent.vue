<script setup lang="ts">
    import Alert from '../../icons/icon.alert.vue';
    import DataTable from './dashboard.table.vue'
</script>

<template>
    <div class="glass-card overflow-hidden">
        <header class="flex items-center justify-between px-6 py-4 border-b border-primary/8">
            <h2 class="text-xl font-primary text-primary">Tickets urgentes</h2>
            <span class="flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 border border-red-100 px-3 py-1.5 rounded-full">
                <Alert class="text-sm" />
                Atención inmediata
            </span>
        </header>
        <div class="p-4">
            <DataTable />
        </div>
    </div>
</template>
