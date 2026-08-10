<script setup lang="ts">
    import { onMounted } from 'vue'
    import Header from '../header/header.vue'
    import Relevant_Data from './dashboard.relevant.vue'
    import Recents from './dashboard.recents.vue'
    import Cities from './dashboard.cities.vue'
    import Urgent from './dashboard.urgent.vue'
    import { useAuth } from '@/composables/useAuth'
    import { useDashboard } from '@/hooks/useDashboard'

    const today = new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    const { user } = useAuth()

    // La carga se dispara UNA vez acá y no en cada componente hijo: el hook es
    // un singleton, así que todos los bloques del panel comparten la misma
    // respuesta de /reports/summary en vez de pedirla por separado.
    const { totals, error, loadSummary, loadActivity } = useDashboard()

    onMounted(() => {
        loadSummary()
        loadActivity()
    })
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">

        <Header class="mb-8" />

        <!-- Bienvenida -->
        <section class="mb-8 px-1">
            <h1 class="text-3xl lg:text-4xl font-primary text-primary mb-1">
                ¡Hola{{ user?.name ? ` ${user.name.split(' ')[0]}` : '' }}!
            </h1>
            <p class="text-sm text-secondary font-medium capitalize opacity-80">{{ today }}</p>

            <!-- Resumen en una línea, para tener el pulso sin leer las tarjetas -->
            <p v-if="!error" class="text-xs text-primary/50 mt-2">
                {{ totals.contacts }} {{ totals.contacts === 1 ? 'cliente' : 'clientes' }} ·
                {{ totals.open_conversations }} {{ totals.open_conversations === 1 ? 'chat abierto' : 'chats abiertos' }}
                <span v-if="totals.unassigned_tickets > 0" class="text-red-600 font-semibold">
                    · {{ totals.unassigned_tickets }} sin asignar
                </span>
            </p>
        </section>

        <!-- Si el backend falla se avisa, en vez de mostrar ceros como si fueran datos -->
        <div v-if="error" class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
            {{ error }}
        </div>

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
