<script setup lang="ts">
    import { ref } from 'vue';

    import Search from '../../icons/icon.search.vue'
    import Bell from '../../icons/icon.bell.vue'
    import Info from '../../icons/icon.info.vue'

    // const pageName = ref()
</script>

<template>
    <header class="subtitle py-4 flex flex-row justify-between items-center">
        <p class="text-lg font-medium">Panel de control</p>
        <div class="flex flex-row gap-3 lg:gap-5 items-center">
            <section id="search-section" class="input-group w-48 lg:w-64">
                <Search class="text-primary opacity-60 group-focus-within:opacity-100 transition-opacity"/>
                <input id="search-bar" type="text" placeholder="Buscar...">
            </section>
            <button id="notifications-btn" class="text-primary opacity-70 hover:opacity-100 hover:scale-110 transition-all cursor-pointer" type="button">
                <Bell />
            </button>
            <button id="info-btn" class="text-primary opacity-70 hover:opacity-100 hover:scale-110 transition-all cursor-pointer" type="button">
                <Info />
            </button>
        </div>
    </header>
</template>
