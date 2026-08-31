<script setup lang="ts">
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
    import Header from '../header/header.vue'
    import { useDashboard } from '@/hooks/useDashboard'
    import { CaseStatus } from '@/hooks/caseStatus'

    import Chart from '../../icons/icon.chart.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'

    /**
     * Vista de Reportes.
     *
     * No repite el dashboard: el panel responde "¿qué tengo que atender hoy?" y
     * esto responde "¿cómo va el negocio?". De ahí que lo que manda sea el
     * filtro por periodo y los cortes que el panel no muestra — prioridad y,
     * sobre todo, curso de interés, que es el mayor ingreso de Reino Aromas.
     *
     * Reutiliza useDashboard() porque los dos leen /reports/summary; el hook es
     * singleton, así que al volver al panel los datos ya están cargados. La
     * contra: si esta vista deja un rango aplicado, el panel lo hereda — por
     * eso al desmontar se recarga el histórico completo.
     */

    const {
        byStatus,
        byCity,
        byChannel,
        byPriority,
        byCourse,
        totals,
        range,
        activity,
        loading,
        error,
        loadSummary,
        loadActivity,
    } = useDashboard()

    type Periodo = '7' | '30' | '90' | 'todo'

    const PERIODOS: { valor: Periodo; etiqueta: string }[] = [
        { valor: '7',    etiqueta: '7 días' },
        { valor: '30',   etiqueta: '30 días' },
        { valor: '90',   etiqueta: '90 días' },
        { valor: 'todo', etiqueta: 'Todo' },
    ]

    const periodo = ref<Periodo>('30')

    /** Fecha en YYYY-MM-DD local, que es lo que espera el backend. */
    const isoDate = (d: Date): string =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

    const paramsDelPeriodo = () => {
        if (periodo.value === 'todo') return {}

        const desde = new Date()
        desde.setDate(desde.getDate() - Number(periodo.value))

        return { from: isoDate(desde) }
    }

    const cargar = () => {
        const params = paramsDelPeriodo()
        loadSummary(params)
        loadActivity({ ...params, limit: 30 })
    }

    const cambiarPeriodo = (valor: Periodo) => {
        periodo.value = valor
        cargar()
    }

    /*
    |-------------------------------------------------------------------------
    | Métricas derivadas
    |-------------------------------------------------------------------------
    */

    /** Tickets del periodo que llegaron a reservado. */
    const reservados = computed(() => byStatus.value[CaseStatus.Reserved] ?? 0)

    /**
     * Porcentaje de tickets del periodo que terminaron reservados.
     *
     * Es la métrica que importa: de todo lo que entró, cuánto se convirtió. Se
     * calcula acá y no en el backend porque sale de dos números que ya vienen.
     */
    const tasaConversion = computed(() => {
        const total = totals.value.tickets
        return total > 0 ? (reservados.value / total) * 100 : 0
    })

    const sinAtender = computed(() => byStatus.value[CaseStatus.New] ?? 0)

    /** El canal que más contactos trajo. Null si no hay ninguno. */
    const canalPrincipal = computed(() => {
        const conContactos = byChannel.value.filter((c) => c.contacts > 0)
        if (conContactos.length === 0) return null

        return conContactos.reduce((a, b) => (b.contacts > a.contacts ? b : a))
    })

    const cursoTop = computed(() => byCourse.value[0] ?? null)

    /** Ninguna consulta devolvió nada: el periodo está vacío de verdad. */
    const sinDatos = computed(
        () => !loading.value && totals.value.tickets === 0 && totals.value.contacts === 0,
    )

    const etiquetaPeriodo = computed(
        () => PERIODOS.find((p) => p.valor === periodo.value)?.etiqueta ?? '',
    )

    /** Pie de página: qué rango se está mirando exactamente. */
    const descripcionRango = computed(() => {
        if (!range.value.from && !range.value.to) return 'Todo el histórico'

        const fmt = (iso: string) =>
            new Date(`${iso}T12:00:00`).toLocaleDateString('es-VE', {
                day: '2-digit',
                month: 'long',
                year: 'numeric',
            })

        if (range.value.from && range.value.to) {
            return `Del ${fmt(range.value.from)} al ${fmt(range.value.to)}`
        }

        return range.value.from ? `Desde el ${fmt(range.value.from)}` : `Hasta el ${fmt(range.value.to!)}`
    })

    /*
    |-------------------------------------------------------------------------
    | Presentación
    |-------------------------------------------------------------------------
    */

    const channelIcon = (canal: string) =>
        canal === 'whatsapp' ? Whatsapp : canal === 'instagram' ? Instagram : Facebook

    const channelColor = (canal: string): string =>
        canal === 'whatsapp'
            ? 'from-green-400 to-emerald-500'
            : canal === 'instagram'
                ? 'from-pink-400 to-fuchsia-500'
                : 'from-sky-400 to-blue-500'

    const priorityColor = (prioridad: string): string =>
        prioridad === 'muy_alta'
            ? 'from-red-500 to-rose-600'
            : prioridad === 'alta'
                ? 'from-orange-400 to-red-500'
                : prioridad === 'media'
                    ? 'from-amber-300 to-orange-400'
                    : 'from-stone-300 to-stone-400'

    // El degradado va por posición: el curso más pedido siempre lleva el color
    // principal, sin importar cuál sea.
    const courseColors = [
        'from-primary to-secondary',
        'from-secondary to-accent-hover',
        'from-accent-hover to-pink-400',
        'from-pink-400 to-fuchsia-400',
        'from-fuchsia-400 to-violet-400',
    ]

    const statusColor: Record<string, string> = {
        [CaseStatus.New]: 'bg-violet-400',
        [CaseStatus.Interested]: 'bg-pink-400',
        [CaseStatus.HighPriority]: 'bg-red-500',
        [CaseStatus.Following]: 'bg-sky-400',
        [CaseStatus.Reserved]: 'bg-green-500',
        [CaseStatus.Closed]: 'bg-stone-400',
    }

    const estados = computed(() =>
        Object.values(CaseStatus).map((etiqueta) => ({
            label: etiqueta,
            total: byStatus.value[etiqueta] ?? 0,
        })),
    )

    const totalEstados = computed(() =>
        estados.value.reduce((suma, e) => suma + e.total, 0),
    )

    const relativeTime = (iso: string): string => {
        const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
        if (mins < 1) return 'Hace un momento'
        if (mins < 60) return `Hace ${mins} min`

        const hours = Math.floor(mins / 60)
        if (hours < 24) return `Hace ${hours} h`

        const days = Math.floor(hours / 24)
        if (days === 1) return 'Ayer'
        if (days < 30) return `Hace ${days} días`

        return new Date(iso).toLocaleDateString('es-VE', { day: '2-digit', month: 'short', year: 'numeric' })
    }

    onMounted(cargar)

    /**
     * Al salir se restaura el histórico completo.
     *
     * El hook es singleton: sin esto, filtrar por "7 días" acá y volver al
     * panel dejaría el dashboard mostrando los números de esa semana como si
     * fueran los totales.
     */
    onBeforeUnmount(() => {
        if (periodo.value !== 'todo') {
            loadSummary()
            loadActivity()
        }
    })
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <Header class="mb-8" />

        <!-- Título y filtro de periodo -->
        <section class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6 px-1">
            <div>
                <h1 class="text-3xl font-primary text-primary">Reportes</h1>
                <p class="text-sm text-primary/50 mt-0.5">{{ descripcionRango }}</p>
            </div>

            <div class="flex gap-1.5 shrink-0">
                <button
                    v-for="p in PERIODOS"
                    :key="p.valor"
                    @click="cambiarPeriodo(p.valor)"
                    :class="[
                        'text-[11px] font-bold uppercase tracking-widest px-3 py-2 rounded-xl border transition-all cursor-pointer whitespace-nowrap',
                        periodo === p.valor
                            ? 'bg-primary text-white border-primary shadow-sm'
                            : 'bg-surface/60 text-primary/60 border-primary/15 hover:border-primary/40 hover:text-primary/80'
                    ]"
                >
                    {{ p.etiqueta }}
                </button>
            </div>
        </section>

        <div v-if="error" class="mb-6 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
            {{ error }}
        </div>

        <!-- Periodo sin datos -->
        <div v-else-if="sinDatos" class="glass-card p-12 flex flex-col items-center text-center gap-3">
            <div class="w-16 h-16 rounded-full bg-gradient-to-br from-secondary/30 to-accent/50 flex items-center justify-center">
                <Chart class="text-primary/50 text-2xl" />
            </div>
            <p class="text-lg font-primary text-primary">Sin datos en este periodo</p>
            <p class="text-sm text-primary/50 max-w-sm leading-relaxed">
                No hubo tickets ni clientes nuevos en los últimos {{ etiquetaPeriodo.toLowerCase() }}.
                Prueba con un rango más amplio.
            </p>
            <button
                v-if="periodo !== 'todo'"
                @click="cambiarPeriodo('todo')"
                class="btn-primary text-xs py-2 px-4 mt-1"
            >
                Ver todo el histórico
            </button>
        </div>

        <template v-else>
            <!-- Cifras de cabecera -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <div class="relative overflow-hidden bg-surface rounded-2xl shadow-sm border border-primary/8 p-5 flex flex-col gap-2">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-violet-400 to-primary rounded-t-2xl"></div>
                    <p class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Clientes nuevos</p>
                    <span v-if="loading" class="inline-block w-14 h-9 bg-primary/8 rounded animate-pulse"></span>
                    <p v-else class="text-4xl font-primary text-primary leading-none tabular-nums">{{ totals.contacts }}</p>
                    <p class="text-[11px] text-primary/40">en el periodo</p>
                </div>

                <div class="relative overflow-hidden bg-surface rounded-2xl shadow-sm border border-primary/8 p-5 flex flex-col gap-2">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-sky-400 to-cyan-500 rounded-t-2xl"></div>
                    <p class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Tickets</p>
                    <span v-if="loading" class="inline-block w-14 h-9 bg-primary/8 rounded animate-pulse"></span>
                    <p v-else class="text-4xl font-primary text-primary leading-none tabular-nums">{{ totals.tickets }}</p>
                    <p class="text-[11px] text-primary/40">
                        {{ totals.closed_tickets }} {{ totals.closed_tickets === 1 ? 'cerrado' : 'cerrados' }}
                    </p>
                </div>

                <div class="relative overflow-hidden bg-surface rounded-2xl shadow-sm border border-primary/8 p-5 flex flex-col gap-2">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-green-400 to-emerald-500 rounded-t-2xl"></div>
                    <p class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Reservas</p>
                    <span v-if="loading" class="inline-block w-14 h-9 bg-primary/8 rounded animate-pulse"></span>
                    <p v-else class="text-4xl font-primary text-primary leading-none tabular-nums">{{ reservados }}</p>
                    <p class="text-[11px] font-semibold text-green-600">
                        {{ tasaConversion.toFixed(1) }}% de conversión
                    </p>
                </div>

                <div class="relative overflow-hidden bg-surface rounded-2xl shadow-sm border border-primary/8 p-5 flex flex-col gap-2">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-red-400 to-rose-600 rounded-t-2xl"></div>
                    <p class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Sin atender</p>
                    <span v-if="loading" class="inline-block w-14 h-9 bg-primary/8 rounded animate-pulse"></span>
                    <p v-else class="text-4xl font-primary text-primary leading-none tabular-nums">{{ sinAtender }}</p>
                    <p class="text-[11px]" :class="totals.unassigned_tickets > 0 ? 'text-red-600 font-semibold' : 'text-primary/40'">
                        {{ totals.unassigned_tickets }} sin asignar hoy
                    </p>
                </div>
            </section>

            <!-- Cursos + prioridad -->
            <div class="grid grid-cols-12 gap-5 mb-6">
                <!-- Cursos más solicitados -->
                <div class="col-span-12 lg:col-span-7">
                    <div class="glass-card h-full p-6 flex flex-col gap-5">
                        <header class="flex items-center justify-between">
                            <h2 class="text-xl font-primary text-primary">Cursos más solicitados</h2>
                            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">
                                Por interés registrado
                            </span>
                        </header>

                        <div v-if="loading" class="flex flex-col gap-4 flex-1">
                            <div v-for="n in 4" :key="n" class="flex flex-col gap-1.5">
                                <div class="h-4 w-32 bg-primary/8 rounded animate-pulse"></div>
                                <div class="w-full bg-primary/8 rounded-full h-2.5 animate-pulse"></div>
                            </div>
                        </div>

                        <div v-else-if="byCourse.length === 0" class="flex-1 flex flex-col items-center justify-center text-center gap-1 py-6">
                            <p class="text-sm text-primary/40">Ningún ticket tiene curso de interés.</p>
                            <p class="text-[11px] text-primary/35 max-w-xs leading-relaxed">
                                El curso se rellena desde el Flow de WhatsApp o a mano en la ficha del ticket.
                            </p>
                        </div>

                        <div v-else class="flex flex-col gap-4 flex-1">
                            <div v-for="(curso, i) in byCourse" :key="curso.course" class="flex flex-col gap-1.5">
                                <div class="flex justify-between items-center gap-2">
                                    <span class="text-sm font-semibold text-primary/80 capitalize truncate">{{ curso.label }}</span>
                                    <span class="text-xs font-bold text-primary/50 tabular-nums shrink-0">
                                        {{ curso.tickets }} · {{ curso.percentage.toFixed(1) }}%
                                    </span>
                                </div>
                                <div class="w-full bg-primary/8 rounded-full h-2.5 overflow-hidden">
                                    <div
                                        :class="`h-full bg-gradient-to-r ${courseColors[i % courseColors.length]} rounded-full transition-all duration-500`"
                                        :style="{ width: `${curso.percentage}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Prioridad -->
                <div class="col-span-12 lg:col-span-5">
                    <div class="glass-card h-full p-6 flex flex-col gap-5">
                        <header class="flex items-center justify-between">
                            <h2 class="text-xl font-primary text-primary">Por prioridad</h2>
                            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">Tickets</span>
                        </header>

                        <div v-if="loading" class="flex flex-col gap-4 flex-1 justify-center">
                            <div v-for="n in 4" :key="n" class="h-10 bg-primary/8 rounded-xl animate-pulse"></div>
                        </div>

                        <div v-else class="flex flex-col gap-3 flex-1 justify-center">
                            <div v-for="p in byPriority" :key="p.priority" class="flex items-center gap-3">
                                <span class="text-xs font-semibold text-primary/70 w-16 shrink-0">{{ p.label }}</span>
                                <div class="flex-1 bg-primary/8 rounded-full h-2.5 overflow-hidden">
                                    <div
                                        :class="`h-full bg-gradient-to-r ${priorityColor(p.priority)} rounded-full transition-all duration-500`"
                                        :style="{ width: `${p.percentage}%` }"
                                    ></div>
                                </div>
                                <span class="text-xs font-bold text-primary/60 tabular-nums w-8 text-right shrink-0">
                                    {{ p.tickets }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Embudo + canales -->
            <div class="grid grid-cols-12 gap-5 mb-6">
                <!-- Embudo por estado -->
                <div class="col-span-12 lg:col-span-7">
                    <div class="glass-card h-full p-6 flex flex-col gap-5">
                        <header class="flex items-center justify-between">
                            <h2 class="text-xl font-primary text-primary">Embudo</h2>
                            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">
                                {{ totalEstados }} {{ totalEstados === 1 ? 'ticket' : 'tickets' }}
                            </span>
                        </header>

                        <div v-if="loading" class="h-32 bg-primary/8 rounded-xl animate-pulse"></div>

                        <div v-else class="flex flex-col gap-2.5 flex-1 justify-center">
                            <div v-for="e in estados" :key="e.label" class="flex items-center gap-3">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" :class="statusColor[e.label]"></span>
                                <span class="text-sm text-primary/70 flex-1 truncate">{{ e.label }}</span>
                                <div class="w-32 sm:w-40 bg-primary/8 rounded-full h-2 overflow-hidden shrink-0">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :class="statusColor[e.label]"
                                        :style="{ width: totalEstados > 0 ? `${(e.total / totalEstados) * 100}%` : '0%' }"
                                    ></div>
                                </div>
                                <span class="text-sm font-bold text-primary tabular-nums w-8 text-right shrink-0">{{ e.total }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Canales -->
                <div class="col-span-12 lg:col-span-5">
                    <div class="glass-card h-full p-6 flex flex-col gap-5">
                        <header class="flex items-center justify-between">
                            <h2 class="text-xl font-primary text-primary">Canales</h2>
                            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">Contactos</span>
                        </header>

                        <div v-if="loading" class="flex flex-col gap-4 flex-1 justify-center">
                            <div v-for="n in 3" :key="n" class="h-12 bg-primary/8 rounded-xl animate-pulse"></div>
                        </div>

                        <div v-else class="flex flex-col gap-4 flex-1 justify-center">
                            <div v-for="c in byChannel" :key="c.channel" class="flex flex-col gap-1.5">
                                <div class="flex justify-between items-center">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-primary/80">
                                        <component :is="channelIcon(c.channel)" class="text-base" />
                                        {{ c.label }}
                                    </span>
                                    <span class="text-xs font-bold text-primary/50 tabular-nums">
                                        {{ c.contacts }} · {{ c.percentage.toFixed(1) }}%
                                    </span>
                                </div>
                                <div class="w-full bg-primary/8 rounded-full h-2.5 overflow-hidden">
                                    <div
                                        :class="`h-full bg-gradient-to-r ${channelColor(c.channel)} rounded-full transition-all duration-500`"
                                        :style="{ width: `${c.percentage}%` }"
                                    ></div>
                                </div>
                            </div>

                            <p v-if="canalPrincipal" class="text-[11px] text-primary/40 leading-relaxed pt-1 border-t border-primary/8">
                                {{ canalPrincipal.label }} trae el {{ canalPrincipal.percentage.toFixed(0) }}% de los contactos.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ciudades -->
            <div class="glass-card p-6 flex flex-col gap-5 mb-6">
                <header class="flex items-center justify-between">
                    <h2 class="text-xl font-primary text-primary">Por sede</h2>
                    <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">
                        Clientes y tickets
                    </span>
                </header>

                <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                    <div v-for="n in 5" :key="n" class="h-24 bg-primary/8 rounded-xl animate-pulse"></div>
                </div>

                <div v-else class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                    <div
                        v-for="ciudad in byCity"
                        :key="ciudad.city"
                        class="px-4 py-3.5 rounded-xl border border-primary/8 bg-surface/60 flex flex-col gap-1"
                    >
                        <p class="text-xs font-bold text-primary/50 uppercase tracking-widest truncate">{{ ciudad.label }}</p>
                        <p class="text-2xl font-primary text-primary leading-none tabular-nums">{{ ciudad.clients }}</p>
                        <p class="text-[11px] text-primary/40">
                            {{ ciudad.percentage.toFixed(1) }}% ·
                            {{ ciudad.tickets }} {{ ciudad.tickets === 1 ? 'ticket' : 'tickets' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Actividad del periodo -->
            <div class="glass-card p-6 flex flex-col gap-4">
                <header class="flex items-center justify-between">
                    <h2 class="text-xl font-primary text-primary">Actividad del periodo</h2>
                    <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">
                        {{ activity.length }} {{ activity.length === 1 ? 'evento' : 'eventos' }}
                    </span>
                </header>

                <p v-if="activity.length === 0" class="text-sm text-primary/40 py-4 text-center">
                    Sin actividad registrada en este periodo.
                </p>

                <div v-else class="flex flex-col divide-y divide-primary/5 max-h-96 overflow-y-auto">
                    <div v-for="item in activity" :key="item.id" class="py-2.5 flex items-center gap-3">
                        <div
                            class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0"
                            :class="item.automatic
                                ? 'bg-secondary/15 text-secondary border border-secondary/25'
                                : 'bg-gradient-to-br from-secondary to-accent-hover text-white'"
                        >
                            <!-- Sin actor = lo hizo un Flow, no una persona. -->
                            {{ item.automatic ? '⚙' : (item.actor?.charAt(0).toUpperCase() ?? '?') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-primary/80 truncate">{{ item.description }}</p>
                            <p class="text-[11px] text-primary/40">
                                {{ item.automatic ? 'Automático' : (item.actor ?? 'Sistema') }} ·
                                {{ relativeTime(item.created_at) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pie: qué se está mirando y qué no -->
            <p class="text-[11px] text-primary/35 mt-4 px-1 leading-relaxed">
                {{ descripcionRango }}.
                <span v-if="cursoTop">
                    El curso más solicitado es {{ cursoTop.label }} ({{ cursoTop.tickets }}
                    {{ cursoTop.tickets === 1 ? 'ticket' : 'tickets' }}).
                </span>
                Los chats abiertos y los tickets sin asignar son del día de hoy, no del periodo.
            </p>
        </template>
    </div>
</template>
