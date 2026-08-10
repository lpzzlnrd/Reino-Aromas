<script setup lang="ts">
    import { CaseStatus } from '@/hooks/caseStatus';
    import { useDashboard } from '@/hooks/useDashboard';

    // Los conteos vienen de GET /api/reports/summary. Antes se leían de
    // casesByStatus, que arrancaba en ceros y nadie llenaba nunca: las seis
    // tarjetas mostraban 0 permanentemente.
    const { countFor, loading } = useDashboard()

    // Sin badgeLabel calculado en el script: antes interpolaba
    // `chatIncrease.value` dentro de un array plano, así que el valor quedaba
    // congelado en el del primer render.
    const cards = [
        { label: 'Nuevos',      status: CaseStatus.New,          accent: 'from-violet-400 to-primary',   badge: 'text-primary/40', badgeLabel: 'sin atender' },
        { label: 'Interesados', status: CaseStatus.Interested,   accent: 'from-pink-400 to-secondary',   badge: 'text-green-600',  badgeLabel: 'con interés' },
        { label: 'Seguimiento', status: CaseStatus.Following,    accent: 'from-sky-400 to-cyan-500',     badge: 'text-sky-600',    badgeLabel: 'activos' },
        { label: 'Urgentes',    status: CaseStatus.HighPriority, accent: 'from-red-400 to-rose-600',     badge: 'text-red-600',    badgeLabel: '⚠ atención' },
        { label: 'Reservas',    status: CaseStatus.Reserved,     accent: 'from-fuchsia-400 to-pink-500', badge: 'text-green-600',  badgeLabel: 'totales' },
        { label: 'Cerrados',    status: CaseStatus.Closed,       accent: 'from-stone-400 to-stone-600',  badge: 'text-primary/40', badgeLabel: 'histórico' },
    ]
</script>

<template>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 w-full">
        <div
            v-for="card in cards"
            :key="card.label"
            class="relative overflow-hidden bg-white rounded-2xl shadow-sm border border-primary/8 p-5 flex flex-col gap-2 hover:-translate-y-0.5 hover:shadow-md transition-all duration-200"
        >
            <!-- Barra de color superior -->
            <div :class="`absolute top-0 left-0 right-0 h-1 bg-gradient-to-r ${card.accent} rounded-t-2xl`"></div>

            <p class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">{{ card.label }}</p>

            <div class="flex items-end gap-2">
                <!-- Placeholder mientras carga, para que no parpadee de 0 al valor real -->
                <span v-if="loading" class="inline-block w-10 h-9 bg-primary/8 rounded animate-pulse"></span>
                <p v-else class="text-4xl font-primary text-primary leading-none tabular-nums">{{ countFor(card.status) }}</p>

                <p v-if="card.badgeLabel && !loading" :class="`text-[11px] font-semibold mb-0.5 ${card.badge}`">{{ card.badgeLabel }}</p>
            </div>
        </div>
    </div>
</template>
