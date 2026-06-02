<script setup lang="ts">
    import { computed } from 'vue';
    import { useRoute } from 'vue-router';
    import { useAuth } from '@/composables/useAuth'

    import Search from '../../icons/icon.search.vue'
    import Bell from '../../icons/icon.bell.vue'
    import Info from '../../icons/icon.info.vue'

    const route = useRoute()
    const { user } = useAuth()

    const pageName = computed(() => (route.meta.title as string) ?? 'Panel de control')
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

            <!-- Avatar -->
            <div v-if="user" class="flex items-center gap-2 pl-3 border-l border-primary/10">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-secondary to-accent-hover flex items-center justify-center text-white text-xs font-bold shadow-sm shrink-0">
                    {{ user.name.charAt(0).toUpperCase() }}
                </div>
                <span class="hidden lg:block text-sm font-semibold text-primary/70 max-w-[100px] truncate">{{ user.name }}</span>
            </div>
        </div>
    </header>
</template>
