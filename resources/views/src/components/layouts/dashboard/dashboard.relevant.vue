<script setup lang="ts">
    import { ref, onMounted, onBeforeUnmount } from 'vue';

    type Chat = 'activo' | 'urgente' | 'nuevo' | 'cerrado'

    const clientChats: Record<Chat, number> = {
        activo: 10,
        urgente: 2,
        nuevo: 3,
        cerrado: 4,
    }

    const chatIncrease = ref(0)

</script>

<template>
    <div id="resume-div" class="txt w-full flex flex-col lg:flex-row gap-1 lg:gap-4 p-2">
        <div id="active-chats-div" class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4">
            <p>Chats activos</p>
            <section class="flex flex-row">
                <p id="active-chats-amount" class="text-4xl">{{ clientChats.activo }}</p>
                <p id="active-chats-augment" class="ml-1 mt-4.5 text-green-700 text-xs">+{{ chatIncrease }}%</p>
            </section>
        </div>
        <div id="high-priority-div" class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4">
            <p>Alta prioridad</p>
            <section class="flex flex-row">
                <p id="high-priority-amount" class="text-4xl">{{ clientChats.urgente }}</p>
                <p class="ml-1 mt-4.5 text-red-700 text-xs">urgente</p>
            </section>
        </div>
        <div id="today-reserves" class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4">
            <p>Reservas Hoy</p>
            <section>
                <p class="text-4xl">{{ clientChats.nuevo }}</p>
            </section>
        </div>
        <div id="closed" class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4">
            <p>Chats cerrados</p>
            <section class="flex flex-row">
                <p id="closed-amount" class="text-4xl">{{ clientChats.cerrado }}</p>
                <p class="ml-1 mt-4.5 md:text-xs">Historico</p>
            </section>
        </div>
    </div>
</template>
