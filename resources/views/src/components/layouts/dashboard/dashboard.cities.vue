<script setup lang="ts">
    import { ref, watch, onBeforeUnmount } from 'vue'
    import { useDashboard } from '@/hooks/useDashboard'

    // Los clientes por ciudad salen de GET /api/reports/summary. Antes estaban
    // escritos a mano (caracas: 400, valencia: 205, barquisimeto: 182) y solo
    // había 3 de las 5 sedes: Maracay y Margarita no aparecían nunca.
    const { byCity, loading } = useDashboard()

    let intervalId: number | undefined

    // Ancho animado de cada barra, indexado por slug de ciudad.
    const currentPercentage = ref<Record<string, number>>({})

    // Se conserva el degradado por posición y no por ciudad: el orden lo decide
    // el backend (mayor primero), así que la sede más activa siempre lleva el
    // color principal.
    const barColors = [
        'from-primary to-secondary',
        'from-secondary to-accent-hover',
        'from-accent-hover to-pink-400',
        'from-pink-400 to-fuchsia-400',
        'from-fuchsia-400 to-violet-400',
    ]

    const stopBars = () => {
        if (intervalId !== undefined) {
            clearInterval(intervalId)
            intervalId = undefined
        }
    }

    const startBars = () => {
        stopBars()

        // Arranca en cero para que la animación se vea al cargar los datos.
        currentPercentage.value = Object.fromEntries(
            byCity.value.map((c) => [c.city, 0]),
        )

        intervalId = window.setInterval(() => {
            let finished = true

            for (const stat of byCity.value) {
                // El ?? 0 cubre el caso de que byCity cambie a mitad de la
                // animación y aparezca una ciudad sin entrada previa.
                const actual = currentPercentage.value[stat.city] ?? 0
                const next = Math.min(actual + 2, stat.percentage)

                currentPercentage.value[stat.city] = next
                if (next < stat.percentage) finished = false
            }

            if (finished) stopBars()
        }, 20)
    }

    // Se dispara al llegar los datos, no en onMounted: el fetch lo lanza
    // dashboard.home.vue y puede resolverse después de montar este componente.
    watch(byCity, startBars, { immediate: true })

    onBeforeUnmount(stopBars)
</script>

<template>
    <div class="glass-card h-full p-6 flex flex-col gap-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xl font-primary text-primary">Por ciudad</h2>
            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">Clientes registrados</span>
        </header>

        <!-- Cargando -->
        <div v-if="loading" class="flex flex-col gap-5 flex-1 justify-center">
            <div v-for="n in 5" :key="n" class="flex flex-col gap-1.5">
                <div class="h-4 w-24 bg-primary/8 rounded animate-pulse"></div>
                <div class="w-full bg-primary/8 rounded-full h-2.5 animate-pulse"></div>
            </div>
        </div>

        <!-- Sin datos todavía -->
        <div v-else-if="byCity.length === 0" class="flex-1 flex items-center justify-center text-center">
            <p class="text-sm text-primary/40">Aún no hay clientes registrados.</p>
        </div>

        <div v-else class="flex flex-col gap-5 flex-1 justify-center">
            <div v-for="(stat, i) in byCity" :key="stat.city" class="flex flex-col gap-1.5">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-semibold text-primary/80">{{ stat.label }}</span>
                    <span class="text-xs font-bold text-primary/50 tabular-nums">
                        {{ (currentPercentage[stat.city] ?? 0).toFixed(1) }}%
                    </span>
                </div>
                <div class="w-full bg-primary/8 rounded-full h-2.5 overflow-hidden">
                    <div
                        :class="`h-full bg-gradient-to-r ${barColors[i % barColors.length]} rounded-full transition-all duration-300`"
                        :style="{ width: `${currentPercentage[stat.city] ?? 0}%` }"
                    ></div>
                </div>
                <p class="text-[11px] text-primary/40">
                    {{ stat.clients }} {{ stat.clients === 1 ? 'cliente' : 'clientes' }}
                    <span v-if="stat.tickets > 0"> · {{ stat.tickets }} {{ stat.tickets === 1 ? 'ticket' : 'tickets' }}</span>
                </p>
            </div>
        </div>
    </div>
</template>
