<script setup lang="ts">
    import Header from '../header/header.vue'
    import Relevant_Data from './dashboard.relevant.vue'
    import Recents from './dashboard.recents.vue'
    import Cities from './dashboard.cities.vue'
    import Urgent from './dashboard.urgent.vue'
</script>

<template>
    <div class="lg:mx-3.5 mt-2.5 w-full">
        <div class="p-2 w-full">
            <Header />
        </div>
        <div id="dashboard-div" class="my-1 lg:my-5 flex flex-col">
            <Relevant_Data />
            <div class="w-full lg:my-5 gap-1 lg:gap-4 flex flex-col lg:flex-row">
                <Recents />
                <Cities />
            </div>
            <Urgent />
        </div>
    </div>
</template>
