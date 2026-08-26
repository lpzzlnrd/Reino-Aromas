<script setup lang="ts">
    import { onMounted, reactive, watch } from 'vue'
    import { useRouter } from 'vue-router'
    import { VueDraggable } from 'vue-draggable-plus'
    import type { SortableEvent } from 'sortablejs'
    import { CaseStatus } from '@/hooks/caseStatus'
    import { PRIORIDADES, useTickets, type BoardTicket } from '@/hooks/useTickets'
    import { useRealtime } from '@/hooks/useRealtime'
    import { useAssignableUsers } from '@/hooks/useAssignableUsers'
    import Header from '../header/header.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'

    /**
     * Tablero Kanban del embudo de ventas.
     *
     * Vivía en settings.updateStatus.vue, bajo /app/settings/status, con las
     * seis columnas maquetadas pero sin datos ni persistencia. Se movió aquí
     * porque un tablero de leads es operación diaria, no configuración — al
     * lado de Mensajería y Clientes.
     */

    const router = useRouter()

    const {
        board,
        statuses,
        filters,
        loading,
        error,
        moviendo,
        total,
        hasFilters,
        loadBoard,
        loadCounts,
        moveTicket,
        assignTicket,
        asignando,
        clearFilters,
        onTicketUpdated,
    } = useTickets()

    // La suscripción va en el componente, nunca en el hook: tiene que morir con
    // la vista o el canal quedaría abierto al salir del tablero.
    // `conectado` y no `disponible`: el segundo solo dice que el build trae
    // credenciales de Reverb, no que el socket esté vivo. Con Reverb caído la
    // insignia se quedaba en verde y el botón de Actualizar no se pintaba.
    const { conectado: enVivo, escuchar, alVolverLaConexion } = useRealtime()

    /**
     * Fin del arrastre.
     *
     * vue-draggable-plus ya movió el ticket en los arrays (el v-model de cada
     * columna) antes de llamar aquí, así que solo queda persistirlo. El id sale
     * del data-attribute del elemento arrastrado y la columna destino del
     * data-status de la lista donde cayó: `evt.to` es el DOM real, que es la
     * única fuente fiable de dónde terminó la tarjeta.
     */
    const onEnd = (evt: SortableEvent): void => {
        const id = Number(evt.item.dataset.ticketId)
        const destino = evt.to.dataset.status as CaseStatus | undefined

        if (!Number.isFinite(id) || !destino) return

        void moveTicket(id, destino)
    }

    /**
     * Abre la bandeja. No selecciona la conversación todavía: useInbox.openChat
     * pertenece a un hook que el tablero no monta, y pasarle el id por la ruta
     * requiere un parámetro que /app/messages no declara. Queda anotado.
     */
    const abrirChat = (): void => {
        router.push({ name: 'Messages Home' })
    }

    const channelIcon = (canal: string) =>
        canal === 'whatsapp' ? Whatsapp : canal === 'instagram' ? Instagram : Facebook

    /** Color del borde por prioridad: el agente escanea la columna de un golpe. */
    const priorityClass = (prioridad: BoardTicket['priority']): string =>
        prioridad === 'muy_alta'
            ? 'border-l-4 border-l-red-500'
            : prioridad === 'alta'
                ? 'border-l-4 border-l-orange-400'
                : prioridad === 'media'
                    ? 'border-l-4 border-l-amber-300'
                    : 'border-l-4 border-l-primary/15'

    const priorityLabel = (prioridad: BoardTicket['priority']): string =>
        PRIORIDADES.find((p) => p.valor === prioridad)?.etiqueta ?? prioridad

    const initials = (name: string | null): string => {
        if (!name) return '?'

        // El filter descarta los vacíos que deja un doble espacio: "Ana  Pérez"
        // producía "AU" al leer n[0] de un string vacío.
        return name
            .split(' ')
            .filter((n) => n !== '')
            .map((n) => n[0])
            .join('')
            .slice(0, 2)
            .toUpperCase()
    }

    /**
     * Ids con avatar roto: las URLs de la CDN de Meta caducan y sin esto se veía
     * el icono de imagen partida del navegador dentro del círculo.
     */
    const avataresRotos = reactive(new Set<number>())

    /* ── Asignación ──────────────────────────────────────────────────────── */

    const { activos: agentes, loadUsers } = useAssignableUsers()

    const onAssign = (id: number, event: Event): void => {
        const valor = (event.target as HTMLSelectElement).value

        void assignTicket(id, valor === '' ? null : Number(valor))
    }

    const relativeTime = (iso: string | null): string => {
        if (!iso) return ''

        const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
        if (mins < 1) return 'ahora'
        if (mins < 60) return `${mins} min`

        const hours = Math.floor(mins / 60)
        if (hours < 24) return `${hours} h`

        const days = Math.floor(hours / 24)
        if (days < 30) return `${days} d`

        return new Date(iso).toLocaleDateString('es-VE', { day: '2-digit', month: 'short' })
    }

    watch(() => [filters.value.priority, filters.value.mine], () => loadBoard())

    onMounted(() => {
        loadBoard()
        loadCounts()

        // Canal compartido a propósito: si un agente mueve una tarjeta, los
        // demás la ven moverse sin recargar.
        escuchar('tickets', {
            'ticket.updated': onTicketUpdated,
        })

        // Al volver de un corte hay que recargar el tablero: los eventos
        // emitidos durante la caida no se reencolan y las tarjetas quedarian
        // en columnas que el servidor ya no tiene.
        alVolverLaConexion(() => {
            loadBoard()
            loadCounts()
        })

        // Los agentes para el selector de cada tarjeta.
        void loadUsers()
    })
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <Header class="mb-8" />

        <!-- Título -->
        <section class="flex items-start justify-between mb-6 px-1 gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl font-primary text-primary">Tablero</h1>
                <p class="text-sm text-primary/50 mt-0.5">
                    {{ total }} {{ total === 1 ? 'caso en el embudo' : 'casos en el embudo' }}
                </p>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <label class="flex items-center gap-1.5 cursor-pointer select-none">
                    <input v-model="filters.mine" type="checkbox" class="accent-primary cursor-pointer">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-primary/50">Asignados a mí</span>
                </label>

                <select v-model="filters.priority" class="input-group text-sm py-2 px-3 cursor-pointer">
                    <option value="">Toda prioridad</option>
                    <option v-for="p in PRIORIDADES" :key="p.valor" :value="p.valor">{{ p.etiqueta }}</option>
                </select>

                <!-- Sin esto el agente no sabe si el tablero está al día. -->
                <span
                    v-if="enVivo"
                    class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest text-green-600"
                    title="El tablero se actualiza solo"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                    En vivo
                </span>
                <button
                    v-else
                    @click="loadBoard(); loadCounts()"
                    class="text-[10px] font-bold uppercase tracking-widest text-primary/40 hover:text-primary transition-colors cursor-pointer"
                    title="El tiempo real no está disponible: actualiza a mano"
                >
                    Actualizar
                </button>

                <button
                    v-if="hasFilters"
                    @click="clearFilters(); loadBoard()"
                    class="text-[10px] font-bold uppercase tracking-widest text-primary/40 hover:text-primary transition-colors cursor-pointer"
                >
                    Limpiar
                </button>
            </div>
        </section>

        <!-- role=alert para que un lector de pantalla lo anuncie, y un boton
             de reintento: antes el bloque era un callejon sin salida y con
             Reverb configurado el boton "Actualizar" quedaba oculto por el
             v-else, asi que no habia forma de recargar sin refrescar el
             navegador. -->
        <div
            v-if="error"
            role="alert"
            class="mb-5 px-4 py-3 rounded-xl bg-red-50 border border-red-100 flex items-start justify-between gap-3"
        >
            <p class="text-sm text-red-700 leading-relaxed">{{ error }}</p>
            <button
                type="button"
                @click="loadBoard(); loadCounts()"
                class="text-[10px] font-bold uppercase tracking-widest text-red-700 hover:text-red-900 cursor-pointer shrink-0 underline"
            >
                Reintentar
            </button>
        </div>

        <p v-if="loading" class="px-1 py-12 text-center text-sm text-primary/40">
            Cargando tablero...
        </p>

        <p v-else-if="total === 0" class="px-1 py-12 text-center text-sm text-primary/40">
            {{ hasFilters
                ? 'Ningún caso coincide con los filtros.'
                : 'Todavía no hay casos. Se crean solos cuando alguien escribe por WhatsApp, Instagram o Facebook.' }}
        </p>

        <!-- Columnas. Se mantienen montadas mientras carga para no perder el
             scroll horizontal en cada refresco. -->
        <div v-show="!loading && total > 0" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section
                v-for="status in statuses"
                :key="status"
                class="glass-card p-4 flex flex-col gap-3"
            >
                <header class="flex items-center justify-between">
                    <h3 class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                        {{ status }}
                    </h3>
                    <span class="text-[10px] font-bold text-primary/40 bg-primary/5 px-2 py-0.5 rounded-full">
                        {{ board[status].length }}
                    </span>
                </header>

                <!-- El data-status lo lee onEnd para saber en qué columna cayó
                     la tarjeta. -->
                <VueDraggable
                    v-model="board[status]"
                    :data-status="status"
                    :group="{ name: 'tickets', pull: true, put: true }"
                    :sort="false"
                    :animation="150"
                    ghost-class="opacity-40"
                    drag-class="rotate-1"
                    class="flex flex-col gap-2 min-h-24"
                    @end="onEnd"
                >
                    <!-- Slot por defecto: vue-draggable-plus NO tiene slot
                         #item (eso es del paquete vuedraggable viejo). Con
                         #item las tarjetas no se renderizaban. -->
                    <article
                        v-for="ticket in board[status]"
                        :key="ticket.id"
                        :data-ticket-id="ticket.id"
                        @click="abrirChat"
                        @keydown.enter.prevent="abrirChat"
                        @keydown.space.prevent="abrirChat"
                        tabindex="0"
                        role="button"
                        :aria-label="`Caso de ${ticket.contact?.display_name || 'contacto sin nombre'}, ${status}, prioridad ${priorityLabel(ticket.priority)}. Abrir la conversación.`"
                        :aria-busy="moviendo === ticket.id"
                        :class="[
                            'rounded-xl bg-white/80 border border-primary/8 p-3 cursor-grab active:cursor-grabbing hover:bg-white transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50',
                            priorityClass(ticket.priority),
                            moviendo === ticket.id ? 'opacity-50 pointer-events-none' : ''
                        ]"
                    >
                        <!-- Contacto -->
                        <div class="flex items-center gap-2 mb-1.5">
                            <!-- alt vacío: el nombre ya va en texto al lado, así
                                 que anunciarlo dos veces sobra. Las URLs de la
                                 CDN de Meta caducan, de ahí el @error. -->
                            <img
                                v-if="ticket.contact?.profile_picture_url && !avataresRotos.has(ticket.id)"
                                :src="ticket.contact.profile_picture_url"
                                alt=""
                                loading="lazy"
                                width="28"
                                height="28"
                                @error="avataresRotos.add(ticket.id)"
                                class="w-7 h-7 rounded-full object-cover shrink-0"
                            >
                            <div
                                v-else
                                class="w-7 h-7 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary text-[10px] font-bold shrink-0"
                            >
                                {{ initials(ticket.contact?.display_name ?? null) }}
                            </div>

                            <p class="text-sm font-semibold text-primary leading-tight truncate flex-1 min-w-0">
                                {{ ticket.contact?.display_name || 'Sin nombre' }}
                            </p>

                            <!-- El :is se evalúa junto al v-if, así que sin el
                                 ?. un contacto null revienta el render de toda
                                 la tarjeta. -->
                            <component
                                v-if="ticket.contact"
                                :is="channelIcon(ticket.contact?.channel)"
                                aria-hidden="true"
                                class="text-xs shrink-0 text-primary/40"
                            />
                        </div>

                        <p v-if="ticket.course_interest" class="text-[11px] text-primary/60 truncate mb-1">
                            {{ ticket.course_interest }}
                        </p>

                        <!-- Etiquetas -->
                        <div v-if="ticket.tags.length" class="flex flex-wrap gap-1 mb-1.5">
                            <span
                                v-for="tag in ticket.tags"
                                :key="tag.id"
                                class="text-[9px] font-bold uppercase tracking-widest px-1.5 py-0.5 rounded-full border"
                                :style="tag.color ? { borderColor: tag.color, color: tag.color } : undefined"
                                :class="tag.color ? '' : 'border-primary/15 text-primary/50'"
                            >
                                {{ tag.name }}
                            </span>
                        </div>

                        <!-- Pie: prioridad en texto (antes solo se distinguía
                             por el color del borde, invisible para un lector de
                             pantalla y para quien no distingue esos colores) y
                             el tiempo desde el último cambio. -->
                        <div class="flex items-center justify-between gap-2 text-[10px] text-primary/50 mb-2">
                            <span class="shrink-0 font-semibold uppercase tracking-wider">
                                {{ priorityLabel(ticket.priority) }}
                            </span>
                            <span class="shrink-0" :title="`Última actividad: ${relativeTime(ticket.updated_at)}`">
                                {{ relativeTime(ticket.updated_at) }}
                            </span>
                        </div>

                        <!-- Asignación desde la tarjeta. El @click.stop y el
                             @mousedown.stop son necesarios: sin ellos abrir el
                             desplegable dispara abrirChat y Sortable interpreta
                             el clic como el inicio de un arrastre. -->
                        <select
                            :value="ticket.assigned_user?.id ?? ''"
                            @change="onAssign(ticket.id, $event)"
                            @click.stop
                            @mousedown.stop
                            @keydown.enter.stop
                            @keydown.space.stop
                            :disabled="asignando === ticket.id"
                            :aria-label="`Agente asignado al caso de ${ticket.contact?.display_name || 'contacto sin nombre'}`"
                            class="w-full text-[10px] py-1 px-1.5 rounded-lg bg-white border border-primary/12 text-primary/70 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:opacity-50 disabled:cursor-wait"
                        >
                            <option value="">Sin asignar</option>
                            <option v-for="u in agentes" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </article>

                    <p
                        v-if="board[status].length === 0"
                        class="text-[11px] text-primary/25 text-center py-4 select-none"
                    >
                        Arrastra un caso aquí
                    </p>
                </VueDraggable>
            </section>
        </div>
    </div>
</template>
