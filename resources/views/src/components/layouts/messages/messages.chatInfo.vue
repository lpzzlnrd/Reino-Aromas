<script setup lang="ts">
    import { ref, computed, watch, onMounted } from 'vue'
    import { useInbox, type TicketPriority, type TicketStatus } from '@/hooks/useInbox'
    import { useAssignableUsers } from '@/hooks/useAssignableUsers'
    import { useAuth } from '@/composables/useAuth'
    import { CaseStatus } from '@/hooks/caseStatus'

    import Mail from '../../icons/icon.mail.vue'
    import Calendar from '../../icons/icon.calendar.vue'
    import Phone from '../../icons/icon.phone.vue'
    import At from '../../icons/icon.at.vue'

    /**
     * Panel lateral del chat: contacto, ticket, notas y etiquetas.
     *
     * Antes era 100% estático — el email, el teléfono 0424-4445210, "Cliente
     * desde 2024" y el "Ticket #RE-8921" estaban literales en el template, así
     * que mostraban lo mismo para cualquier contacto que abrieras.
     */

    const { detail, updateTicket } = useInbox()

    const ESTADOS: { valor: TicketStatus; etiqueta: CaseStatus }[] = [
        { valor: 'nuevo',          etiqueta: CaseStatus.New },
        { valor: 'interesado',     etiqueta: CaseStatus.Interested },
        { valor: 'alta_prioridad', etiqueta: CaseStatus.HighPriority },
        { valor: 'en_seguimiento', etiqueta: CaseStatus.Following },
        { valor: 'reservado',      etiqueta: CaseStatus.Reserved },
        { valor: 'cerrado',        etiqueta: CaseStatus.Closed },
    ]

    const PRIORIDADES: { valor: TicketPriority; etiqueta: string }[] = [
        { valor: 'baja',     etiqueta: 'Baja' },
        { valor: 'media',    etiqueta: 'Media' },
        { valor: 'alta',     etiqueta: 'Alta' },
        { valor: 'muy_alta', etiqueta: 'Muy alta' },
    ]

    const notes = ref('')
    const savingNotes = ref(false)
    const notesSaved = ref(false)

    const contact = computed(() => detail.value?.contact ?? null)
    const ticket = computed(() => detail.value?.ticket ?? null)

    // Las notas se editan en un textarea local y se guardan con un botón: sin
    // esto, cambiar de chat pisaría lo que el agente estaba escribiendo.
    watch(
        () => detail.value?.id,
        () => {
            notes.value = ticket.value?.notes ?? ''
            notesSaved.value = false
            notasDeOtroAgente.value = null
        },
        { immediate: true },
    )

    /**
     * Notas que cambió otro agente mientras este escribía.
     *
     * El watch de arriba solo mira el id de la conversación, así que un
     * `ticket.updated` de otro agente reemplazaba `ticket.notes` en el store sin
     * disparar nada aquí: al guardar, este agente pisaba silenciosamente lo que
     * escribió el otro. Ahora se detecta y se avisa antes de sobrescribir.
     */
    const notasDeOtroAgente = ref<string | null>(null)

    watch(
        () => ticket.value?.notes,
        (remotas, previas) => {
            // Solo interesa si el agente tiene cambios sin guardar: sin eso, la
            // versión del servidor entra sin ruido.
            if (remotas === previas) return

            if (notes.value !== (previas ?? '') && notes.value !== (remotas ?? '')) {
                notasDeOtroAgente.value = remotas ?? ''

                return
            }

            notes.value = remotas ?? ''
        },
    )

    /** Descarta lo escrito y se queda con la versión del otro agente. */
    const usarNotasDeOtroAgente = (): void => {
        if (notasDeOtroAgente.value === null) return

        notes.value = notasDeOtroAgente.value
        notasDeOtroAgente.value = null
    }

    const notesChanged = computed(() => notes.value !== (ticket.value?.notes ?? ''))

    const saveNotes = async () => {
        savingNotes.value = true
        notesSaved.value = await updateTicket({ notes: notes.value })
        savingNotes.value = false
    }

    /**
     * Devuelve el <select> a lo que dice el store si el PATCH falló.
     *
     * El DOM ya muestra lo que eligió el usuario, y como el valor vinculado no
     * cambió, Vue no vuelve a pintar: el desplegable se quedaba mintiendo
     * indefinidamente. El agente creía haber marcado "Reservado" y el ticket
     * seguía en "Nuevo", sin ningún aviso.
     */
    const revertirSelect = (event: Event, valorReal: string): void => {
        const select = event.target as HTMLSelectElement

        // nextTick no alcanza: hay que forzarlo sobre el elemento, porque el
        // binding reactivo no cambió y Vue no tiene nada que re-renderizar.
        select.value = valorReal
    }

    const changeStatus = async (event: Event) => {
        const previo = ticket.value?.status ?? ''
        const valor = (event.target as HTMLSelectElement).value as TicketStatus

        const ok = await updateTicket({ status: valor })

        if (!ok) revertirSelect(event, previo)
    }

    const changePriority = async (event: Event) => {
        const previo = ticket.value?.priority ?? ''
        const valor = (event.target as HTMLSelectElement).value as TicketPriority

        const ok = await updateTicket({ priority: valor })

        if (!ok) revertirSelect(event, previo)
    }

    /* ── Asignación de agentes ───────────────────────────────────────────── */

    const { activos: agentes, error: errorAgentes, loadUsers } = useAssignableUsers()
    const { user: usuarioActual } = useAuth()

    const asignando = ref(false)

    /** El botón "Tomar el caso" solo tiene sentido si no es ya suyo. */
    const puedeTomarlo = computed(
        () =>
            usuarioActual.value !== null
            && ticket.value?.assigned_user?.id !== usuarioActual.value.id,
    )

    const asignar = async (usuarioId: number | null, event?: Event): Promise<void> => {
        const previo = String(ticket.value?.assigned_user?.id ?? '')

        asignando.value = true

        try {
            const ok = await updateTicket({ assigned_user_id: usuarioId })

            if (!ok && event) revertirSelect(event, previo)
        } finally {
            asignando.value = false
        }
    }

    const changeAssignee = (event: Event) => {
        const valor = (event.target as HTMLSelectElement).value

        void asignar(valor === '' ? null : Number(valor), event)
    }

    const tomarElCaso = () => {
        if (usuarioActual.value === null) return

        void asignar(usuarioActual.value.id)
    }

    // La lista de agentes se pide al montar: el selector tiene que estar
    // poblado antes de que el agente lo abra, no al primer clic.
    onMounted(() => void loadUsers())

    const channelLabel = computed(() => {
        const canal = contact.value?.channel
        if (canal === 'whatsapp') return 'WhatsApp'
        if (canal === 'instagram') return 'Instagram'
        if (canal === 'facebook') return 'Facebook'
        return 'Sin canal'
    })

    const channelClass = computed(() => {
        const canal = contact.value?.channel
        if (canal === 'whatsapp') return 'bg-green-100 text-green-700 border-green-200'
        if (canal === 'instagram') return 'bg-pink-100 text-pink-700 border-pink-200'
        if (canal === 'facebook') return 'bg-sky-100 text-sky-700 border-sky-200'
        return 'bg-primary/5 text-primary/50 border-primary/10'
    })

    const relativeTime = (iso: string | null | undefined): string => {
        if (!iso) return 'Nunca'

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

    const clientSince = computed(() => {
        const iso = contact.value?.first_seen_at
        if (!iso) return 'Sin registro'

        return `Cliente desde ${new Date(iso).getFullYear()}`
    })
</script>

<template>
    <div class="h-full border-l border-primary/10 bg-white/50 flex flex-col overflow-y-auto scroll">
        <!-- Header -->
        <div class="px-4 py-4 border-b border-primary/8">
            <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Información del contacto</p>
        </div>

        <template v-if="contact">
            <!-- Datos del contacto -->
            <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2.5">
                <p v-if="contact.phone" class="flex items-center gap-2 text-sm text-primary/70">
                    <Phone class="text-secondary shrink-0"/>
                    {{ contact.phone }}
                </p>
                <p v-if="contact.instagram_handle" class="flex items-center gap-2 text-sm text-primary/70">
                    <At class="text-secondary shrink-0"/>
                    <span class="truncate">{{ contact.instagram_handle }}</span>
                </p>
                <p v-if="!contact.phone && !contact.instagram_handle" class="flex items-center gap-2 text-sm text-primary/50">
                    <Mail class="text-secondary shrink-0"/>
                    <span class="truncate">{{ contact.channel_id }}</span>
                </p>
                <p class="flex items-center gap-2 text-sm text-primary/70">
                    <Calendar class="text-secondary shrink-0"/>
                    {{ clientSince }}
                </p>
            </div>

            <!-- Datos del ticket -->
            <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2.5">
                <p class="text-xs font-bold text-primary/40 uppercase tracking-widest mb-1">
                    {{ ticket ? `Ticket #${ticket.id}` : 'Sin ticket' }}
                </p>

                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-primary/50">Canal</span>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full border" :class="channelClass">
                        {{ channelLabel }}
                    </span>
                </div>

                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-primary/50">Última act.</span>
                    <span class="text-xs text-primary/60">{{ relativeTime(detail?.last_message_at) }}</span>
                </div>

                <template v-if="ticket">
                    <label class="flex flex-col gap-1 mt-1">
                        <span class="text-xs font-semibold text-primary/50">Estado</span>
                        <select
                            :value="ticket.status"
                            @change="changeStatus"
                            class="input-group text-xs py-1.5 px-2 w-full cursor-pointer"
                        >
                            <option v-for="e in ESTADOS" :key="e.valor" :value="e.valor">{{ e.etiqueta }}</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-primary/50">Prioridad</span>
                        <select
                            :value="ticket.priority"
                            @change="changePriority"
                            class="input-group text-xs py-1.5 px-2 w-full cursor-pointer"
                        >
                            <option v-for="p in PRIORIDADES" :key="p.valor" :value="p.valor">{{ p.etiqueta }}</option>
                        </select>
                    </label>

                    <div v-if="ticket.course_interest" class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-primary/50 shrink-0">Curso</span>
                        <span class="text-xs text-primary/60 truncate">{{ ticket.course_interest }}</span>
                    </div>

                    <!-- Asignación. Antes era un <span> de solo lectura: el
                         backend aceptaba assigned_user_id desde el principio,
                         pero no había nada en la app que lo mandara, así que el
                         filtro "Asignados a mí" y la alerta de casos sin asignar
                         del dashboard no tenían forma de resolverse. -->
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-primary/50">Asignado a</span>
                        <select
                            :value="ticket.assigned_user?.id ?? ''"
                            @change="changeAssignee"
                            :disabled="asignando"
                            class="input-group text-xs py-1.5 px-2 w-full cursor-pointer disabled:opacity-50 disabled:cursor-wait"
                        >
                            <option value="">Sin asignar</option>
                            <option v-for="u in agentes" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </label>

                    <!-- Atajo para el caso más común: el agente que está leyendo
                         la conversación se la queda. Evita buscar su propio
                         nombre en el desplegable. -->
                    <button
                        v-if="puedeTomarlo"
                        type="button"
                        @click="tomarElCaso"
                        :disabled="asignando"
                        class="text-[10px] font-bold uppercase tracking-widest px-3 py-1.5 rounded-lg border-2 border-secondary/40 text-primary/60 hover:border-primary hover:bg-primary hover:text-white transition-all cursor-pointer disabled:opacity-50 disabled:cursor-wait"
                    >
                        Tomar el caso
                    </button>

                    <p v-if="errorAgentes" role="alert" class="text-[11px] text-red-600 leading-relaxed">
                        {{ errorAgentes }}
                    </p>
                </template>
            </div>

            <!-- Notas internas -->
            <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Notas internas</p>
                    <button
                        v-if="ticket && notesChanged"
                        type="button"
                        @click="saveNotes"
                        :disabled="savingNotes"
                        class="text-[10px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer disabled:opacity-50"
                    >
                        {{ savingNotes ? 'Guardando...' : 'Guardar' }}
                    </button>
                    <span v-else-if="notesSaved" class="text-[10px] font-bold uppercase tracking-widest text-green-600">
                        Guardado
                    </span>
                </div>
                <textarea
                    v-model="notes"
                    :disabled="!ticket"
                    aria-label="Notas internas del caso"
                    class="w-full h-20 text-xs text-primary p-2.5 bg-white/70 border border-primary/12 rounded-xl resize-none focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25 disabled:opacity-50"
                    :placeholder="ticket ? 'Escribe una nota...' : 'Esta conversación no tiene ticket'"
                />

                <!-- Otro agente cambió las notas mientras este escribía.
                     Guardar sin avisar pisaba su trabajo en silencio. -->
                <div
                    v-if="notasDeOtroAgente !== null"
                    role="alert"
                    class="px-2.5 py-2 rounded-lg bg-amber-50 border border-amber-200 flex flex-col gap-1.5"
                >
                    <p class="text-[11px] text-amber-800 leading-relaxed">
                        Otro agente cambió estas notas. Si guardas, se perderá su versión.
                    </p>
                    <button
                        type="button"
                        @click="usarNotasDeOtroAgente"
                        class="self-start text-[10px] font-bold uppercase tracking-widest text-amber-800 hover:text-amber-950 cursor-pointer underline"
                    >
                        Ver la suya y descartar lo mío
                    </button>
                </div>
            </div>

            <!-- Etiquetas -->
            <div class="px-4 py-4 flex flex-col gap-2">
                <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Etiquetas</p>
                <div class="flex flex-wrap gap-1.5 min-h-6">
                    <span
                        v-for="tag in ticket?.tags ?? []"
                        :key="tag.id"
                        class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full border"
                        :style="tag.color ? { backgroundColor: tag.color + '20', borderColor: tag.color + '40', color: tag.color } : undefined"
                        :class="tag.color ? '' : 'bg-primary/5 text-primary/60 border-primary/15'"
                    >
                        {{ tag.name }}
                    </span>
                    <span v-if="!ticket?.tags?.length" class="text-[11px] text-primary/35">
                        Sin etiquetas
                    </span>
                </div>
            </div>
        </template>

        <p v-else class="px-4 py-6 text-sm text-primary/40">
            Esta conversación no tiene un contacto asociado.
        </p>
    </div>
</template>
