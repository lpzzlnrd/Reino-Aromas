<script setup lang="ts">
    import { computed } from 'vue'
    import { useTheme, type Tema } from '@/composables/useTheme'

    const { tema, efectivo, establecer } = useTheme()

    /*
     * Tres estados en un solo boton, ciclando claro -> oscuro -> sistema.
     *
     * Se prefirio esto a un desplegable porque vive en la barra superior, al
     * lado de campana e info: un <select> ahi rompe el ritmo visual y obliga a
     * dos clics para algo que se cambia de pasada.
     */
    const CICLO: Record<Tema, Tema> = {
        light: 'dark',
        dark: 'system',
        system: 'light',
    }

    const ETIQUETAS: Record<Tema, string> = {
        light: 'Tema claro',
        dark: 'Tema oscuro',
        system: 'Tema del sistema',
    }

    const etiqueta = computed(() => ETIQUETAS[tema.value])

    const siguiente = computed(() => ETIQUETAS[CICLO[tema.value]])

    const avanzar = (): void => establecer(CICLO[tema.value])
</script>

<template>
    <button
        @click="avanzar"
        type="button"
        class="relative p-2 rounded-xl hover:bg-surface/60 text-primary/50 hover:text-primary transition-all cursor-pointer"
        :aria-label="`${etiqueta}. Cambiar a ${siguiente}`"
        :title="etiqueta"
    >
        <!-- Sol: tema claro -->
        <svg v-if="tema === 'light'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
        </svg>

        <!-- Luna: tema oscuro -->
        <svg v-else-if="tema === 'dark'" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>

        <!-- Monitor: sigue al sistema -->
        <svg v-else class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2" />
            <path d="M8 21h8M12 17v4" />
        </svg>

        <!-- Con 'system' activo, un punto indica que se esta pintando en
             oscuro: sin esto el icono de monitor no dice cual de los dos
             temas esta viendo el usuario. -->
        <span
            v-if="tema === 'system' && efectivo === 'dark'"
            class="absolute top-1.5 right-1.5 w-1.5 h-1.5 bg-secondary rounded-full"
            aria-hidden="true"
        />
    </button>
</template>
