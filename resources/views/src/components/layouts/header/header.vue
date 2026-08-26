<script setup lang="ts">
    import { computed, onBeforeUnmount, ref, watch } from 'vue';
    import { useRoute } from 'vue-router';
    import { useAuth } from '@/composables/useAuth'

    import Search from '../../icons/icon.search.vue'
    import Bell from '../../icons/icon.bell.vue'
    import Info from '../../icons/icon.info.vue'

    const route = useRoute()
    const { user, logout } = useAuth()

    const pageName = computed(() => (route.meta.title as string) ?? 'Panel de control')
    const menuOpen = ref(false)

    /*
     * El menu se cerraba SOLO con @mouseleave, y eso lo dejaba sin salida para
     * teclado y para tactil: en el telefono el mouseleave se dispara de forma
     * erratica y podia cerrarse antes de que el dedo llegara a "Cerrar sesion".
     * Ahora cierra con Escape y con un clic fuera, que es lo que la gente
     * intenta primero.
     */
    const alPresionarTecla = (evento: KeyboardEvent): void => {
        if (evento.key === 'Escape') menuOpen.value = false
    }

    const alClicFuera = (evento: MouseEvent): void => {
        const objetivo = evento.target as HTMLElement | null

        if (objetivo?.closest('[data-menu-usuario]') === null) menuOpen.value = false
    }

    watch(menuOpen, (abierto) => {
        if (abierto) {
            document.addEventListener('keydown', alPresionarTecla)
            document.addEventListener('click', alClicFuera, true)
        } else {
            document.removeEventListener('keydown', alPresionarTecla)
            document.removeEventListener('click', alClicFuera, true)
        }
    })

    onBeforeUnmount(() => {
        document.removeEventListener('keydown', alPresionarTecla)
        document.removeEventListener('click', alClicFuera, true)
    })
</script>

<template>
    <header class="flex items-center justify-between gap-4">

        <div>
            <h2 class="text-lg font-semibold text-primary/80">{{ pageName }}</h2>
        </div>

        <div class="flex items-center gap-2 lg:gap-3">

            <!-- Buscador -->
            <label class="input-group w-40 lg:w-56 cursor-text group">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <input
                    id="search-bar"
                    type="text"
                    placeholder="Buscar..."
                    class="bg-transparent text-sm focus:outline-none w-full placeholder:text-primary/30"
                >
            </label>

            <!-- Notificaciones -->
            <button class="relative p-2 rounded-xl hover:bg-white/60 text-primary/50 hover:text-primary transition-all" type="button" aria-label="Notificaciones">
                <Bell class="text-lg" />
                <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-secondary rounded-full border border-white"></span>
            </button>

            <!-- Info -->
            <button class="p-2 rounded-xl hover:bg-white/60 text-primary/50 hover:text-primary transition-all" type="button" aria-label="Información">
                <Info class="text-lg" />
            </button>

            <!-- Avatar + menú -->
            <div v-if="user" data-menu-usuario class="relative pl-3 border-l border-primary/10">
                <button
                    @click="menuOpen = !menuOpen"
                    :aria-expanded="menuOpen"
                    aria-haspopup="menu"
                    :aria-label="`Menú de ${user.name}`"
                    class="flex items-center gap-2 rounded-xl hover:bg-white/60 px-2 py-1 transition-all"
                    type="button"
                >
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-secondary to-accent-hover flex items-center justify-center text-white text-xs font-bold shadow-sm shrink-0">
                        {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                    <span class="hidden lg:block text-sm font-semibold text-primary/70 max-w-[100px] truncate">{{ user.name }}</span>
                </button>

                <div
                    v-if="menuOpen"
                    role="menu"
                    class="absolute right-0 top-full mt-2 w-40 bg-white rounded-xl shadow-lg border border-primary/10 py-1 z-50"
                >
                    <button
                        @click="logout"
                        role="menuitem"
                        class="w-full text-left px-4 py-2 text-sm text-red-500 hover:bg-red-50 transition-colors"
                        type="button"
                    >
                        Cerrar sesión
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
