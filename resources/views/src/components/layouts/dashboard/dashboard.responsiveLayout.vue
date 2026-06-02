<script setup lang="ts">
    import { ref } from 'vue'
    import { useRoute, useRouter } from 'vue-router'

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

    const route = useRoute()
    const router = useRouter()

    const baseBtn = 'text-left border-primary rounded-md my-0 px-4 py-2 w-40 max-w-40 transition'
    const inactiveBtn = 'text-txt hover:cursor-pointer hover:bg-primary hover:text-secondary'
    const activeBtn = 'text-primary border-r-4 bg-accent'

    const isActive = (name: string | string[]) => {
        const current = route.name as string | undefined
        if (!current) return false
        if (Array.isArray(name)) return name.includes(current)
        return current === name
    }

    const buttonClass = (name: string | string[]) => `${baseBtn} ${isActive(name) ? activeBtn : inactiveBtn}`

    const goTo = (name: string) => {
        router.push({ name })
    }

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
            <button id="new-sell-btn" class="font-txt text-txt border-2 border-primary hover:bg-accent-hover hover:text-secondary px-3 py-1 rounded-lg flex items-center">
                <Plus />
                <span class="ml-2">Nueva Venta</span>
            </button>
        </header>

        <!-- Menu -->
        <div id="dashboard-menu">
            <!-- Desktop Menu -->
            <div id="dashboard-desktop-menu" class="hidden md:flex bg-background h-full border-r-4 border-primary rounded-tr-2xl rounded-br-2xl w-54 flex-col">
                <!-- Desktop Menu Header -->
                <div id="dashboard-menu-header" class='mt-4 gap-2 flex flex-col items-center justify-center'>
                    <Logo class="w-30" />
                    <p class="text-primary font-primary text-xl">Reino Aromas</p>
                    <button id="new-sell-btn" class="font-txt text-txt border-2 border-secondary hover:border-subtitle hover:bg-primary hover:border-primary hover:text-secondary px-4 py-1 w-40 rounded-lg flex flex-row">
                        <plus />
                        <p>Nueva Venta</p>
                    </button>
                </div>

                <hr class="text-secondary bg-secondary p-0.5 my-20 ml-3 w-48 min-w-30 rounded-full">

                <!-- Desktop Main Pages -->
                <div id="pages-div">
                    <div class='font-secondary gap-2 flex flex-col items-center justify-center'>
                        <button id="home-btn" :class="buttonClass('Dashboard Home')" type:toggle @click="goTo('Dashboard Home')">
                            <Home />
                            Home
                        </button>
                        <button id="chats-btn" :class="buttonClass('Messages Home')" type:toggle @click="goTo('Messages Home')">
                            <Message />
                            Chats
                        </button>
                        <button id="users-btn" type:toggle class="
                            text-txt text-left
                            border-primary rounded-md
                            my-0 px-4 py-2 w-40 max-w-40 transition
                            focus:text-primary focus:border-r-4  focus:bg-accent
                            hover:cursor-pointer hover:bg-primary hover:text-secondary
                            focus:hover:text-secondary focus:hover:border-secondary
                        ">
                           <Users />
                            Usuarios
                        </button>
                        <button id="reports-btn" type:toggle class="
                            text-txt text-left
                            border-primary rounded-md
                            my-0 px-4 py-2 w-40 max-w-40 transition
                            focus:text-primary focus:border-r-4  focus:bg-accent
                            hover:cursor-pointer hover:bg-primary hover:text-secondary
                            focus:hover:text-secondary focus:hover:border-secondary
                        ">
                            <Chart />
                            Reportes
                        </button>
                    </div>
                </div>

                <hr class="text-secondary bg-secondary p-0.5 my-20 ml-3 w-48 min-w-30 rounded-full">

                <!-- Desktop Options & User Pages -->
                <div id="options-div" class="font-secondary p-4">
                    <footer class="gap-2 flex flex-col text-left items-center justify-center">
                        <button id="profile-btn" type:toggle class="
                            text-txt text-left
                            border-primary rounded-md
                            my-0 px-4 py-2 w-40 max-w-40 transition
                            focus:text-primary focus:border-r-4  focus:bg-accent
                            hover:cursor-pointer hover:bg-primary hover:text-secondary
                            focus:hover:text-secondary focus:hover:border-secondary
                        ">
                            <User />
                            Perfil
                        </button>
                        <button id="settings-btn" :class="buttonClass('Dashboard Settings')" type:toggle @click="goTo('Dashboard Settings')">
                            <Gear />
                            Ajustes
                        </button>
                    </footer>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="dashboard-mobile-menu">
                <transition name="slide">
                    <div v-if="open" class="fixed inset-0 z-40 flex md:hidden">
                        <div class="w-64 bg-background border-r-2 border-primary p-4 overflow-y-auto">
                            <!-- Mobile Menu Header -->
                            <header class="flex flex-col">
                                <section class="flex flex-row justify-between">
                                    <Logo class="w-24" />
                                    <button @click="close()" aria-label="Cerrar menú" class="p-2">
                                        <Close class="text-primary text-xl" />
                                    </button>
                                </section>
                                <p class="title text-2xl">Reino Aromas</p>
                            </header>

                            <hr class="text-secondary bg-secondary p-0.5 my-32 rounded-full">

                            <!-- Mobile Main Pages -->
                            <div>
                                <button @click="goTo('Dashboard Home'); close()" :class="buttonClass('Dashboard Home')">
                                    <Home />
                                    Home
                                </button>
                                <button @click="goTo('Messages Home'); close()" :class="buttonClass('Messages Home')">
                                    <Message />
                                    Chats
                                </button>
                                <button @click="close()" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Users />
                                    Usuarios
                                </button>
                                <button @click="close()" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <Chart />
                                    Reportes
                                </button>
                            </div>

                            <hr class="text-secondary bg-secondary p-0.5 my-32 rounded-full">

                            <!-- Mobile Options & User Pages -->
                            <div>
                                <button @click="close()" class="w-full text-left text-txt px-4 py-2 rounded-md flex items-center gap-2">
                                    <User />
                                    Perfil
                                </button>
                                <button @click="goTo('Dashboard Settings'); close()" :class="buttonClass('Dashboard Settings')">
                                    <Gear />
                                    Ajustes
                                </button>
                            </div>
                        </div>
                        <section class="flex-1 bg-black bg-opacity-30" @click="close()"></section>
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
