<script setup lang="ts">
    import Header from '../header/header.vue'
    import Chats from './messages.chats.vue'

    /**
     * Contenedor de la vista de mensajes.
     *
     * Ya no recibe ni pasa el chat seleccionado: el estado vive en useInbox(),
     * que es singleton. Antes el tipo Chat estaba declarado tres veces (acá, en
     * el layout y en chats.vue) y se propagaba por props.
     */
</script>

<template>
    <div class="w-full font-secondary flex flex-col gap-2">
        <section class="p-2 ">
            <Header class="hidden md:flex "/>
        </section>
        <Chats class="bg-background w-full h-full rounded-md"/>
    </div>
</template>
