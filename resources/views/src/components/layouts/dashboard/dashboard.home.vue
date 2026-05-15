<script setup lang="ts">
    import Header from '../header/header.vue'
    import Relevant_Data from './dashboard.relevant.vue'
    import Recents from './dashboar.recents.vue'
    import Cities from './dashboard.cities.vue'
    import Urgent from './dashboard.urgent.vue'

    const today = new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <!-- Unified Header & Search -->
        <Header class="mb-8" />

        <!-- Welcome Section -->
        <section class="mb-10 px-2">
            <h1 class="text-3xl lg:text-4xl font-primary text-primary mb-2">¡Hola de nuevo!</h1>
            <p class="text-secondary font-medium opacity-80 capitalize">{{ today }}</p>
        </section>

        <!-- Main Dashboard Grid -->
        <div id="dashboard-grid" class="grid grid-cols-12 gap-6 lg:gap-8">
            
            <!-- KPI Section (Full Width) -->
            <div class="col-span-12">
                <Relevant_Data />
            </div>

            <!-- Middle Section: Activity & Distribution -->
            <div class="col-span-12 lg:col-span-8">
                <Recents />
            </div>

            <div class="col-span-12 lg:col-span-4">
                <Cities />
            </div>

            <!-- Bottom Section: Urgent Tasks -->
            <div class="col-span-12">
                <Urgent />
            </div>

        </div>
    </div>
</template>

<style scoped>
    /* Prevent horizontal overflow issues with blur effects */
    #dashboard-grid {
        contain: layout;
    }
</style>
