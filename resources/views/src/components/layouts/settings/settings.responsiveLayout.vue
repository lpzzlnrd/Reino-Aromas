<script setup lang="ts">
    import { ref } from 'vue'
    import { useRoute, useRouter } from 'vue-router'

    const router = useRouter()
    const route = useRoute()

    const baseBtn = 'text-left rounded-xl px-4 py-2.5 w-full text-sm font-semibold transition-all cursor-pointer'
    const inactiveBtn = 'text-primary/60 hover:bg-primary/6 hover:text-primary'
    const activeBtn = 'bg-gradient-to-r from-secondary/20 to-accent/20 text-primary border-l-4 border-secondary'

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
    <!-- Contenedor flex propio: el <main> padre no es flex, así que sin este
         wrapper el menú de configuración y el contenido se apilaban en vez de
         quedar lado a lado. -->
    <div class="flex h-full min-h-screen w-full">

    <!-- Panel lateral de configuración -->
    <div id="settings-desktop-menu" class="hidden md:flex md:w-52 md:flex-none shrink-0 border-r border-primary/10 bg-surface/60">
        <div class="w-full h-full flex flex-col px-3 py-5 gap-1">
            <p class="px-3 text-[10px] font-bold text-primary/40 uppercase tracking-widest mb-2">Configuración</p>
            <button :class="buttonClass('Accounts')" @click="goTo('Accounts')">
                Cuentas
            </button>
            <!-- "Estado de casos" salió de aquí en Semana 4: era el tablero
                 Kanban y ahora vive en /app/tickets, con su botón en el menú
                 principal. -->
            <button :class="buttonClass('Users')" @click="goTo('Users')">
                Administradores
            </button>
        </div>
    </div>
    <router-view class="flex-1 min-w-0" />

    </div>
</template>
