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

    const { statuses, casesByStatus, total } = useCaseStatus()
    const selectedChat = ref<Chat | null>(null)
    const selectedStatus = ref<CaseStatus | null>(null)

    const baseStatusStyle = 'bg-primary/10 backdrop-blur-lg backdrop-saturate-150 whitespace-nowrap border border-white/20 shadow-sm text-sm px-3 py-1 rounded-full transition hover:shadow-md hover:bg-primary hover:text-secondary focus:bg-accent focus:text-primary'

    const statusClassMap: Record<CaseStatus, string> = {
        [CaseStatus.New]: 'text-green-600',
        [CaseStatus.Interested]: 'text-txt',
        [CaseStatus.HighPriority]: 'text-red-600',
        [CaseStatus.Following]: 'text-cyan-500',
        [CaseStatus.Reserved]: 'text-fuchsia',
        [CaseStatus.Closed]: 'text-mauve-600',
    }

    const selectedStatusClass = 'bg-primary text-secondary border-primary shadow-md'

    const chats = ref<Chat[]>([
        { id: 1, username: 'Ana', lastMessage: 'Hola', messageTime: '10:20am', location: 'Caracas, Venezuela' },
        { id: 2, username: 'Luis', lastMessage: 'Esta disponible?', messageTime: '2:04pm', location: 'Barquisimeto, Valencia' },
        { id: 3, username: 'Carlos', lastMessage: 'Buenas, precio del curso', messageTime: '11:00am', location: 'Valencia, Venezuela' }
    ])

    const openChat = (chat: Chat) => {
        selectedChat.value = chat
    }

    // Carga la informacion relevante del usuario y lo carga mediante un v-for
    const pfp = ref()
    const messageStatus = ref(MessageStatus.Sent)

    const open = ref(false)
    const close = () => (open.value = false)
    const toggle = () => (open.value = !open.value)
</script>

<template>
    <div id="messages-desktop-menu" class="hidden md:flex md:w-1/5 md:flex-none md:min-w-0">
        <!-- Desktop Menu -->
        <transition name="slide">
            <div class="w-full min-w-0 bg-background h-full border-r-4 border-primary overflow-hidden">
                <header id="desktop-chats-filter" class="txt gap-2 flex flex-col p-1">
                    <section class="
                            group border-2 border-secondary rounded-xl
                            text-sm p-1 mt-1 gap-0.5
                            focus-within:border-secondary focus-within:bg-contrast
                            hover:border-accent-hover
                            flex flex-row
                        ">
                        <Search class="mt-1 text-txt group-hover:text-accent-hover"/>
                        <input id="search-bar" class="focus:outline-none" type="text">
                    </section>
                    <section class="scroll overflow-x-auto flex flex-row p-2 mr-0.5 gap-1.5">
                        <button
                            :class="[baseStatusStyle, selectedStatus === value ? selectedStatusClass : statusClassMap[value]]"
                            style="
                                backdrop-filter: blur(8px);
                                -webkit-backdrop-filter: blur(8px);
                            "
                            @click="selectedStatus = value"
                            type="button"
                            v-for="value in statuses"
                            :key="value"
                        >
                            {{ value }} {{ casesByStatus[value] }}
                        </button>
                    </section>
                </header>
                <div id="desktop-chats" class="p-1">
                    <!-- Se cargaran los datos de los chats de la base de datos con un v-for -->
                    <div class="txt transition group
                        p-2 rounded-md
                        flex flex-col"
                        v-for="chat in chats"
                        :key="chat.id"
                        :class="selectedChat?.id === chat.id ? 'bg-accent border-r-4 border-primary' : 'hover:bg-primary'"
                        @click="openChat(chat)"
                    >
                        <div class="flex flex-row justify-between">
                            <section class="font-primary text-primary group gap-2 flex flex-row">
                                <p :class="selectedChat?.id === chat.id ? 'text-primary' : 'group-hover:text-secondary'" v-if="pfp === undefined">
                                    <User />
                                </p>
                                <p v-else>
                                    {{ pfp }}
                                </p>
                                <p :class="selectedChat?.id === chat.id ? 'text-primary' : 'group-hover:text-secondary'">{{ chat.username }}</p>
                            </section>
                            <p :class="selectedChat?.id === chat.id ? 'text-xs' : 'text-xs group-hover:text-accent-hover'">{{ chat.messageTime }}</p>
                        </div>
                        <section class="group gap-2 flex flex-row">
                            <p v-if="messageStatus == MessageStatus.Sent">
                                <Check class="text-gray-700"/>
                            </p>
                            <p v-else-if="messageStatus == MessageStatus.Delivered">
                                <DoubleCheck class="text-gray-700"/>
                            </p>
                            <p v-else-if="messageStatus == MessageStatus.Viewed">
                                <DoubleCheck class="text-sky-700"/>
                            </p>
                            <p :class="selectedChat?.id === chat.id ? '' : 'group-hover:text-accent-hover'">{{ chat.lastMessage }}</p>
                        </section>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Mobile Menu -->
    </div>
    <router-view :selected-chat="selectedChat" />
</template>
