<script setup lang="ts">
    import { ref } from 'vue';
    import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
    import { faComments, faTriangleExclamation, faCalendarCheck, faBoxArchive } from '@fortawesome/free-solid-svg-icons'

    type Chat = 'activo' | 'urgente' | 'nuevo' | 'cerrado'

    const clientChats: Record<Chat, number> = {
        activo: 10,
        urgente: 2,
        nuevo: 3,
        cerrado: 4,
    }

    const chatIncrease = ref(5)

</script>

<template>
    <div id="resume-div" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 w-full">
        
        <!-- Active Chats -->
        <div id="active-chats-div" class="glass-card p-6 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 text-primary opacity-5 group-hover:opacity-10 transition-opacity">
                <FontAwesomeIcon :icon="faComments" class="text-8xl rotate-12" />
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-accent/20 flex items-center justify-center text-primary">
                    <FontAwesomeIcon :icon="faComments" />
                </div>
                <p class="text-xs font-bold uppercase tracking-wider text-primary opacity-60">Chats Activos</p>
            </div>
            <section class="flex items-baseline gap-2">
                <p id="active-chats-amount" class="text-4xl font-primary text-primary">{{ clientChats.activo }}</p>
                <p id="active-chats-augment" class="text-green-600 text-xs font-bold">+{{ chatIncrease }}%</p>
            </section>
        </div>

        <!-- High Priority -->
        <div id="high-priority-div" class="glass-card p-6 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 text-red-700 opacity-5 group-hover:opacity-10 transition-opacity">
                <FontAwesomeIcon :icon="faTriangleExclamation" class="text-8xl rotate-12" />
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-red-700">
                    <FontAwesomeIcon :icon="faTriangleExclamation" />
                </div>
                <p class="text-xs font-bold uppercase tracking-wider text-primary opacity-60">Alta Prioridad</p>
            </div>
            <section class="flex items-baseline gap-2">
                <p id="high-priority-amount" class="text-4xl font-primary text-primary">{{ clientChats.urgente }}</p>
                <p class="text-red-600 text-[10px] font-bold uppercase tracking-tighter">Acción requerida</p>
            </section>
        </div>

        <!-- Today's Reserves -->
        <div id="today-reserves" class="glass-card p-6 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 text-secondary opacity-5 group-hover:opacity-10 transition-opacity">
                <FontAwesomeIcon :icon="faCalendarCheck" class="text-8xl rotate-12" />
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-secondary/20 flex items-center justify-center text-secondary">
                    <FontAwesomeIcon :icon="faCalendarCheck" />
                </div>
                <p class="text-xs font-bold uppercase tracking-wider text-primary opacity-60">Reservas Hoy</p>
            </div>
            <section>
                <p class="text-4xl font-primary text-primary">{{ clientChats.nuevo }}</p>
            </section>
        </div>

        <!-- Closed/Archive -->
        <div id="closed" class="glass-card p-6 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 text-primary opacity-5 group-hover:opacity-10 transition-opacity">
                <FontAwesomeIcon :icon="faBoxArchive" class="text-8xl rotate-12" />
            </div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                    <FontAwesomeIcon :icon="faBoxArchive" />
                </div>
                <p class="text-xs font-bold uppercase tracking-wider text-primary opacity-60">Histórico</p>
            </div>
            <section class="flex items-baseline gap-2">
                <p id="closed-amount" class="text-4xl font-primary text-primary">{{ clientChats.cerrado }}</p>
                <p class="text-primary/40 text-[10px] font-bold">Total cerrados</p>
            </section>
        </div>

    </div>
</template>
