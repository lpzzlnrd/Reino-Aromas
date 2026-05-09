<script setup lang="ts">
    // import Logo from '../../icons/logo.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Home from '../../icons/icon.home.vue'
    import Message from '../../icons/icon.chat.vue'
    import Users from '../../icons/icon.users.vue'
    import Chart from '../../icons/icon.chart.vue'
    import User from '../../icons/icon.user.vue'
    import Gear from '../../icons/icon.setting.vue'
</script>

<template>
    <div class="flex flex-row">
    <div class="bg-contrast h-full w-54 flex flex-col">
        <div>
            <div class='mt-4 gap-2 flex flex-col items-center justify-center'>
                <!-- Aqui importaremos el logo -->
                <span class="title">Reino Aromas</span>
                <button class="txt border-2 border-text hover:border-subtitle bg-accent hover:bg-accent-hover px-4 py-1 w-40 rounded-lg">
                    <plus />
                    <span>Nueva Venta</span>
                </button>
            </div>
        </div>
        <div>
            <hr class="txt my-30 ml-3 w-48 min-w-30">
            <div class='txt gap-2 flex flex-col items-center justify-center'>
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <Home />
                    Home
                </button>
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <Message />
                    Chats
                </button>
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <Users />
                    Usuarios
                </button>
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <Chart />
                    Reportes
                </button>
            </div>
        </div>
        <hr class="txt mt-30 ml-3 w-48 min-w-30">
        <div class="txt mt-23 p-4">
            <footer class="gap-2 flex flex-col text-left items-center justify-center">
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <User />
                    Perfil
                </button>
                <button type:toggle class="focus:border-r-4 border-text hover:border-subtitle hover:cursor-pointer hover:bg-accent-hover focus:bg-accent my-0 px-4 py-2 w-40 max-w-40 rounded-md">
                    <Gear />
                    Ajustes
                </button>
            </footer>
        </div>
    </div>
        <router-view />
    </div>
</template>
