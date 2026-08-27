<script setup lang="ts">
    import { onMounted, ref, watch } from 'vue'
    import { useCaseStatus } from '@/hooks/caseStatus.ts'
    import { useInbox, type ChatSummary } from '@/hooks/useInbox'
    import { useRealtime } from '@/hooks/useRealtime'

    import Search from '../../icons/icon.search.vue'
    import User from '../../icons/icon.user.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'

    /**
     * Panel lateral de la bandeja: buscador, filtros y lista de chats.
     *
     * Antes la lista era un ref con Ana/Luis/Carlos escritos a mano, el
     * buscador no tenía v-model y los contadores de los filtros estaban en
     * cero porque nadie llamaba a setCounts(). Ahora todo sale de la API.
     */

    const { statuses, casesByStatus } = useCaseStatus()
    const {
        chats,
        filters,
        selectedId,
        hasFilters,
        loadingChats,
        chatsError,
        loadChats,
        loadCounts,
        openChat,
        clearFilters,
        onMessageCreated,
        onTicketUpdated,
        sonido,
        avisoEscritorio,
    } = useInbox()

    /*
     * Estado del interruptor de avisos.
     *
     * Se copia a un ref en vez de llamar a sonido.activado() en la plantilla:
     * ese método lee localStorage, que no es reactivo, así que Vue no
     * re-renderizaría al cambiarlo y el icono se quedaría al revés.
     */
    const sonidoActivo = ref(sonido.activado())
    const escritorioActivo = ref(avisoEscritorio.activado())

    /** Por qué no se pudieron activar los avisos del sistema. */
    const errorAvisos = ref<string | null>(null)

    const alternarSonido = (): void => {
        sonidoActivo.value = sonido.alternar()
    }

    /**
     * Pide permiso al navegador la primera vez; después solo alterna.
     *
     * Un 'denied' no se puede revertir por código, así que se avisa en vez de
     * dejar el interruptor puesto sin que llegue ningún aviso.
     */
    const alternarEscritorio = async (): Promise<void> => {
        if (escritorioActivo.value) {
            avisoEscritorio.desactivar()
            escritorioActivo.value = false

            return
        }

        if (avisoEscritorio.permiso() === 'denied') {
            errorAvisos.value = 'El navegador tiene los avisos bloqueados para este sitio. Habilítalos en la configuración del candado de la barra de direcciones.'

            return
        }

        errorAvisos.value = null
        escritorioActivo.value = await avisoEscritorio.solicitar()
    }

    // La suscripción vive en el componente y no en el hook: useInbox es un
    // singleton que nunca se desmonta, así que dejarla ahí mantendría el canal
    // abierto al salir de la bandeja.
    // `conectado` y no `disponible`: el segundo solo dice que el build trae
    // credenciales de Reverb, no que el socket esté vivo.
    const { conectado: enVivo, escuchar, alVolverLaConexion } = useRealtime()

    let searchTimer: number | undefined

    // Debounce solo en la búsqueda: sin esto cada tecla dispara un request.
    // Los filtros de botón sí van directo, que son un clic.
    watch(
        () => filters.value.search,
        () => {
            if (searchTimer !== undefined) clearTimeout(searchTimer)
            searchTimer = window.setTimeout(() => loadChats(), 350)
        },
    )

    watch(
        () => [filters.value.case, filters.value.channel, filters.value.mine],
        () => loadChats(),
    )

    const toggleStatus = (valor: (typeof statuses)[number]) => {
        filters.value.case = filters.value.case === valor ? null : valor
    }

    const channelIcon = (canal: ChatSummary['channel']) =>
        canal === 'whatsapp' ? Whatsapp : canal === 'instagram' ? Instagram : Facebook

    const channelClass = (canal: ChatSummary['channel']): string =>
        canal === 'whatsapp'
            ? 'text-green-600'
            : canal === 'instagram'
                ? 'text-pink-600'
                : 'text-sky-600'

    /** Hora corta para los chats de hoy, fecha para los más viejos. */
    const messageTime = (iso: string | null): string => {
        if (!iso) return ''

        const fecha = new Date(iso)
        const hoy = new Date()
        const mismoDia =
            fecha.getDate() === hoy.getDate() &&
            fecha.getMonth() === hoy.getMonth() &&
            fecha.getFullYear() === hoy.getFullYear()

        return mismoDia
            ? fecha.toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit' })
            : fecha.toLocaleDateString('es-VE', { day: '2-digit', month: 'short' })
    }

    const initials = (name: string): string =>
        name
            ? name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()
            : '?'

    onMounted(() => {
        loadChats()
        loadCounts()

        // Canal compartido: los agentes ven la misma bandeja, así que un chat
        // nuevo tiene que aparecerles a todos.
        escuchar('inbox', {
            'message.created': onMessageCreated,
        })

        // Los tickets llegan por su propio canal: mover una tarjeta en el
        // Kanban debe refrescar el badge de la lista.
        escuchar('tickets', {
            'ticket.updated': onTicketUpdated,
        })

        // Al volver de un corte hay que recargar: los eventos que se emitieron
        // mientras el socket estaba caído no se reencolan, así que la lista
        // quedaría con datos viejos sin que nada lo delate.
        alVolverLaConexion(() => {
            loadChats()
            loadCounts()
        })
    })
