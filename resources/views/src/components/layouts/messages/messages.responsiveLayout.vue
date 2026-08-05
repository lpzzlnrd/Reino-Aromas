<script setup lang="ts">
    import { ref } from 'vue'
    import { CaseStatus, useCaseStatus } from '@/hooks/caseStatus.ts'

    import Search from '../../icons/icon.search.vue'
    import User from '../../icons/icon.user.vue'
    import Check from '../../icons/icon.check.vue'
    import DoubleCheck from '../../icons/icon.doubleCheck.vue'

    enum MessageStatus {
        Sent = 'Enviado',
        Delivered = 'Recibido',
        Viewed = 'Visto'
    }

    type Chat = {
        id: number
        username: string
        lastMessage: string
        messageTime: string
        location: string
    }

    const { statuses, casesByStatus } = useCaseStatus()
    const selectedChat = ref<Chat | null>(null)
    const selectedStatus = ref<CaseStatus | null>(null)

    const chats = ref<Chat[]>([
        { id: 1, username: 'Ana',    lastMessage: 'Hola',                  messageTime: '10:20am', location: 'Caracas, Venezuela'         },
        { id: 2, username: 'Luis',   lastMessage: '¿Está disponible?',     messageTime: '2:04pm',  location: 'Barquisimeto, Venezuela'    },
        { id: 3, username: 'Carlos', lastMessage: 'Buenos días, precio del curso', messageTime: '11:00am', location: 'Valencia, Venezuela' },
    ])

    const openChat = (chat: Chat) => { selectedChat.value = chat }

    const pfp = ref()
    const messageStatus = ref(MessageStatus.Sent)
    const open = ref(false)
    const close = () => (open.value = false)
    const toggle = () => (open.value = !open.value)
</script>

<template>
    <!-- Contenedor flex propio: el <main> padre no es flex, así que sin este
         wrapper el panel de chats y el chat se apilaban verticalmente en vez
         de quedar lado a lado. -->
    <div class="flex h-full min-h-screen w-full">

    <!-- Panel lateral de chats -->
    <div class="hidden md:flex md:w-72 md:flex-none shrink-0 flex-col border-r border-primary/10 bg-white/60">

        <!-- Buscador y filtros -->
        <div class="p-3 border-b border-primary/8 flex flex-col gap-2">
            <label class="input-group group cursor-text">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <input id="search-bar" class="focus:outline-none bg-transparent text-sm w-full placeholder:text-primary/30" type="text" placeholder="Buscar conversación...">
            </label>

            <!-- Filtros por status -->
            <div class="flex gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                <button
                    v-for="value in statuses"
                    :key="value"
                    @click="selectedStatus = selectedStatus === value ? null : value"
                    :class="[
                        'text-[10px] font-bold uppercase tracking-widest whitespace-nowrap px-2.5 py-1 rounded-full border transition-all',
                        selectedStatus === value
                            ? 'bg-primary text-white border-primary shadow-sm'
                            : 'bg-white/50 text-primary/60 border-primary/15 hover:border-primary/40 hover:text-primary/80'
                    ]"
                >
                    {{ value }} <span class="opacity-60">{{ casesByStatus[value] }}</span>
                </button>
            </div>
        </div>

        <!-- Lista de chats -->
        <div class="flex-1 overflow-y-auto">
            <button
                v-for="chat in chats"
                :key="chat.id"
                @click="openChat(chat)"
                :class="[
                    'w-full text-left px-4 py-3 flex items-start gap-3 border-b border-primary/5 transition-all',
                    selectedChat?.id === chat.id
                        ? 'bg-accent/40 border-l-4 border-l-primary'
                        : 'hover:bg-white/70'
                ]"
            >
                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0 mt-0.5">
                    <User v-if="pfp === undefined" class="text-lg translate-y-0.5" />
                </div>

                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-center mb-0.5">
                        <span class="text-sm font-semibold text-primary truncate">{{ chat.username }}</span>
                        <span class="text-[10px] text-primary/40 shrink-0 ml-1">{{ chat.messageTime }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <Check v-if="messageStatus === MessageStatus.Sent" class="text-primary/30 text-xs shrink-0" />
                        <DoubleCheck v-else-if="messageStatus === MessageStatus.Delivered" class="text-primary/30 text-xs shrink-0" />
                        <DoubleCheck v-else-if="messageStatus === MessageStatus.Viewed" class="text-sky-500 text-xs shrink-0" />
                        <span class="text-xs text-primary/50 truncate">{{ chat.lastMessage }}</span>
                    </div>
                </div>
            </button>
        </div>
    </div>

    <!-- Vista del chat seleccionado -->
    <router-view :selected-chat="selectedChat" class="flex-1 min-w-0" />

    </div>
</template>
