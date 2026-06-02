<script setup lang="ts">
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

    let intervalId: number | undefined

    type City = 'caracas' | 'valencia' | 'barquisimeto'

    const clients: Record<City, number> = {
        caracas: 400,
        valencia: 205,
        barquisimeto: 182,
    }

    const cityLabels: Record<City, string> = {
        caracas: 'Caracas',
        valencia: 'Valencia',
        barquisimeto: 'Barquisimeto',
    }

    const barColors: Record<City, string> = {
        caracas:      'from-primary to-secondary',
        valencia:     'from-secondary to-accent-hover',
        barquisimeto: 'from-accent-hover to-pink-400',
    }

    const total = computed(() => Object.values(clients).reduce((a, b) => a + b, 0))

    const targetPercentage = computed<Record<City, number>>(() => ({
        caracas:      (clients.caracas / total.value) * 100,
        valencia:     (clients.valencia / total.value) * 100,
        barquisimeto: (clients.barquisimeto / total.value) * 100,
    }))

    const currentPercentage = ref<Record<City, number>>({ caracas: 0, valencia: 0, barquisimeto: 0 })

    const cities: City[] = ['caracas', 'valencia', 'barquisimeto']

    function startBars() {
        intervalId = window.setInterval(() => {
            let finished = true
            for (const city of cities) {
                const next = Math.min(currentPercentage.value[city] + 2, targetPercentage.value[city])
                currentPercentage.value[city] = next
                if (next < targetPercentage.value[city]) finished = false
            }
            if (finished && intervalId !== undefined) clearInterval(intervalId)
        }, 20)
    }

    onMounted(startBars)
    onBeforeUnmount(() => { if (intervalId !== undefined) clearInterval(intervalId) })
</script>

<template>
    <div class="glass-card h-full p-6 flex flex-col gap-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xl font-primary text-primary">Por ciudad</h2>
            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">Clientes activos</span>
        </header>

        <div class="flex flex-col gap-5 flex-1 justify-center">
            <div v-for="city in cities" :key="city" class="flex flex-col gap-1.5">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-semibold text-primary/80">{{ cityLabels[city] }}</span>
                    <span class="text-xs font-bold text-primary/50 tabular-nums">
                        {{ currentPercentage[city].toFixed(1) }}%
                    </span>
                </div>
                <div class="w-full bg-primary/8 rounded-full h-2.5 overflow-hidden">
                    <div
                        :class="`h-full bg-gradient-to-r ${barColors[city]} rounded-full transition-all duration-300`"
                        :style="{ width: `${currentPercentage[city]}%` }"
                    ></div>
                </div>
                <p class="text-[11px] text-primary/40">{{ clients[city] }} clientes</p>
            </div>
        </div>
    </div>
</template>
