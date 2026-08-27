<script setup lang="ts">
    import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
    import { useInbox, type ChatMessage } from '@/hooks/useInbox'
    import { useRealtime } from '@/hooks/useRealtime'
    import { useDashboard } from '@/hooks/useDashboard'
    import { useModal } from '@/composables/useModal'
    import { CaseStatus } from '@/hooks/caseStatus'

    import CheckMark from '../../icons/icon.checkMark.vue'
    import Comments from '../../icons/icon.comments.vue'
    import User from '../../icons/icon.user.vue'
    import Location from '../../icons/icon.location.vue'
    import Sent from '../../icons/icon.sent.vue'
    import Check from '../../icons/icon.check.vue'
    import DoubleCheck from '../../icons/icon.doubleCheck.vue'

    import Info from './messages.chatInfo.vue'

    /**
     * Conversación abierta.
     *
     * Antes esto pintaba un solo párrafo con el último mensaje del preview, el
     * input no enviaba nada y "Cerrar ticket" no hacía nada. Ahora carga el
     * historial completo y encola mensajes de verdad.
     */

    const {
        detail,
        templates,
        loadingDetail,
        sending,
        detailError,
        templatesError,
        closeChat,
        sendError,
        sendMessage,
        retrying,
        retryMessage,
        sendTemplate,
        setConversationStatus,
        onMessageCreated,
        onMessageStatus,
        onTicketUpdated,
    } = useInbox()

    const { escuchar, dejar } = useRealtime()

    /** Canal al que este chat está suscrito, para poder dejarlo al cambiar. */
    let canalActual: string | null = null

    // Los tickets resueltos del estado vacío salen del mismo /reports/summary
    // que alimenta el dashboard; antes era un ref(0) que nadie actualizaba.
    const { byStatus, loadSummary } = useDashboard()

    const isDesktop = ref(false)
    const draft = ref('')
    const showTemplates = ref(false)
    const messagesPane = ref<HTMLElement | null>(null)
    const panelPlantillas = ref<HTMLElement | null>(null)

    /*
     * El selector de plantillas se cerraba SOLO con su propio boton o al elegir
     * una. Como tapa el area de mensajes, el agente que lo abria por curiosidad
     * tenia que acertar otra vez en el mismo boton para salir. Ahora cierra con
     * Escape, y el foco entra al panel al abrirlo.
     */
    useModal(showTemplates, () => { showTemplates.value = false }, { panel: panelPlantillas })

    const inputBtnStyle = 'p-2 cursor-pointer hover:bg-primary/8 text-primary/50 hover:text-primary rounded-xl transition-colors'
    const clientText = 'max-w-sm text-left px-4 py-2.5 bg-white border border-primary/10 rounded-2xl rounded-tl-sm shadow-sm text-sm text-primary leading-relaxed'
    const adminText = 'max-w-sm ml-auto text-left px-4 py-2.5 bg-gradient-to-br from-secondary/30 to-accent/40 border border-secondary/20 rounded-2xl rounded-tr-sm shadow-sm text-sm text-primary leading-relaxed'

    const completedTickets = computed(() => byStatus.value[CaseStatus.Closed] ?? 0)

    const contactName = computed(
        () => detail.value?.contact?.display_name || 'Sin nombre',
    )

    const isClosed = computed(() => detail.value?.status === 'closed')

    /**
     * Fuera de la ventana de 24h Meta rechaza el texto libre y solo acepta
     * plantillas aprobadas. Se avisa antes de que el envío falle en la cola,
     * que es donde el agente no lo vería.
     */
    const outsideWindow = computed(
        () => detail.value !== null && !detail.value.within_24h_window,
    )

    const canSend = computed(
        () => draft.value.trim() !== '' && !sending.value && !isClosed.value,
    )

    const checkScreen = () => {
        isDesktop.value = window.innerWidth >= 768
    }

    const scrollToBottom = async () => {
        await nextTick()
        const pane = messagesPane.value
        if (pane) pane.scrollTop = pane.scrollHeight
    }

    const submit = async () => {
        if (!canSend.value) return

        const texto = draft.value
        // Se limpia antes de esperar la respuesta para que el input no quede
        // bloqueado; si falla, el texto se devuelve.
        draft.value = ''

        const ok = await sendMessage(texto)
        if (!ok) draft.value = texto
        else await scrollToBottom()
    }

    const useTemplate = async (id: number) => {
        showTemplates.value = false
        const ok = await sendTemplate(id)
        if (ok) await scrollToBottom()
    }

    /**
     * Cerrar o reabrir la conversación.
     *
     * El guard de reentrada no es cosmético: cerrar y reabrir son rutas
     * distintas, y el estado local solo se actualiza al recibir el 200. Dos
     * clics rápidos mandaban dos PATCH y podían dejar el estado local invertido
     * respecto al del servidor.
     */
    const cambiandoEstado = ref(false)

    const toggleConversation = async () => {
        if (!detail.value || cambiandoEstado.value) return

        cambiandoEstado.value = true

        try {
            await setConversationStatus(detail.value.id, isClosed.value ? 'reopen' : 'close')
        } finally {
            cambiandoEstado.value = false
        }
    }

    /*
     * Los formateadores se crean UNA vez y se reutilizan.
     *
     * Antes cada mensaje construía su propio Intl en cada render: crear un
     * formateador es una de las llamadas más caras del runtime de JS, y con un
     * hilo de cientos de mensajes se rehacían todos en cada tick del websocket.
     */
    const FORMATO_HORA = new Intl.DateTimeFormat('es-VE', {
        hour: '2-digit',
        minute: '2-digit',
    })

    const FORMATO_FECHA = new Intl.DateTimeFormat('es-VE', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })

    /** Hora del mensaje, que es lo único que hace falta dentro del hilo. */
    const messageTime = (msg: ChatMessage): string =>
        FORMATO_HORA.format(new Date(msg.sent_at ?? msg.created_at))

    /**
     * Separadores de día, precalculados en una sola pasada.
     *
     * showDaySeparator() y dayLabel() eran funciones llamadas desde el template
     * por cada mensaje: la primera recorría el array entero en cada llamada
     * (O(n²)) y las dos construían objetos Date y strings nuevos. Como son
     * llamadas a función, Vue no las cachea y se reevaluaban íntegras en cada
     * re-render — y hay re-renders constantes, uno por cada `message.status` que
     * entra por el socket.
     *
     * El Map va por id de mensaje y no por índice: un mensaje insertado al
     * principio desplazaría todos los índices.
     */
    const separadores = computed<Map<number, string>>(() => {
        const mapa = new Map<number, string>()
        const mensajes = detail.value?.messages ?? []

        const hoy = new Date().toDateString()
        const ayerFecha = new Date()
        ayerFecha.setDate(ayerFecha.getDate() - 1)
        const ayer = ayerFecha.toDateString()

        let diaPrevio: string | null = null

        for (const msg of mensajes) {
            const fecha = new Date(msg.created_at)
            const dia = fecha.toDateString()

            if (dia !== diaPrevio) {
                mapa.set(
                    msg.id,
                    dia === hoy ? 'Hoy' : dia === ayer ? 'Ayer' : FORMATO_FECHA.format(fecha),
                )

                diaPrevio = dia
            }
        }

        return mapa
    })

    /**
     * Al cambiar de chat: bajar el hilo y mover la suscripción.
     *
     * Se deja el canal anterior antes de entrar al nuevo. Sin eso, navegar entre
     * chats acumularía suscripciones y los mensajes de una conversación cerrada
     * seguirían llegando.
     */
    watch(() => detail.value?.id, (id) => {
        scrollToBottom()

        if (canalActual !== null) {
            dejar(canalActual)
            canalActual = null
        }

        if (id === undefined) return

        canalActual = `conversations.${id}`

        escuchar(canalActual, {
            'message.created': onMessageCreated,
            // El que cierra el círculo del envío: pending → sent/failed sin
            // recargar.
            'message.status': onMessageStatus,
            // El panel lateral muestra el ticket: si otro agente lo mueve, se
            // actualiza acá también.
            'ticket.updated': onTicketUpdated,
        })

        // El selector de plantillas se cierra al cambiar de chat: openChat vacía
        // la lista, así que quedaba abierto mostrando "no hay plantillas" para
        // un chat que sí las tiene, hasta que resolvía la carga.
        showTemplates.value = false
    }, { immediate: true })

    // Cuando llega un mensaje al chat abierto hay que bajar el hilo, o el
    // agente no ve lo que acaba de entrar.
    watch(
        () => detail.value?.messages.length,
        (nuevo, previo) => {
            if (nuevo !== undefined && previo !== undefined && nuevo > previo) {
                void scrollToBottom()
            }
        },
    )

    onMounted(() => {
        checkScreen()
        window.addEventListener('resize', checkScreen)
        loadSummary()
    })

    onBeforeUnmount(() => {
        window.removeEventListener('resize', checkScreen)
    })
