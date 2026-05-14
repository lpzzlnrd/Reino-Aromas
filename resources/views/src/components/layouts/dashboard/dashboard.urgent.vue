<script setup lang="ts">
    import Alert from '../../icons/icon.alert.vue';
    import DataTable from './dashboard.table.vue'
</script>

<template>
    <div class="p-2 w-full h-full">
        <div id="urgent-div" class="bg-background p-2 border-2 border-primary rounded-md">
            <header class="flex flex-row justify-between border-b-2 border-secondary p-1">
                <p class="subtitle">Tickets urgentes</p>
                <section class="text-red-800 flex flex-row gap-1">
                    <Alert />
                    <p>Atención inmediata requerida</p>
                </section>
            </header>
            <DataTable class="my-4" />
        </div>
    </div>
</template>
