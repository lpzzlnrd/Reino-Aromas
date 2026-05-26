<script setup lang="ts">
    import { ref } from 'vue'

    import Logo from '../../logo.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Home from '../../icons/icon.home.vue'
    import Message from '../../icons/icon.chat.vue'
    import Users from '../../icons/icon.users.vue'
    import Chart from '../../icons/icon.chart.vue'
    import User from '../../icons/icon.user.vue'
    import Gear from '../../icons/icon.setting.vue'
    import Close from '../../icons/icon.close.vue'
    import Bars from '../../icons/icon.bars.vue'

    const open = ref(false)
    const close = () => (open.value = false)
    const toggle = () => (open.value = !open.value)
</script>

<template>
    <div class="min-h-screen mesh-bg flex flex-col lg:flex-row font-secondary">
        <!-- Mobile Header -->
        <header class="md:hidden items-center justify-between p-4 bg-background/80 backdrop-blur-md border-b border-primary/10 flex sticky top-0 z-50">
            <button @click="toggle" aria-label="Abrir menú" class="p-2 rounded-xl bg-accent/20 text-primary">
                <Bars class="text-xl" />
            </button>
            <Logo class="w-24" />
            <button id="new-sell-btn" class="btn-primary scale-90">
                <Plus />
                <span>Venta</span>
            </button>
        </header>

        <!-- Menu -->
        <aside id="menu" class="z-40">
            <!-- Desktop Menu -->
            <div id="desktop-menu" class="hidden md:flex glass-sidebar h-screen w-64 flex-col sticky top-0 px-4 py-6">
                <!-- Desktop Menu Header -->
                <div id="menu-header" class='mb-8 px-2 flex flex-col items-center gap-3'>
                    <Logo class="w-32 drop-shadow-sm" />
                    <div class="text-center">
                        <p class="text-primary font-primary text-2xl tracking-tight leading-tight">Reino Aromas</p>
                        <p class="text-[10px] text-secondary font-bold uppercase tracking-[0.2em] opacity-80">Gourmet & Artesanal</p>
                    </div>
                </div>

                <div class="flex-1 flex flex-col gap-8 overflow-y-auto custom-scrollbar">
                    <!-- Quick Actions -->
                    <div class="px-2">
                        <button id="new-sell-btn" class="btn-primary w-full shadow-lg shadow-secondary/20 group">
                            <Plus class="group-hover:rotate-90 transition-transform" />
                            <span>Nueva Venta</span>
                        </button>
                    </div>

                    <!-- Desktop Main Pages -->
                    <nav id="pages-div" class="flex flex-col gap-1">
                        <p class="px-4 text-[11px] font-bold text-primary/40 uppercase tracking-widest mb-2">Principal</p>
                        <button id="home-btn" class="nav-item">
                            <Home />
                            <span>Dashboard</span>
                        </button>
                        <button id="chats-btn" class="nav-item">
                            <Message />
                            <span>Mensajería</span>
                        </button>
                        <button id="users-btn" class="nav-item">
                            <Users />
                            <span>Clientes</span>
                        </button>
                        <button id="reports-btn" class="nav-item">
                            <Chart />
                            <span>Reportes</span>
                        </button>
                    </nav>

                    <!-- Desktop Options & User Pages -->
                    <nav id="options-div" class="mt-auto pb-4 flex flex-col gap-1">
                        <p class="px-4 text-[11px] font-bold text-primary/40 uppercase tracking-widest mb-2">Sistema</p>
                        <button id="profile-btn" class="nav-item">
                            <User />
                            <span>Mi Perfil</span>
                        </button>
                        <button id="setting-btn" class="nav-item">
                            <Gear />
                            <span>Ajustes</span>
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="mobile-menu">
                <transition name="slide">
                    <div v-if="open" class="fixed inset-0 z-50 flex md:hidden">
                        <div class="w-72 bg-background/95 backdrop-blur-xl h-full shadow-2xl flex flex-col p-6">
                            <!-- Mobile Menu Header -->
                            <header class="flex items-center justify-between mb-10">
                                <Logo class="w-24" />
                                <button @click="close" aria-label="Cerrar menú" class="p-2 rounded-full bg-accent/20 text-primary">
                                    <Close class="text-xl" />
                                </button>
                            </header>

                            <!-- Mobile Navigation -->
                            <nav class="flex flex-col gap-4 flex-1">
                                <button @click="close" class="flex items-center gap-4 text-primary font-medium p-3 rounded-2xl bg-accent/10">
                                    <Home class="text-xl" />
                                    <span>Dashboard</span>
                                </button>
                                <button @click="close" class="flex items-center gap-4 text-primary/70 font-medium p-3">
                                    <Message class="text-xl" />
                                    <span>Mensajería</span>
                                </button>
                                <button @click="close" class="flex items-center gap-4 text-primary/70 font-medium p-3">
                                    <Users class="text-xl" />
                                    <span>Clientes</span>
                                </button>
                                <button @click="close" class="flex items-center gap-4 text-primary/70 font-medium p-3">
                                    <Chart class="text-xl" />
                                    <span>Reportes</span>
                                </button>
                            </nav>

                            <footer class="mt-auto border-t border-primary/10 pt-6">
                                <button @click="close" class="flex items-center gap-4 text-primary/70 font-medium p-3 w-full">
                                    <User class="text-xl" />
                                    <span>Mi Perfil</span>
                                </button>
                            </footer>
                        </div>
                        <div class="flex-1 bg-primary/20 backdrop-blur-sm" @click="close"></div>
                    </div>
                </transition>
            </div>
        </aside>

        <!-- Content Area -->
        <main class="flex-1 min-w-0 overflow-y-auto">
            <router-view />
        </main>
    </div>
</template>

<style scoped>
    .slide-enter-active, .slide-leave-active {
        transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .slide-enter-from {
        transform: translateX(-100%);
    }
    .slide-enter-to {
        transform: translateX(0);
    }
    .slide-leave-from {
        transform: translateX(0);
    }
    .slide-leave-to {
        transform: translateX(-100%);
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(109, 18, 63, 0.1);
        border-radius: 10px;
    }
</style>
