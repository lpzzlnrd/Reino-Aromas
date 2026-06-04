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

    const inputBtnStyle = 'p-2 cursor-pointer hover:bg-primary/8 text-primary/50 hover:text-primary rounded-xl transition-colors'
    const clientText = 'max-w-sm text-left px-4 py-2.5 bg-white border border-primary/10 rounded-2xl rounded-tl-sm shadow-sm text-sm text-primary leading-relaxed'
    const adminText = 'max-w-sm ml-auto text-left px-4 py-2.5 bg-gradient-to-br from-secondary/30 to-accent/40 border border-secondary/20 rounded-2xl rounded-tr-sm shadow-sm text-sm text-primary leading-relaxed'

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
    <div id="desktop-open-chats" class="h-full relative flex flex-col">
        <!-- Estado vacío: sin chat seleccionado -->
        <div v-if="!props.selectedChat && isDesktop" class="absolute inset-0 flex flex-col items-center justify-center text-center gap-6 px-8">
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
            </div>
        </div>

        <!-- Chat abierto -->
        <div v-else-if="props.selectedChat" class="flex flex-row w-full h-full">
            <!-- Panel de mensajes -->
            <div class="h-full flex-1 flex flex-col min-w-0">
                <!-- Header del chat -->
                <header class="px-5 py-3.5 border-b border-primary/8 bg-white/60 backdrop-blur-sm flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0">
                            <User class="text-xl translate-y-0.5" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-primary leading-tight">{{ props.selectedChat.username }}</p>
                            <p class="flex items-center gap-1 text-[11px] text-primary/50">
                                <Location class="text-xs"/>
                                {{ props.selectedChat.location }}
                            </p>
                        </div>
                    </div>
                    <button type="button"
                        class="text-xs font-bold uppercase tracking-widest px-4 py-2 rounded-xl border-2 border-secondary/40 text-primary/60 hover:border-primary hover:bg-primary hover:text-white transition-all cursor-pointer">
                        Cerrar ticket
                    </button>
                </header>

                <!-- Área de mensajes -->
                <div class="flex-1 overflow-y-auto scroll p-5 flex flex-col gap-3">
                    <p :class="clientText">{{ props.selectedChat.lastMessage }}</p>
                </div>

                <!-- Input bar -->
                <footer class="px-4 py-3 border-t border-primary/8 bg-white/40 shrink-0">
                    <div class="flex items-center gap-2 bg-white border border-primary/12 rounded-2xl px-3 py-2 shadow-sm focus-within:border-secondary/50 transition-colors">
                        <input
                            class="flex-1 text-sm text-primary placeholder:text-primary/30 focus:outline-none bg-transparent"
                            type="text"
                            placeholder="Escribe un mensaje..."
                        >
                        <div class="flex items-center gap-0.5">
                            <button type="button" :class="inputBtnStyle">
                                <Clip />
                            </button>
                            <button type="button" :class="inputBtnStyle + ' bg-gradient-to-br from-secondary to-accent-hover text-white hover:brightness-110 rounded-xl px-3 py-1.5'">
                                <Sent />
                            </button>
                        </div>
                    </div>
                </footer>
            </div>

            <Info class="w-64 shrink-0"/>
        </div>
    </div>
</template>
