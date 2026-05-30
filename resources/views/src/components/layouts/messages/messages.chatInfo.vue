<script setup lang="ts">
    import Mail from '../../icons/icon.mail.vue'
    import Calendar from '../../icons/icon.calendar.vue'
    import Phone from '../../icons/icon.phone.vue'
</script>

<template>
    <!-- Informacion del chat -->
    <div class="p-4 border-l-4 border-primary rounded-lg">
        <p class="title">Informacion del contacto</p>
        <div class="my-6">
            <p class="flex flex-row gap-1">
                <Mail />
                reinoaromas@gmail.com
            </p>
            <p class="flex flex-row gap-1">
                <Phone />
                0424-4445210
            </p>
            <p class="flex flex-row gap-1">
                <Calendar />
                Cliente desde 2024
            </p>
        </div>
        <div class="my-6">
            <p class="title">Ticket #RE-8921</p>
            <section class="flex flex-row justify-between">
                <p class="title">Canal</p>
                <p>Whatsapp</p>
            </section>
            <section class="flex flex-row justify-between">
                <p class="title">Ultima act.</p>
                <p>Hace 2 min</p>
            </section>
        </div>
        <div class="my-6">
            <section class="flex flex-row justify-between">
                <p class="title">Notas internas</p>
                <button type="button" class="cursor-pointer text-xs hover:text-primary">Añadir</button>
            </section>
            <textarea class="h-24 w-full align-text-top text-xs p-1 border-2 rounded-md border-secondary resize-none overflow-y-auto focus:outline-none" />
        </div>
        <div class="my-6">
            <section class="flex flex-row justify-between">
                <p class="title">Etiquetas</p>
                <button type="button" class="cursor-pointer text-xs hover:text-primary">Añadir</button>
            </section>
            <!-- Se cargaran las diferentes etiquetas que se le hayan agregado al usuario -->
            <section></section>
        </div>
    </div>
</template>
