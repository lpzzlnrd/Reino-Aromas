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
    <!-- `h-full min-h-0`: el layout padre ya fija la altura de la bandeja, y sin
         propagarla aqui el `h-full` de <Chats> resolvia a `auto` y la lista de
         mensajes crecia sin tope en vez de scrollear por dentro.
         El `min-h-0` es el que permite que el hijo flexible se ENCOJA: por
         defecto un item de flex no baja de su contenido, asi que sin el la
         columna volvia a desbordar hacia abajo. -->
    <div class="w-full h-full min-h-0 font-secondary flex flex-col gap-2">
        <section class="p-2 shrink-0">
            <Header class="hidden md:flex "/>
        </section>
        <!-- `flex-1 min-h-0` en vez de `h-full`: `h-full` aqui seria el 100% de
             la columna COMPLETA e ignoraria lo que ya ocupa el Header, que es
             justo lo que empujaba la barra de escritura fuera de la pantalla. -->
        <Chats class="bg-background w-full flex-1 min-h-0 rounded-md"/>
    </div>
</template>
