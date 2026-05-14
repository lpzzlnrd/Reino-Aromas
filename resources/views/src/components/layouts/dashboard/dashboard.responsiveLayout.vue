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
    <div class="min-h-screen bg-linear-to-b from-primary via-secondary to-background flex flex-col lg:flex-row">
        <!-- Mobile Header -->
        <header class="md:hidden items-center justify-between p-3 bg-background border-b-2 border-primary flex">
            <button @click="toggle" aria-label="Abrir menú" class="p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                <Bars class="text-primary text-xl" />
            </button>
            <Logo class="w-28" />
            <button id="new-sell-btn" class="btn-primary">
                <Plus />
                <span class="ml-2">Nueva Venta</span>
            </button>
        </header>

        <!-- Menu -->
        <div id="menu">
            <!-- Desktop Menu -->
            <div id="desktop-menu" class="hidden md:flex sidebar-bg h-full rounded-tr-2xl rounded-br-2xl w-56 flex-col shadow-sm">
                <!-- Desktop Menu Header -->
                <div id="menu-header" class='mt-4 gap-2 flex flex-col items-center justify-center'>
                    <Logo class="w-30" />
                    <p class="text-primary font-primary text-xl">Reino Aromas</p>
                    <button id="new-sell-btn" class="btn-primary">
                        <plus />
                        <p>Nueva Venta</p>
                    </button>
                </div>

                <hr class="border-t border-secondary opacity-30 my-10 mx-6">

                <!-- Desktop Main Pages -->
                <div id="pages-div">
                    <div class='font-secondary gap-2 flex flex-col items-center justify-center'>
                        <button id="home-btn" class="nav-item">
                            <Home />
                            Home
                        </button>
                        <button id="chats-btn" class="nav-item">
                            <Message />
                            Chats
                        </button>
                        <button id="users-btn" class="nav-item">
                            <Users />
                            Usuarios
                        </button>
                        <button id="reports-btn" class="nav-item">
                            <Chart />
                            Reportes
                        </button>
                    </div>
                </div>

                <hr class="border-t border-secondary opacity-30 my-10 mx-6">

                <!-- Desktop Options & User Pages -->
                <div id="options-div" class="font-secondary mt-23 p-4">
                    <footer class="gap-2 flex flex-col text-left items-center justify-center">
                        <button id="profile-btn" class="nav-item">
                            <User />
                            Perfil
                        </button>
                        <button id="setting-btn" class="nav-item">
                            <Gear />
                            Ajustes
                        </button>
                    </footer>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="mobile-menu">
                <transition name="slide">
                    <div v-if="open" class="fixed inset-0 z-40 flex md:hidden">
                        <div class="w-64 bg-background border-r-2 border-primary p-4 overflow-y-auto">
                            <!-- Mobile Menu Header -->
                            <header class="flex flex-col">
                                <section class="flex flex-row justify-between">
                                    <Logo class="w-24" />
                                    <button @click="close" aria-label="Cerrar menú" class="p-2">
                                        <Close class="text-primary text-xl" />
                                    </button>
                                </section>
                                <p class="title text-2xl">Reino Aromas</p>
                            </header>

                            <hr class="border-t border-secondary opacity-20 my-8 mx-4">

                            <!-- Mobile Main Pages -->
                            <div>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Home />
                                    Home
                                </button>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Message />
                                    Chats
                                </button>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Users />
                                    Usuarios
                                </button>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Chart />
                                    Reportes
                                </button>
                            </div>

                            <hr class="border-t border-secondary opacity-20 my-8 mx-4">

                            <!-- Mobile Options & User Pages -->
                            <div>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <User />
                                    Perfil
                                </button>
                                <button @click="close" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Gear />
                                    Ajustes
                                </button>
                            </div>
                        </div>
                        <section class="flex-1 bg-black bg-opacity-30" @click="close"></section>
                    </div>
                </transition>
            </div>
        </div>
        <router-view />
    </div>
</template>

<style scoped>
    .slide-enter-active, .slide-leave-active {
    transition: transform 0.2s ease;
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
</style>
