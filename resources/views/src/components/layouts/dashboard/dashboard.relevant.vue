<script setup lang="ts">
    import { ref, onMounted, onBeforeUnmount } from 'vue';
    import { useCaseStatus, CaseStatus } from '@/hooks/caseStatus';

    const { casesByStatus } = useCaseStatus()
    const chatIncrease = ref(0)

</script>

<template>
    <div id="resume-div" class="txt w-full flex flex-col lg:flex-row gap-1 lg:gap-4 p-2">
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Nuevos</p>
            <section class="flex flex-row">
                <p id="active-chats-amount" class="text-4xl">{{ casesByStatus[CaseStatus.New] }}</p>
                <p id="active-chats-augment" class="ml-1 mt-4.5 text-green-700 text-xs">+{{ chatIncrease }}%</p>
            </section>
        </div>
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Interesados</p>
            <section>
                <p class="text-4xl">{{ casesByStatus[CaseStatus.Interested] }}</p>
            </section>
        </div>
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Seguimiento</p>
            <section class="flex flex-row">
                <p id="closed-amount" class="text-4xl">{{ casesByStatus[CaseStatus.Following] }}</p>
            </section>
        </div>
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Urgentes</p>
            <section class="flex flex-row">
                <p id="high-priority-amount" class="text-4xl">{{ casesByStatus[CaseStatus.HighPriority] }}</p>
                <p class="ml-1 mt-4.5 text-red-700 text-xs">urgente</p>
            </section>
        </div>
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Reservas</p>
            <section class="flex flex-row">
                <p id="closed-amount" class="text-4xl">{{ casesByStatus[CaseStatus.Reserved] }}</p>
                <p class="ml-1 mt-4.5 text-green-700 md:text-xs">Totales</p>
            </section>
        </div>
        <div class="bg-background border-2 border-primary rounded-md w-full lg:w-1/4 p-4 shadow-lg">
            <p>Cerrados</p>
            <section class="flex flex-row">
                <p id="closed-amount" class="text-4xl">{{ casesByStatus[CaseStatus.Closed] }}</p>
                <p class="ml-1 mt-4.5 md:text-xs">Historico</p>
            </section>
        </div>
    </div>
</template>
