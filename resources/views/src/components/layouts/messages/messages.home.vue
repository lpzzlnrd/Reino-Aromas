<script setup lang="ts">
    import Header from '../header/header.vue'
    import Chats from './messages.chats.vue'

    const props = defineProps<{
        selectedChat: {
            id: number,
            username: string,
            lastMessage: string,
            messageTime: string,
            location: string,
        } | null
    }>()
</script>

<template>
    <div class="w-full font-secondary flex flex-col gap-2">
        <section class="p-2 ">
            <Header class="hidden md:flex "/>
        </section>
        <Chats :selected-chat="props.selectedChat" class="bg-background w-full h-full rounded-md"/>
    </div>
</template>
