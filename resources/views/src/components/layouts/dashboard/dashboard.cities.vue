<script setup lang="ts">
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

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

    function reduce_bar(city: City, step = 2){
        const next = currentPercentage.value[city] - step
        currentPercentage.value[city] = Math.max(next, 0)
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
        }, 20)
    }

    onMounted(() => {
        startBars()
    })

    onBeforeUnmount(() => {
        if (intervalId !== undefined) clearInterval(intervalId)
    })
</script>

<template>
    <div class="p-2 w-full lg:w-2/3">
        <div id="cities-div" class="bg-background border-2 border-title rounded-md p-2 h-full flex flex-col justify-between shadow-xl">
            <header class="border-b-2 border-secondary p-1">
                <p class="subtitle">Distribucion por ciudad</p>
            </header>

            <div id="states" class="flex flex-col gap-4">
                <div id="caracas-div">
                    <section id="caracas-section" class="txt flex flex-row justify-between">
                        <p>Caracas</p>
                        <p id="caracas-percentage">{{ currentPercentage.caracas.toFixed(2) }}%</p>
                    </section>
                    <div class="w-full bg-background border-2 border-primary rounded-full h-4 overflow-hidden">
                        <section
                            id="caracas-percentage-bar"
                            class="w-full bg-linear-to-r from-primary via-accent-hover to-secondary h-full transition-all duration-500 ease-out"
                            :style="{ width: `${currentPercentage.caracas}%`}"
                        ></section>
                    </div>
                </div>

                <div id="valencia-div">
                    <section id="valencia-section" class="txt flex flex-row justify-between">
                        <p>Valencia</p>
                        <p id="valencia-percentage">{{ currentPercentage.valencia.toFixed(2) }}%</p>
                    </section>
                    <div class="w-full bg-background border-2 border-primary rounded-full h-4 overflow-hidden">
                        <section
                            id="valencia-percentage-bar"
                            class="w-full bg-linear-to-r from-primary via-accent-hover to-secondary h-full transition-all duration-500 ease-out"
                            :style="{ width: `${currentPercentage.valencia}%`}"
                        ></section>
                    </div>
                </div>

                <div id="barquisimeto-div">
                    <section id="barquisimeto-section" class="txt flex flex-row justify-between">
                        <p>Barquisimeto</p>
                        <p id="barquisimeto-percentage">{{ currentPercentage.barquisimeto.toFixed(2) }}%</p>
                    </section>
                    <div class="w-full bg-background border-2 border-primary rounded-full h-4 overflow-hidden">
                        <section
                            id="barquisimeto-percentage-bar"
                            class="w-full bg-linear-to-r from-primary via-accent-hover to-secondary h-full transition-all duration-500 ease-out"
                            :style="{ width: `${currentPercentage.barquisimeto}%`}"
                        ></section>
                    </div>
                </div>

            </div>

        </div>
    </div>
</template>
