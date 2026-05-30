<script setup lang="ts">
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

    import CheckMark from '../../icons/icon.checkMark.vue'
    import Comments from '../../icons/icon.comments.vue'
    import User from '../../icons/icon.user.vue'
    import Location from '../../icons/icon.location.vue'
    import Sent from '../../icons/icon.sent.vue'
    import Clip from '../../icons/icon.clip.vue'

    import Info from './messages.chatInfo.vue'

    const isDesktop = ref(false)
    const completedTickets = ref(0)

    const inputBtnStyle = 'p-2 cursor-pointer hover:bg-primary hover:text-secondary rounded-full'
    const clientText = 'mt-auto text-left w-1/3 p-2 bg-accent rounded-br-md rounded-t-md'
    const adminText = ''

    const props = defineProps<{
        selectedChat: {
            id: number;
            username: string;
            lastMessage: string;
            messageTime: string;
            location: string;
        } | null
    }>()

    const checkScreen = () => {
        isDesktop.value = window.innerWidth >= 768
    }

    onMounted(() => {
        checkScreen()
        window.addEventListener('resize', checkScreen)
    })

    onBeforeUnmount(() => {
        window.removeEventListener('resize', checkScreen)
    })
</script>

<template>
    <div id="desktop-open-chats" class="h-full txt relative">
        <!-- Default chat -->
        <div v-if="!props.selectedChat && isDesktop" class="absolute inset-0 flex flex-col items-center justify-center text-center gap-10">
            <section class="w-60 h-60 rounded-full bg-secondary flex items-center justify-center">
                <Comments class="text-primary text-9xl"/>
            </section>
            <section class="flex flex-col gap-1">
                <p>Chats</p>
                <p>Seleccione un chat para gestionar sus ventas y asesorias</p>
                <p>
                    <CheckMark class="text-green-700"/>
                    Tickets resueltos
                    {{ completedTickets }}
                </p>
            </section>
        </div>

        <!-- Chat abierto -->
        <div v-else-if="props.selectedChat" class="flex flex-row w-full h-full justify-between">
            <!-- Chat -->
            <div class="h-full w-full flex flex-col p-4">
                <header class="w-full flex flex-row gap-2 justify-between">
                    <div class="flex flex-row">
                        <User class="text-5xl text-primary"/>
                        <section class="flex flex-col">
                            <p class="text-primary font-primary">{{ props.selectedChat.username }}</p>
                            <p class="txt">
                                <Location class="text-primary"/>
                                {{ props.selectedChat.location }}
                            </p>
                        </section>
                    </div>
                    <button type="button"
                        class="transition cursor-pointer p-2 border-2 border-secondary rounded-lg
                        hover:bg-primary hover:text-secondary hover:border-primary"
                        >Cerrar ticket</button>
                </header>

                <!-- Mensajes -->
                <div class="my-4 px-12 h-full min-w-2/3 max-w-full">
                    <p :class="clientText">{{ props.selectedChat.lastMessage }}</p>
                </div>

                <footer class="mt-auto">
                    <section class="border-2 p-1 border-secondary rounded-lg flex flex-row">
                        <input class="w-full focus:outline-none" type="text">
                        <section class="flex flex-row gap-0.5">
                            <button type="button" :class="inputBtnStyle">
                                <Clip />
                            </button>
                            <button type="button" :class="inputBtnStyle">
                                <Sent />
                            </button>
                        </section>
                    </section>
                </footer>
            </div>

            <Info class=""/>
        </div>
    </div>
</template>