</script>

<template>
    <div id="desktop-open-chats" class="h-full relative flex flex-col">
        <!-- Estado vacío: sin chat seleccionado -->
        <!-- Sin el gate de isDesktop: en móvil ninguna rama del v-if/v-else-if
             se cumplía y la bandeja quedaba completamente en blanco, sin lista,
             sin mensaje y sin explicación. El texto se adapta porque en móvil la
             lista está arriba y en escritorio al lado. -->
        <div v-if="!detail && !loadingDetail" class="absolute inset-0 flex flex-col items-center justify-center text-center gap-6 px-8">
            <div class="w-24 h-24 rounded-full bg-gradient-to-br from-secondary/30 to-accent/50 flex items-center justify-center shadow-inner">
                <Comments class="text-primary/50 text-4xl"/>
            </div>
            <div class="flex flex-col gap-1.5">
                <p class="text-lg font-primary text-primary">Selecciona un chat</p>
                <p class="text-sm text-primary/50 leading-relaxed max-w-xs">Elige una conversación para gestionar tus ventas y asesorías</p>
                <p class="flex items-center justify-center gap-1.5 text-xs text-green-600 font-semibold mt-1">
                    <CheckMark class="text-sm"/>
                    {{ completedTickets }} tickets resueltos
                </p>
                <p v-if="detailError" class="text-xs text-red-600 mt-2">{{ detailError }}</p>
            </div>
        </div>

        <!-- Cargando el detalle -->
        <div v-else-if="loadingDetail && !detail" class="absolute inset-0 flex items-center justify-center">
            <p class="text-sm text-primary/40">Cargando conversación...</p>
        </div>

        <!-- Chat abierto -->
        <div v-else-if="detail" class="flex flex-row w-full h-full">
            <!-- Panel de mensajes -->
            <div class="h-full flex-1 flex flex-col min-w-0">
                <!-- Header del chat -->
                <header class="px-5 py-3.5 border-b border-primary/8 bg-white/60 backdrop-blur-sm flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <!-- Volver a la lista. Solo en móvil: ahí el chat tapa
                             la lista entera, así que sin este botón se entra a
                             una conversación y no hay forma de salir. -->
                        <button
                            type="button"
                            @click="closeChat"
                            aria-label="Volver a la lista de conversaciones"
                            class="md:hidden p-1.5 -ml-1.5 rounded-lg text-primary/60 hover:text-primary hover:bg-primary/8 transition-colors cursor-pointer shrink-0"
                        >
                            <span aria-hidden="true" class="text-lg leading-none">‹</span>
                        </button>

                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0 overflow-hidden">
                            <img
                                v-if="detail.contact?.profile_picture_url"
                                :src="detail.contact.profile_picture_url"
                                :alt="contactName"
                                class="w-full h-full object-cover"
                            >
                            <User v-else class="text-xl translate-y-0.5" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-primary leading-tight truncate">{{ contactName }}</p>
                            <p class="flex items-center gap-1 text-[11px] text-primary/50">
                                <Location class="text-xs"/>
                                <span class="capitalize truncate">{{ detail.contact?.city || 'Sin ciudad' }}</span>
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="toggleConversation"
                        :disabled="cambiandoEstado"
                        class="text-xs font-bold uppercase tracking-widest px-4 py-2 rounded-xl border-2 border-secondary/40 text-primary/60 hover:border-primary hover:bg-primary hover:text-white transition-all cursor-pointer whitespace-nowrap shrink-0 ml-3 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <template v-if="cambiandoEstado">Guardando...</template>
                        <template v-else>{{ isClosed ? 'Reabrir chat' : 'Cerrar chat' }}</template>
                    </button>
                </header>

                <!-- Errores de las acciones del chat (cerrar/reabrir, estado,
                     prioridad, notas).

                     Antes detailError solo se pintaba en el estado vacío, o sea
                     únicamente cuando NO había chat abierto: con un chat abierto
                     los tres fallos eran completamente mudos y el agente
                     clickeaba de nuevo creyendo que no había pasado nada. -->
                <div
                    v-if="detailError"
                    role="alert"
                    class="px-5 py-2.5 bg-red-50 border-b border-red-100 flex items-start justify-between gap-3 shrink-0"
                >
                    <p class="text-xs text-red-700 leading-relaxed">{{ detailError }}</p>
                    <button
                        type="button"
                        @click="detailError = null"
                        aria-label="Descartar el aviso"
                        class="text-xs font-bold text-red-700/60 hover:text-red-700 cursor-pointer shrink-0"
                    >
                        ✕
                    </button>
                </div>

                <!-- Área de mensajes -->
                <!-- role=log + aria-live: los mensajes que entran por el socket
                     eran invisibles para un lector de pantalla. En una app de
                     chat eso es un fallo funcional, no un detalle. -->
                <div
                    ref="messagesPane"
                    role="log"
                    aria-live="polite"
                    aria-label="Mensajes de la conversación"
                    class="flex-1 overflow-y-auto scroll p-5 flex flex-col gap-3"
                >
                    <p v-if="detail.messages.length === 0" class="text-sm text-primary/40 text-center my-auto">
                        Todavía no hay mensajes en esta conversación.
                    </p>

                    <template v-for="msg in detail.messages" :key="msg.id">
                        <!-- Separador de día: sale del computed `separadores`,
                             que recorre el hilo una sola vez. -->
                        <p v-if="separadores.has(msg.id)" class="text-[10px] font-bold uppercase tracking-widest text-primary/40 text-center my-2">
                            {{ separadores.get(msg.id) }}
                        </p>

                        <div :class="msg.direction === 'inbound' ? 'flex flex-col items-start' : 'flex flex-col items-end'">
                            <p :class="msg.direction === 'inbound' ? clientText : adminText">
                                <span v-if="msg.body" class="whitespace-pre-wrap">{{ msg.body }}</span>
                                <a
                                    v-else-if="msg.media_url"
                                    :href="msg.media_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="underline text-secondary"
                                >
                                    Ver archivo adjunto
                                </a>
                                <span v-else class="italic text-primary/40">Mensaje sin contenido</span>
                            </p>

                            <!-- Hora y estado de entrega -->
                            <span class="flex items-center gap-1 text-[10px] text-primary/35 mt-0.5 px-1">
                                {{ messageTime(msg) }}

                                <template v-if="msg.direction === 'outbound'">
                                    <!-- El envío responde 202: el mensaje nace en
                                         'pending' y la cola lo pasa a 'sent'. Sin
                                         pintarlo, el agente no sabe si salió. -->
                                    <span v-if="msg.status === 'pending'" class="text-primary/30">· Enviando...</span>
                                    <span v-else-if="msg.status === 'failed'" class="text-red-600 font-semibold">· No se envió</span>
                                    <Check v-else-if="msg.status === 'sent'" class="text-primary/30" />
                                    <DoubleCheck v-else-if="msg.status === 'delivered'" class="text-primary/30" />
                                    <DoubleCheck v-else-if="msg.status === 'read'" class="text-sky-500" />
                                </template>
                            </span>

                            <!-- Fallo: el motivo y la salida.
                                 Antes solo se pintaba el texto rojo y el agente
                                 no tenia mas opcion que reescribir el mensaje. -->
                            <div v-if="msg.status === 'failed'" class="flex flex-col items-end gap-1 px-1 max-w-sm">
                                <p v-if="msg.failed_reason" class="text-[10px] text-red-600/80 text-right">
                                    {{ msg.failed_reason }}
                                </p>

                                <button
                                    :disabled="retrying === msg.id"
                                    @click="retryMessage(msg.id)"
                                    class="text-[10px] font-bold uppercase tracking-widest text-red-600 hover:text-red-700 hover:bg-red-50 px-2 py-1 rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                                >
                                    {{ retrying === msg.id ? 'Reintentando...' : 'Reintentar' }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Aviso de ventana de 24h -->
                <div v-if="outsideWindow && !isClosed" class="px-5 py-2 bg-amber-50 border-t border-amber-100">
                    <p class="text-[11px] text-amber-700 leading-relaxed">
                        Pasaron más de 24 horas desde el último mensaje del cliente. Meta solo acepta
                        plantillas aprobadas: usa el selector de plantillas o el texto libre no llegará.
                    </p>
                </div>

                <!-- Error de envío -->
                <div v-if="sendError" class="px-5 py-2 bg-red-50 border-t border-red-100">
                    <p role="alert" class="text-[11px] text-red-700">{{ sendError }}</p>
                </div>

                <!-- Input bar -->
                <footer class="px-4 py-3 border-t border-primary/8 bg-white/40 shrink-0 relative">
                    <!-- Selector de plantillas -->
                    <div
                        v-if="showTemplates"
                        ref="panelPlantillas"
                        role="menu"
                        aria-label="Plantillas disponibles"
                        class="absolute bottom-full left-4 right-4 mb-2 max-h-64 overflow-y-auto bg-white border border-primary/12 rounded-2xl shadow-lg z-10"
                    >
                        <!-- El fallo de red se distingue del "no hay ninguna":
                             fuera de la ventana de 24h las plantillas son el
                             UNICO envio posible, asi que decir que no hay
                             cuando fallo la carga deja al agente sin saber que
                             puede reintentar. -->
                        <p v-if="templatesError" class="px-4 py-4 text-xs text-red-600 text-center leading-relaxed">
                            {{ templatesError }}
                        </p>
                        <p v-else-if="templates.length === 0" class="px-4 py-4 text-xs text-primary/50 text-center">
                            No hay plantillas disponibles para esta conversación.
                        </p>
                        <button
                            v-for="tpl in templates"
                            :key="tpl.id"
                            @click="useTemplate(tpl.id)"
                            :disabled="sending"
                            role="menuitem"
                            type="button"
                            class="w-full text-left px-4 py-3 border-b border-primary/5 last:border-b-0 hover:bg-primary/3 transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-wait"
                        >
                            <p class="text-xs font-semibold text-primary">{{ tpl.name }}</p>
                            <p class="text-[11px] text-primary/50 line-clamp-2 mt-0.5">{{ tpl.rendered_body }}</p>
                        </button>
                    </div>

                    <p v-if="isClosed" class="text-xs text-primary/40 text-center py-2">
                        Esta conversación está cerrada. Reábrela para poder responder.
                    </p>

                    <div v-else class="flex items-center gap-2 bg-white border border-primary/12 rounded-2xl px-3 py-2 shadow-sm focus-within:border-secondary/50 transition-colors">
                        <input
                            v-model="draft"
                            @keydown.enter.prevent="submit"
                            :disabled="sending"
                            aria-label="Escribe un mensaje"
                            class="flex-1 text-sm text-primary placeholder:text-primary/40 focus:outline-none bg-transparent disabled:opacity-50"
                            type="text"
                            placeholder="Escribe un mensaje..."
                        >
                        <div class="flex items-center gap-0.5">
                            <button
                                type="button"
                                @click="showTemplates = !showTemplates"
                                :class="[inputBtnStyle, showTemplates ? 'bg-primary/8 text-primary' : '']"
                                :aria-expanded="showTemplates"
                                aria-haspopup="menu"
                                aria-label="Plantillas"
                                title="Plantillas"
                            >
                                <Comments aria-hidden="true" />
                            </button>
                            <!-- El boton principal de la app no tenia ni texto,
                                 ni title, ni aria-label: para un lector de
                                 pantalla era completamente anonimo. -->
                            <button
                                type="button"
                                @click="submit"
                                :disabled="!canSend"
                                aria-label="Enviar mensaje"
                                title="Enviar mensaje"
                                :class="inputBtnStyle + ' bg-gradient-to-br from-secondary to-accent-hover text-white hover:brightness-110 rounded-xl px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed'"
                            >
                                <Sent aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </footer>
            </div>

            <Info class="w-64 shrink-0 hidden lg:flex"/>
        </div>
    </div>
</template>
