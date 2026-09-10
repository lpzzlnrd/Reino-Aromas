<script setup lang="ts">
    import { computed } from 'vue'
    import { useDashboard } from '@/hooks/useDashboard'

    /**
     * Distribución de clientes por estado del país.
     *
     * Es un corte distinto del de sedes (dashboard.cities.vue), no un
     * reemplazo: `city` dice a qué sede pertenece el cliente — dónde toma el
     * curso — y `state` de dónde escribe. Un cliente de Táchira atendido desde
     * la sede de Valencia cuenta en las dos vistas, y las dos son ciertas.
     *
     * A diferencia de las sedes, el backend NO devuelve los 24 estados con
     * cero: solo los que tienen clientes, más una fila agregada de "sin
     * determinar". Por eso aquí no hay animación por slug como en cities
     * — la lista cambia de largo entre cargas.
     */
    const { byState, loading } = useDashboard()

    /** Cuántos estados se listan antes de agrupar el resto en "otros". */
    const VISIBLES = 6

    /**
     * La fila de pendientes va aparte del ranking.
     *
     * Mezclarla haría que "Sin determinar" compitiera por el primer puesto:
     * al principio del CRM es siempre el número más alto, y taparía a los
     * estados reales justo cuando son lo que se quiere ver.
     */
    const sinDeterminar = computed(() => byState.value.find((s) => s.state === null) ?? null)

    const conEstado = computed(() => byState.value.filter((s) => s.state !== null))

    const listados = computed(() => conEstado.value.slice(0, VISIBLES))

    /**
     * Los que no entran en el top se suman en una sola fila.
     *
     * Venezuela tiene 24 estados: sin esto, un CRM con clientes repartidos
     * dibujaría veinte barras de 1% y el bloque perdería su altura fija.
     */
    const resto = computed(() => {
        const sobrantes = conEstado.value.slice(VISIBLES)

        if (sobrantes.length === 0) return null

        return {
            estados: sobrantes.length,
            clients: sobrantes.reduce((suma, s) => suma + s.clients, 0),
            percentage: sobrantes.reduce((suma, s) => suma + s.percentage, 0),
        }
    })

    /** Cuántos clientes ya están clasificados, para el pie del bloque. */
    const clasificados = computed(() =>
        conEstado.value.reduce((suma, s) => suma + s.clients, 0),
    )

    // El degradado va por posición, igual que en sedes y cursos: el estado con
    // más clientes lleva siempre el color principal.
    const barColors = [
        'from-primary to-secondary',
        'from-secondary to-accent-hover',
        'from-accent-hover to-pink-400',
        'from-pink-400 to-fuchsia-400',
        'from-fuchsia-400 to-violet-400',
        'from-violet-400 to-indigo-400',
    ]
</script>

<template>
    <div class="glass-card h-full p-6 flex flex-col gap-5">
        <header class="flex items-center justify-between gap-2">
            <h2 class="text-xl font-primary text-primary">Por estado</h2>
            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70 shrink-0">
                Clientes por región
            </span>
        </header>

        <!-- Cargando -->
        <div v-if="loading" class="flex flex-col gap-4 flex-1 justify-center">
            <div v-for="n in 5" :key="n" class="flex flex-col gap-1.5">
                <div class="h-4 w-28 bg-primary/8 rounded animate-pulse"></div>
                <div class="w-full bg-primary/8 rounded-full h-2.5 animate-pulse"></div>
            </div>
        </div>

        <!-- Ningún cliente tiene estado todavía -->
        <div
            v-else-if="conEstado.length === 0"
            class="flex-1 flex flex-col items-center justify-center text-center gap-1.5 py-4"
        >
            <p class="text-sm text-primary/40">
                {{ sinDeterminar ? 'Ningún cliente tiene estado asignado.' : 'Aún no hay clientes registrados.' }}
            </p>
            <!-- Se dice DÓNDE se llena: sin esto el bloque se lee como un
                 error del sistema en vez de como trabajo pendiente. -->
            <p v-if="sinDeterminar" class="text-[11px] text-primary/35 max-w-xs leading-relaxed">
                Se asigna en el panel del chat o en la ficha del cliente.
                {{ sinDeterminar.clients }} {{ sinDeterminar.clients === 1 ? 'cliente' : 'clientes' }} por clasificar.
            </p>
        </div>

        <div v-else class="flex flex-col gap-4 flex-1">
            <div v-for="(stat, i) in listados" :key="stat.state ?? 'sin'" class="flex flex-col gap-1.5">
                <div class="flex justify-between items-center gap-2">
                    <span class="text-sm font-semibold text-primary/80 truncate">{{ stat.label }}</span>
                    <span class="text-xs font-bold text-primary/50 tabular-nums shrink-0">
                        {{ stat.clients }} · {{ stat.percentage.toFixed(1) }}%
                    </span>
                </div>
                <div class="w-full bg-primary/8 rounded-full h-2.5 overflow-hidden">
                    <div
                        :class="`h-full bg-gradient-to-r ${barColors[i % barColors.length]} rounded-full transition-all duration-500`"
                        :style="{ width: `${stat.percentage}%` }"
                    ></div>
                </div>
            </div>

            <!-- Los estados que no entran en el top, en una sola línea -->
            <p v-if="resto" class="text-[11px] text-primary/40 leading-relaxed">
                Y {{ resto.clients }} {{ resto.clients === 1 ? 'cliente' : 'clientes' }}
                en otros {{ resto.estados }} {{ resto.estados === 1 ? 'estado' : 'estados' }}
                ({{ resto.percentage.toFixed(1) }}%).
            </p>

            <!-- Pendientes al pie, no compitiendo en el ranking -->
            <p
                v-if="sinDeterminar"
                class="text-[11px] text-primary/45 leading-relaxed pt-2.5 mt-auto border-t border-primary/8"
            >
                <span class="font-semibold">{{ sinDeterminar.clients }}</span>
                {{ sinDeterminar.clients === 1 ? 'cliente' : 'clientes' }} sin estado
                ({{ sinDeterminar.percentage.toFixed(1) }}%).
                Se asigna en el panel del chat.
            </p>
            <p v-else class="text-[11px] text-primary/40 leading-relaxed pt-2.5 mt-auto border-t border-primary/8">
                Los {{ clasificados }} {{ clasificados === 1 ? 'cliente' : 'clientes' }}
                {{ clasificados === 1 ? 'tiene' : 'tienen' }} su estado asignado.
            </p>
        </div>
    </div>
</template>
