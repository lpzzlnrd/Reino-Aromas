<script setup lang="ts">
    import { computed } from 'vue'
    import { useDashboard, type ActivityItem } from '@/hooks/useDashboard'
    import User from '../../icons/icon.user.vue'
    // Icono de chat para los eventos que originó una automatización (un Flow).
    import Automation from '../../icons/icon.chat.vue'

    // La actividad sale de GET /api/reports/activity, que lee activity_logs.
    //
    // La maqueta anterior mostraba cuatro items fijos con textos de "compró",
    // "reservó" y "completó el pago". Pagos y ventas están FUERA del alcance
    // acordado, así que esos eventos no existen ni van a existir: prometían
    // una función inexistente. Ahora se muestra lo que el CRM registra de
    // verdad — tickets, cambios de estado y uso de plantillas.
    const { activity } = useDashboard()

    /** Etiqueta corta y color, según el tipo de acción. */
    const badgeFor = (item: ActivityItem): { text: string; class: string } => {
        if (item.action.startsWith('template.')) {
            return { text: 'Plantilla', class: 'bg-accent/30 text-primary border-accent/50' }
        }
        if (item.action === 'ticket.created') {
            return { text: 'Nuevo', class: 'bg-primary/8 text-primary border-primary/15' }
        }
        if (item.action === 'ticket.qualified_automatically') {
            return { text: 'Automático', class: 'bg-violet-100 text-violet-700 border-violet-200' }
        }
        if (item.action.includes('assigned')) {
            return { text: 'Asignación', class: 'bg-sky-100 text-sky-700 border-sky-200' }
        }
        return { text: 'Ticket', class: 'bg-secondary/20 text-primary border-secondary/30' }
    }

    /** "Hace 5 minutos", "Hace 2 horas", "Ayer"… */
    const relativeTime = (iso: string): string => {
        const diff = Date.now() - new Date(iso).getTime()
        const mins = Math.floor(diff / 60000)

        if (mins < 1) return 'Hace un momento'
        if (mins < 60) return `Hace ${mins} ${mins === 1 ? 'minuto' : 'minutos'}`

        const hours = Math.floor(mins / 60)
        if (hours < 24) return `Hace ${hours} ${hours === 1 ? 'hora' : 'horas'}`

        const days = Math.floor(hours / 24)
        if (days === 1) return 'Ayer'
        if (days < 30) return `Hace ${days} días`

        return new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })
    }

    const items = computed(() => activity.value.slice(0, 8))
</script>

<template>
    <div class="glass-card h-full p-6 flex flex-col gap-4">
        <header class="flex items-center justify-between border-b border-primary/8 pb-4">
            <h2 class="text-xl font-primary text-primary">Actividad reciente</h2>
            <span class="text-[10px] font-bold text-secondary uppercase tracking-widest opacity-70">
                Últimos movimientos
            </span>
        </header>

        <!-- Sin actividad -->
        <div v-if="items.length === 0" class="flex-1 flex items-center justify-center text-center px-6">
            <p class="text-sm text-primary/40">
                Todavía no hay actividad registrada.<br>
                Aparecerá aquí cuando el equipo empiece a mover tickets.
            </p>
        </div>

        <div v-else class="flex flex-col gap-1 flex-1 overflow-y-auto scroll">
            <div
                v-for="item in items"
                :key="item.id"
                class="flex items-center justify-between p-3 rounded-xl hover:bg-primary/4 transition-colors"
            >
                <div class="flex items-center gap-3 min-w-0">
                    <div
                        class="w-9 h-9 rounded-full flex items-center justify-center shrink-0"
                        :class="item.automatic
                            ? 'bg-gradient-to-br from-violet-200 to-violet-400 text-violet-900'
                            : 'bg-gradient-to-br from-secondary/40 to-accent/60 text-primary'"
                    >
                        <Automation v-if="item.automatic" class="text-base translate-y-0.5" />
                        <User v-else class="text-lg translate-y-0.5" />
                    </div>

                    <div class="min-w-0">
                        <p class="text-sm text-primary leading-tight truncate">
                            <span class="font-semibold">{{ item.automatic ? 'Sistema' : (item.actor ?? 'Alguien') }}</span>
                            <span class="font-normal text-txt"> · {{ item.description }}</span>
                        </p>
                        <p class="text-[11px] text-primary/40 mt-0.5">{{ relativeTime(item.created_at) }}</p>
                    </div>
                </div>

                <span
                    class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full border shrink-0 ml-2"
                    :class="badgeFor(item).class"
                >
                    {{ badgeFor(item).text }}
                </span>
            </div>
        </div>
    </div>
</template>
