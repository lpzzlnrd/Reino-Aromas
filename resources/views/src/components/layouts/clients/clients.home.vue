<script setup lang="ts">
    import { ref, computed, onMounted, watch } from 'vue'
    import { useRouter } from 'vue-router'
    import Header from '../header/header.vue'
    import Search from '../../icons/icon.search.vue'
    import Location from '../../icons/icon.location.vue'
    import Phone from '../../icons/icon.phone.vue'
    import At from '../../icons/icon.at.vue'
    import Close from '../../icons/icon.close.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'
    import api from '@/lib/axios'

    /**
     * Clientes del CRM.
     *
     * Los contactos NO se crean desde acá: nacen de los webhooks de Meta
     * cuando alguien escribe. Por eso no hay botón de "nuevo cliente" — un
     * contacto sin channel_id sería un registro que ningún canal puede
     * alcanzar. Solo se corrigen datos a mano (nombre, ciudad, teléfono).
     */

    type Canal = 'whatsapp' | 'instagram' | 'facebook'
    type Ciudad = 'caracas' | 'valencia' | 'barquisimeto' | 'maracay' | 'margarita'

    type Contact = {
        id: number
        display_name: string | null
        profile_picture_url: string | null
        channel: Canal
        channel_id: string
        city: Ciudad | null
        phone: string | null
        instagram_handle: string | null
        first_seen_at: string | null
        last_seen_at: string | null
    }

    type ContactConversation = {
        id: number
        status: 'open' | 'closed'
        last_message_at: string | null
        ticket: { id: number; status: string; status_label: string } | null
    }

    type ContactDetail = Contact & { conversations: ContactConversation[] }

    const CIUDADES: { valor: Ciudad; etiqueta: string }[] = [
        { valor: 'caracas',      etiqueta: 'Caracas' },
        { valor: 'valencia',     etiqueta: 'Valencia' },
        { valor: 'barquisimeto', etiqueta: 'Barquisimeto' },
        { valor: 'maracay',      etiqueta: 'Maracay' },
        { valor: 'margarita',    etiqueta: 'Margarita' },
    ]

    const CANALES: { valor: Canal; etiqueta: string }[] = [
        { valor: 'whatsapp',  etiqueta: 'WhatsApp' },
        { valor: 'instagram', etiqueta: 'Instagram' },
        { valor: 'facebook',  etiqueta: 'Facebook' },
    ]

    const router = useRouter()

    const contacts = ref<Contact[]>([])
    const loading = ref(false)
    const error = ref<string | null>(null)

    // Filtros. La búsqueda va al backend (no filtra en cliente) porque el
    // listado viene paginado: filtrar solo la página actual daría resultados
    // incompletos.
    const searchQuery = ref('')
    const filterChannel = ref<Canal | ''>('')
    const filterCity = ref<Ciudad | ''>('')

    const currentPage = ref(1)
    const lastPage = ref(1)
    const total = ref(0)

    // Panel de detalle
    const selected = ref<ContactDetail | null>(null)
    const loadingDetail = ref(false)

    // Edición inline dentro del panel
    const editing = ref(false)
    const saving = ref(false)
    const editForm = ref<{ display_name: string; city: Ciudad | ''; phone: string }>({
        display_name: '',
        city: '',
        phone: '',
    })

    let searchTimer: number | undefined

    const fetchContacts = async (page = 1) => {
        loading.value = true
        error.value = null

        try {
            const { data } = await api.get('/contacts', {
                params: {
                    page,
                    per_page: 25,
                    search: searchQuery.value || undefined,
                    channel: filterChannel.value || undefined,
                    city: filterCity.value || undefined,
                },
            })

            contacts.value = data.data ?? []
            currentPage.value = data.meta?.current_page ?? 1
            lastPage.value = data.meta?.last_page ?? 1
            total.value = data.meta?.total ?? 0
        } catch {
            contacts.value = []
            error.value = 'No se pudieron cargar los clientes'
        } finally {
            loading.value = false
        }
    }

    // Debounce: sin esto cada tecla dispara un request.
    watch(searchQuery, () => {
        if (searchTimer !== undefined) clearTimeout(searchTimer)
        searchTimer = window.setTimeout(() => fetchContacts(1), 350)
    })

    watch([filterChannel, filterCity], () => fetchContacts(1))

    const openDetail = async (contact: Contact) => {
        loadingDetail.value = true
        editing.value = false
        // Se muestra lo que ya se tiene del listado para que el panel abra
        // instantáneo, y se completa con el detalle al llegar.
        selected.value = { ...contact, conversations: [] }

        try {
            const { data } = await api.get<ContactDetail>(`/contacts/${contact.id}`)
            selected.value = data
        } catch {
            // Se queda con los datos del listado; el historial no aparece.
        } finally {
            loadingDetail.value = false
        }
    }

    const closeDetail = () => {
        selected.value = null
        editing.value = false
    }

    const startEdit = () => {
        if (!selected.value) return

        editForm.value = {
            display_name: selected.value.display_name ?? '',
            city: selected.value.city ?? '',
            phone: selected.value.phone ?? '',
        }
        editing.value = true
    }

    const saveEdit = async () => {
        if (!selected.value) return

        saving.value = true

        try {
            const { data } = await api.patch(`/contacts/${selected.value.id}`, {
                display_name: editForm.value.display_name,
                // Cadena vacía → null: el backend valida contra el enum de
                // ciudades y '' no es un valor válido.
                city: editForm.value.city || null,
                phone: editForm.value.phone || null,
            })

            const actualizado = data.contact ?? data

            selected.value = { ...selected.value, ...actualizado }
            editing.value = false

            // Refresca la fila del listado sin recargar toda la página.
            const i = contacts.value.findIndex((c) => c.id === selected.value?.id)
            if (i !== -1) contacts.value[i] = { ...contacts.value[i], ...actualizado }
        } catch {
            error.value = 'No se pudo guardar el cambio'
        } finally {
            saving.value = false
        }
    }

    /** Abre la bandeja para escribirle. */
    const openChat = () => {
        router.push({ name: 'Messages Home' })
    }

    const channelIcon = (canal: Canal) =>
        canal === 'whatsapp' ? Whatsapp : canal === 'instagram' ? Instagram : Facebook

    const channelClass = (canal: Canal): string =>
        canal === 'whatsapp'
            ? 'bg-green-50 text-green-600 border-green-100'
            : canal === 'instagram'
                ? 'bg-pink-50 text-pink-600 border-pink-100'
                : 'bg-sky-50 text-sky-600 border-sky-100'

    const cityLabel = (ciudad: Ciudad | null): string =>
        CIUDADES.find((c) => c.valor === ciudad)?.etiqueta ?? 'Sin ciudad'

    const initials = (name: string | null): string => {
        if (!name) return '?'
        return name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()
    }

    const relativeTime = (iso: string | null): string => {
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

    const hasFilters = computed(() =>
        searchQuery.value !== '' || filterChannel.value !== '' || filterCity.value !== '',
    )

    const clearFilters = () => {
        searchQuery.value = ''
        filterChannel.value = ''
        filterCity.value = ''
        fetchContacts(1)
    }

    onMounted(() => fetchContacts(1))
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <Header class="mb-8" />

        <!-- Título -->
        <section class="flex items-start justify-between mb-6 px-1 gap-4">
            <div>
                <h1 class="text-3xl font-primary text-primary">Clientes</h1>
                <p class="text-sm text-primary/50 mt-0.5">
                    {{ total }} {{ total === 1 ? 'contacto registrado' : 'contactos registrados' }}
                </p>
            </div>
        </section>

        <!-- Filtros -->
        <div class="flex flex-col sm:flex-row gap-3 mb-5">
            <label class="input-group w-full sm:max-w-xs group cursor-text">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, teléfono o @usuario..."
                    class="bg-transparent text-sm focus:outline-none w-full placeholder:text-primary/30"
                >
            </label>

            <select v-model="filterChannel" class="input-group text-sm py-2.5 px-3 cursor-pointer">
                <option value="">Todos los canales</option>
                <option v-for="c in CANALES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
            </select>

            <select v-model="filterCity" class="input-group text-sm py-2.5 px-3 cursor-pointer">
                <option value="">Todas las ciudades</option>
                <option v-for="c in CIUDADES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
            </select>

            <button
                v-if="hasFilters"
                @click="clearFilters"
                class="text-xs font-semibold text-primary/50 hover:text-primary px-3 py-2 rounded-xl hover:bg-primary/5 transition-colors whitespace-nowrap cursor-pointer"
            >
                Limpiar filtros
            </button>
        </div>

        <div v-if="error" class="mb-5 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
            {{ error }}
        </div>

        <!-- Tabla -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-primary/8">
                            <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest">Cliente</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest hidden sm:table-cell">Canal</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest hidden md:table-cell">Ciudad</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest hidden lg:table-cell">Último contacto</th>
                            <th class="px-5 py-3.5 text-right text-[11px] font-bold text-primary/40 uppercase tracking-widest">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-primary/40">Cargando...</td>
                        </tr>

                        <tr v-else-if="contacts.length === 0">
                            <td colspan="5" class="px-5 py-12 text-center">
                                <p class="text-sm text-primary/40">
                                    {{ hasFilters ? 'Ningún cliente coincide con los filtros.' : 'Todavía no hay clientes registrados.' }}
                                </p>
                                <p v-if="!hasFilters" class="text-[11px] text-primary/35 mt-1">
                                    Los contactos se crean solos cuando alguien escribe por WhatsApp, Instagram o Facebook.
                                </p>
                            </td>
                        </tr>

                        <tr
                            v-for="c in contacts"
                            :key="c.id"
                            class="border-b border-primary/5 hover:bg-primary/3 transition-colors cursor-pointer"
                            @click="openDetail(c)"
                        >
                            <!-- Nombre -->
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="c.profile_picture_url"
                                        :src="c.profile_picture_url"
                                        :alt="c.display_name ?? 'Cliente'"
                                        class="w-9 h-9 rounded-full object-cover shrink-0"
                                    >
                                    <div
                                        v-else
                                        class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary text-xs font-bold shrink-0"
                                    >
                                        {{ initials(c.display_name) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-primary leading-tight truncate">
                                            {{ c.display_name || 'Sin nombre' }}
                                        </p>
                                        <p class="text-[11px] text-primary/40 truncate">
                                            {{ c.phone || (c.instagram_handle ? '@' + c.instagram_handle : c.channel_id) }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- Canal -->
                            <td class="px-5 py-4 hidden sm:table-cell">
                                <span
                                    class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full border"
                                    :class="channelClass(c.channel)"
                                >
                                    <component :is="channelIcon(c.channel)" class="text-xs" />
                                    {{ c.channel }}
                                </span>
                            </td>

                            <!-- Ciudad -->
                            <td class="px-5 py-4 hidden md:table-cell">
                                <span class="text-xs text-primary/60 capitalize">{{ cityLabel(c.city) }}</span>
                            </td>

                            <!-- Último contacto -->
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <span class="text-xs text-primary/50">{{ relativeTime(c.last_seen_at) }}</span>
                            </td>

                            <!-- Acciones -->
                            <td class="px-5 py-4 text-right">
                                <button
                                    class="text-[11px] font-bold uppercase tracking-widest px-3 py-1.5 rounded-lg border-2 border-secondary text-primary hover:bg-primary hover:border-primary hover:text-white transition-all cursor-pointer whitespace-nowrap"
                                    @click.stop="openDetail(c)"
                                >
                                    Ver ficha
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="lastPage > 1" class="flex items-center justify-between px-5 py-4 border-t border-primary/8">
                <p class="text-xs text-primary/50">Página {{ currentPage }} de {{ lastPage }}</p>
                <div class="flex gap-2">
                    <button
                        :disabled="currentPage <= 1 || loading"
                        @click="fetchContacts(currentPage - 1)"
                        class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-primary/15 text-primary/70 hover:bg-primary/5 disabled:opacity-30 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                        Anterior
                    </button>
                    <button
                        :disabled="currentPage >= lastPage || loading"
                        @click="fetchContacts(currentPage + 1)"
                        class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-primary/15 text-primary/70 hover:bg-primary/5 disabled:opacity-30 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                        Siguiente
                    </button>
                </div>
            </div>
        </div>

        <!-- Panel de detalle -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="selected" class="fixed inset-0 z-50 flex justify-end bg-primary/20 backdrop-blur-sm" @click.self="closeDetail">
                <aside class="w-full max-w-md h-full bg-white shadow-2xl overflow-y-auto flex flex-col">

                    <!-- Cabecera de la ficha -->
                    <header class="px-6 py-5 border-b border-primary/8 flex items-start justify-between gap-3 sticky top-0 bg-white z-10">
                        <div class="flex items-center gap-3 min-w-0">
                            <img
                                v-if="selected.profile_picture_url"
                                :src="selected.profile_picture_url"
                                :alt="selected.display_name ?? 'Cliente'"
                                class="w-12 h-12 rounded-full object-cover shrink-0"
                            >
                            <div v-else class="w-12 h-12 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary text-sm font-bold shrink-0">
                                {{ initials(selected.display_name) }}
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-lg font-primary text-primary leading-tight truncate">
                                    {{ selected.display_name || 'Sin nombre' }}
                                </h2>
                                <span
                                    class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full border mt-1"
                                    :class="channelClass(selected.channel)"
                                >
                                    <component :is="channelIcon(selected.channel)" class="text-xs" />
                                    {{ selected.channel }}
                                </span>
                            </div>
                        </div>
                        <button @click="closeDetail" class="p-2 rounded-xl hover:bg-primary/5 text-primary/40 hover:text-primary transition-colors cursor-pointer shrink-0">
                            <Close />
                        </button>
                    </header>

                    <div class="px-6 py-5 flex flex-col gap-6 flex-1">

                        <!-- Datos, en lectura -->
                        <section v-if="!editing" class="flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-[11px] font-bold text-primary/40 uppercase tracking-widest">Datos</h3>
                                <button @click="startEdit" class="text-[11px] font-bold text-secondary hover:text-accent-hover uppercase tracking-widest transition-colors cursor-pointer">
                                    Editar
                                </button>
                            </div>

                            <div class="flex items-center gap-2.5 text-sm text-primary/70">
                                <Location class="text-primary/40 shrink-0" />
                                <span class="capitalize">{{ cityLabel(selected.city) }}</span>
                            </div>

                            <div v-if="selected.phone" class="flex items-center gap-2.5 text-sm text-primary/70">
                                <Phone class="text-primary/40 shrink-0" />
                                <span>{{ selected.phone }}</span>
                            </div>

                            <div v-if="selected.instagram_handle" class="flex items-center gap-2.5 text-sm text-primary/70">
                                <At class="text-primary/40 shrink-0" />
                                <span>{{ selected.instagram_handle }}</span>
                            </div>

                            <div class="text-[11px] text-primary/40 pt-1 leading-relaxed">
                                <p>Primer contacto: {{ relativeTime(selected.first_seen_at) }}</p>
                                <p>Último contacto: {{ relativeTime(selected.last_seen_at) }}</p>
                            </div>
                        </section>

                        <!-- Datos, en edición -->
                        <section v-else class="flex flex-col gap-3">
                            <h3 class="text-[11px] font-bold text-primary/40 uppercase tracking-widest">Editar datos</h3>

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">Nombre</span>
                                <input v-model="editForm.display_name" type="text" class="input-group text-sm py-2 px-3 w-full">
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">Ciudad</span>
                                <select v-model="editForm.city" class="input-group text-sm py-2 px-3 w-full cursor-pointer">
                                    <option value="">Sin ciudad</option>
                                    <option v-for="c in CIUDADES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
                                </select>
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">Teléfono</span>
                                <input v-model="editForm.phone" type="text" class="input-group text-sm py-2 px-3 w-full">
                            </label>

                            <!-- El canal no se edita: lo asigna Meta y cambiarlo
                                 rompería la correlación de los webhooks. -->
                            <p class="text-[11px] text-primary/35 leading-relaxed">
                                El canal y el identificador los asigna Meta y no se pueden cambiar.
                            </p>

                            <div class="flex gap-2 pt-1">
                                <button
                                    @click="saveEdit"
                                    :disabled="saving"
                                    class="btn-primary text-xs py-2 px-4 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ saving ? 'Guardando...' : 'Guardar' }}
                                </button>
                                <button
                                    @click="editing = false"
                                    class="text-xs font-semibold text-primary/50 hover:text-primary px-3 py-2 rounded-xl hover:bg-primary/5 transition-colors cursor-pointer"
                                >
                                    Cancelar
                                </button>
                            </div>
                        </section>

                        <!-- Historial -->
                        <section class="flex flex-col gap-3">
                            <h3 class="text-[11px] font-bold text-primary/40 uppercase tracking-widest">
                                Historial de conversaciones
                            </h3>

                            <p v-if="loadingDetail" class="text-sm text-primary/40">Cargando historial...</p>

                            <p v-else-if="selected.conversations.length === 0" class="text-sm text-primary/40">
                                Sin conversaciones registradas.
                            </p>

                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="conv in selected.conversations"
                                    :key="conv.id"
                                    class="px-3 py-2.5 rounded-xl border border-primary/8 bg-primary/2 flex items-center justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-primary">
                                            {{ conv.status === 'open' ? 'Chat abierto' : 'Chat cerrado' }}
                                        </p>
                                        <p class="text-[11px] text-primary/40">{{ relativeTime(conv.last_message_at) }}</p>
                                    </div>
                                    <span
                                        v-if="conv.ticket"
                                        class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-secondary/15 text-primary/70 border border-secondary/25 shrink-0"
                                    >
                                        {{ conv.ticket.status_label }}
                                    </span>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Acción -->
                    <footer class="px-6 py-4 border-t border-primary/8 sticky bottom-0 bg-white">
                        <button @click="openChat" class="btn-primary w-full text-sm py-2.5">
                            Abrir conversación
                        </button>
                    </footer>
                </aside>
            </div>
        </Transition>
    </div>
</template>