</script>

<template>
    <!-- Contenedor flex propio: el <main> padre no es flex, así que sin este
         wrapper el panel de chats y el chat se apilaban verticalmente en vez
         de quedar lado a lado. -->
    <div class="flex h-full min-h-screen w-full">

    <!-- Panel lateral de chats.

         En movil la lista OCUPA la pantalla mientras no hay chat abierto y se
         esconde al abrir uno; en escritorio esta siempre al lado. Antes era
         `hidden md:flex` a secas, asi que en el telefono no habia lista y la
         bandeja quedaba en blanco: no habia forma de elegir una conversacion. -->
    <div
        :class="[
            'md:flex md:w-72 md:flex-none shrink-0 flex-col border-r border-primary/10 bg-white/60',
            selectedId === null ? 'flex w-full' : 'hidden',
        ]"
    >

        <!-- Buscador y filtros -->
        <div class="p-3 border-b border-primary/8 flex flex-col gap-2">
            <label class="input-group group cursor-text">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <!-- id propio: header.vue ya usa "search-bar" y los dos se
                     pintan a la vez en /app/messages. Un id duplicado rompe la
                     asociacion de labels y la navegacion por rotulo de los
                     lectores de pantalla. -->
                <input
                    id="inbox-search"
                    v-model="filters.search"
                    aria-label="Buscar conversación"
                    class="focus:outline-none bg-transparent text-sm w-full placeholder:text-primary/40"
                    type="text"
                    placeholder="Buscar conversación..."
                >
            </label>

            <!-- Filtros por status -->
            <div class="flex gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                <button
                    v-for="value in statuses"
                    :key="value"
                    @click="toggleStatus(value)"
                    :class="[
                        'text-[10px] font-bold uppercase tracking-widest whitespace-nowrap px-2.5 py-1 rounded-full border transition-all cursor-pointer',
                        filters.case === value
                            ? 'bg-primary text-white border-primary shadow-sm'
                            : 'bg-white/50 text-primary/60 border-primary/15 hover:border-primary/40 hover:text-primary/80'
                    ]"
                >
                    {{ value }} <span class="opacity-60">{{ casesByStatus[value] }}</span>
                </button>
            </div>

            <!-- Solo los míos -->
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-1.5 cursor-pointer select-none">
                    <input v-model="filters.mine" type="checkbox" class="accent-primary cursor-pointer">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-primary/50">Asignados a mí</span>
                </label>

                <div class="flex items-center gap-2">
                    <!-- Avisos de mensaje entrante.

                         Van aquí, al lado de la insignia "En vivo", porque los
                         dos responden a la misma pregunta del agente: ¿me voy a
                         enterar cuando escriba un cliente? El sonido depende del
                         socket, así que separarlos escondería esa relación. -->
                    <button
                        @click="alternarSonido()"
                        class="cursor-pointer transition-colors"
                        :class="sonidoActivo ? 'text-primary/70 hover:text-primary' : 'text-primary/25 hover:text-primary/50'"
                        :title="sonidoActivo ? 'Sonido activado: clic para silenciar' : 'Silenciado: clic para activar el sonido'"
                        :aria-pressed="sonidoActivo"
                        aria-label="Sonido de mensajes nuevos"
                        type="button"
                    >
                        <!-- Altavoz. El icono cambia de forma, no solo de color:
                             el estado tiene que leerse sin depender de distinguir
                             dos tonos del mismo color. -->
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 4.5 5.5 7.5H3v5h2.5L9 15.5z" />
                            <template v-if="sonidoActivo">
                                <path d="M12.2 7.5a3.5 3.5 0 0 1 0 5" />
                                <path d="M14.4 5.4a6.5 6.5 0 0 1 0 9.2" />
                            </template>
                            <template v-else>
                                <path d="M13 8l4 4" />
                                <path d="M17 8l-4 4" />
                            </template>
                        </svg>
                    </button>

                    <button
                        v-if="avisoEscritorio.soportado()"
                        @click="alternarEscritorio()"
                        class="cursor-pointer transition-colors"
                        :class="escritorioActivo ? 'text-primary/70 hover:text-primary' : 'text-primary/25 hover:text-primary/50'"
                        :title="escritorioActivo ? 'Avisos del sistema activados: clic para desactivar' : 'Clic para recibir avisos del sistema con la pestaña en segundo plano'"
                        :aria-pressed="escritorioActivo"
                        aria-label="Avisos del sistema para mensajes nuevos"
                        type="button"
                    >
                        <!-- Campana. Tachada cuando está desactivada. -->
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5.5 8.5a4.5 4.5 0 0 1 9 0c0 3 1 4.5 1 4.5H4.5s1-1.5 1-4.5z" />
                            <path d="M8.2 15.5a1.9 1.9 0 0 0 3.6 0" />
                            <path v-if="!escritorioActivo" d="M3.5 3.5l13 13" />
                        </svg>
                    </button>

                    <!-- Sin esto el agente no sabe si la lista está al día o si
                         tiene que recargar a mano.

                         Se mira `enVivo` (el socket de verdad) y no si Reverb
                         está configurado: con el servidor caído la insignia se
                         quedaba en verde y el botón de Actualizar no aparecía,
                         así que el agente miraba una bandeja congelada
                         convencido de que estaba al día. -->
                    <span
                        v-if="enVivo"
                        class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-widest text-green-600"
                        title="Las conversaciones se actualizan solas"
                        role="status"
                    >
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                        En vivo
                    </span>
                    <button
                        v-else
                        @click="loadChats(); loadCounts()"
                        class="text-[10px] font-bold uppercase tracking-widest text-primary/40 hover:text-primary transition-colors cursor-pointer"
                        title="El tiempo real no está disponible: actualiza a mano"
                    >
                        Actualizar
                    </button>

                    <button
                        v-if="hasFilters"
                        @click="clearFilters(); loadChats()"
                        class="text-[10px] font-bold uppercase tracking-widest text-primary/40 hover:text-primary transition-colors cursor-pointer"
                    >
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Un permiso bloqueado no se puede revertir por código: el agente
                 pulsaría el interruptor sin que pasara nada. Hay que decirle
                 dónde se arregla. -->
            <p v-if="errorAvisos" class="text-[10px] leading-snug text-red-600" role="alert">
                {{ errorAvisos }}
            </p>
        </div>

        <!-- Lista de chats -->
        <div class="flex-1 overflow-y-auto">
            <p v-if="loadingChats" class="px-4 py-8 text-center text-sm text-primary/40">
                Cargando conversaciones...
            </p>

            <div v-else-if="chatsError" class="px-4 py-8 text-center">
                <p class="text-sm text-red-600">{{ chatsError }}</p>
                <button
                    @click="loadChats()"
                    class="mt-2 text-[11px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer"
                >
                    Reintentar
                </button>
            </div>

            <div v-else-if="chats.length === 0" class="px-4 py-8 text-center">
                <p class="text-sm text-primary/40">
                    {{ hasFilters ? 'Ninguna conversación coincide con los filtros.' : 'Todavía no hay conversaciones.' }}
                </p>
                <p v-if="!hasFilters" class="text-[11px] text-primary/35 mt-1 leading-relaxed">
                    Los chats aparecen solos cuando alguien escribe por WhatsApp, Instagram o Facebook.
                </p>
            </div>

            <button
                v-for="chat in chats"
                :key="chat.id"
                @click="openChat(chat.id)"
                :class="[
                    'w-full text-left px-4 py-3 flex items-start gap-3 border-b border-primary/5 transition-all cursor-pointer',
                    selectedId === chat.id
                        ? 'bg-accent/40 border-l-4 border-l-primary'
                        : 'hover:bg-white/70'
                ]"
            >
                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0 mt-0.5 overflow-hidden">
                    <img
                        v-if="chat.contact_avatar"
                        :src="chat.contact_avatar"
                        :alt="chat.contact_name"
                        class="w-full h-full object-cover"
                    >
                    <span v-else-if="chat.contact_name" class="text-[11px] font-bold">
                        {{ initials(chat.contact_name) }}
                    </span>
                    <User v-else class="text-lg translate-y-0.5" />
                </div>

                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-center mb-0.5">
                        <span class="text-sm font-semibold text-primary truncate">{{ chat.contact_name }}</span>
                        <span class="text-[10px] text-primary/40 shrink-0 ml-1">{{ messageTime(chat.message_time) }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <component
                            :is="channelIcon(chat.channel)"
                            v-if="chat.channel"
                            :class="[channelClass(chat.channel), 'text-xs shrink-0']"
                        />
                        <span class="text-xs text-primary/50 truncate">
                            {{ chat.last_message || 'Sin mensajes' }}
                        </span>
                    </div>
                </div>
            </button>
        </div>
    </div>

    <!-- En movil el chat abierto ocupa todo; sin chat, lo tapa la lista. -->
    <router-view :class="['flex-1 min-w-0', selectedId === null ? 'hidden md:block' : 'block']" />

    </div>
</template>
