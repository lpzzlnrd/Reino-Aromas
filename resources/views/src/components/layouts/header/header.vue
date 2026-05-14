<script setup lang="ts">
    import { ref } from 'vue';

    import Search from '../../icons/icon.search.vue'
    import Bell from '../../icons/icon.bell.vue'
    import Info from '../../icons/icon.info.vue'

    // const pageName = ref()
</script>

<template>
    <header class="subtitle h-7 flex flex-row justify-between">
        <p class="p-1">Panel de control</p>
        <div class="flex flex-row gap-2 lg:gap-3.5">
            <section id="search-section" class="group border-2 p-1 border-title focus-within:border-secondary focus-within:bg-contrast hover:border-accent-hover flex flex-row rounded-xl">
                <Search class="group-hover:text-accent-hover"/>
                <input id="search-bar" class="focus:outline-none" type="text">
            </section>
            <button id="notifications-btn" class="hover:cursor-pointer hover:text-accent-hover" type="button">
                <Bell />
            </button>
            <button id="info-btn" class="hover:cursor-pointer hover:text-accent-hover" type="button">
                <Info />
            </button>
        </div>
    </header>
</template>
