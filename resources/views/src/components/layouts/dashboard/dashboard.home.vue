<script setup lang="ts">
    import Header from '../header/header.vue'
    import Relevant_Data from './dashboard.relevant.vue'
    import Recents from './dashboard.recents.vue'
    import Cities from './dashboard.cities.vue'
    import Urgent from './dashboard.urgent.vue'

    const today = new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">

        <Header class="mb-8" />

        <!-- Bienvenida -->
        <section class="mb-8 px-1">
            <h1 class="text-3xl lg:text-4xl font-primary text-primary mb-1">¡Hola de nuevo!</h1>
            <p class="text-sm text-secondary font-medium capitalize opacity-80">{{ today }}</p>
        </section>

        <!-- KPIs -->
        <section class="mb-8">
            <Relevant_Data />
        </section>

        <!-- Actividad + ciudades -->
        <div class="grid grid-cols-12 gap-5 mb-8">
            <div class="col-span-12 lg:col-span-8">
                <Recents class="h-full" />
            </div>
            <div class="col-span-12 lg:col-span-4">
                <Cities class="h-full" />
            </div>
        </div>

        <!-- Urgentes -->
        <Urgent />

    </div>
</template>
