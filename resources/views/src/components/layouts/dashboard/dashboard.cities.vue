<script setup lang="ts">
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
    import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
    import { faLocationDot } from '@fortawesome/free-solid-svg-icons'

    let intervalId: number | undefined

    type City = 'caracas' | 'valencia' | 'barquisimeto'

    const clients: Record<City, number> = {
        caracas: 400,
        valencia: 205,
        barquisimeto: 182,
    }

    const total = computed(() => Object.values(clients).reduce((a, b) => a + b, 0))

    const targetPercentage = computed<Record<City, number>>(() => ({
        caracas: (clients.caracas / total.value) * 100,
        valencia: (clients.valencia / total.value) * 100,
        barquisimeto: (clients.barquisimeto / total.value) * 100,
    }))

    const currentPercentage = ref<Record<City, number>>({
        caracas: 0,
        valencia: 0,
        barquisimeto: 0,
    })

    function move_bar(city: City, step = 2){
        const next = currentPercentage.value[city] + step
        currentPercentage.value[city] = Math.min(next, targetPercentage.value[city])
    }

    function startBars() {
        intervalId = window.setInterval(() => {
            move_bar('caracas')
            move_bar('valencia')
            move_bar('barquisimeto')

            const finished =
                currentPercentage.value.caracas >= targetPercentage.value.caracas &&
                currentPercentage.value.valencia >= targetPercentage.value.valencia &&
                currentPercentage.value.barquisimeto >= targetPercentage.value.barquisimeto

            if (finished && intervalId !== undefined) {
                clearInterval(intervalId)
            }
        }, 30)
    }

    onMounted(() => {
        startBars()
    })

    onBeforeUnmount(() => {
        if (intervalId !== undefined) clearInterval(intervalId)
    })
</script>

<template>
    <div id="cities-div" class="glass-card p-6 h-full flex flex-col">
        <header class="mb-8 px-2">
            <h2 class="text-xl font-primary text-primary">Distribución</h2>
            <p class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-60">Clientes por ciudad</p>
        </header>

        <div id="states" class="flex flex-col gap-6 flex-1 justify-center">
            
            <!-- Caracas -->
            <div id="caracas-div" class="group">
                <section id="caracas-section" class="flex flex-row justify-between items-end mb-2 px-1">
                    <div class="flex items-center gap-2">
                        <FontAwesomeIcon :icon="faLocationDot" class="text-primary opacity-30 group-hover:opacity-100 transition-opacity" />
                        <span class="text-sm font-bold text-primary/80 tracking-wide">Caracas</span>
                    </div>
                    <span id="caracas-percentage" class="text-xs font-bold text-secondary">{{ currentPercentage.caracas.toFixed(1) }}%</span>
                </section>
                <div class="w-full bg-primary/5 rounded-full h-2.5 overflow-hidden">
                    <div
                        id="caracas-percentage-bar"
                        class="bar-fill h-full transition-all duration-700 ease-out"
                        :style="{ width: `${currentPercentage.caracas}%`}"
                    ></div>
                </div>
            </div>

            <!-- Valencia -->
            <div id="valencia-div" class="group">
                <section id="valencia-section" class="flex flex-row justify-between items-end mb-2 px-1">
                    <div class="flex items-center gap-2">
                        <FontAwesomeIcon :icon="faLocationDot" class="text-primary opacity-30 group-hover:opacity-100 transition-opacity" />
                        <span class="text-sm font-bold text-primary/80 tracking-wide">Valencia</span>
                    </div>
                    <span id="valencia-percentage" class="text-xs font-bold text-secondary">{{ currentPercentage.valencia.toFixed(1) }}%</span>
                </section>
                <div class="w-full bg-primary/5 rounded-full h-2.5 overflow-hidden">
                    <div
                        id="valencia-percentage-bar"
                        class="bar-fill h-full transition-all duration-700 ease-out"
                        :style="{ width: `${currentPercentage.valencia}%`}"
                    ></div>
                </div>
            </div>

            <!-- Barquisimeto -->
            <div id="barquisimeto-div" class="group">
                <section id="barquisimeto-section" class="flex flex-row justify-between items-end mb-2 px-1">
                    <div class="flex items-center gap-2">
                        <FontAwesomeIcon :icon="faLocationDot" class="text-primary opacity-30 group-hover:opacity-100 transition-opacity" />
                        <span class="text-sm font-bold text-primary/80 tracking-wide">Barquisimeto</span>
                    </div>
                    <span id="barquisimeto-percentage" class="text-xs font-bold text-secondary">{{ currentPercentage.barquisimeto.toFixed(1) }}%</span>
                </section>
                <div class="w-full bg-primary/5 rounded-full h-2.5 overflow-hidden">
                    <div
                        id="barquisimeto-percentage-bar"
                        class="bar-fill h-full transition-all duration-700 ease-out"
                        :style="{ width: `${currentPercentage.barquisimeto}%`}"
                    ></div>
                </div>
            </div>

        </div>

        <div class="mt-8 pt-6 border-t border-primary/5 text-center">
            <p class="text-[10px] text-primary/40 font-bold uppercase tracking-widest">Total Clientes: {{ total }}</p>
        </div>
    </div>
</template>
