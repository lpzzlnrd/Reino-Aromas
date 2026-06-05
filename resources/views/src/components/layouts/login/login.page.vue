<script setup lang="ts">
    import Login from './login.component.vue'
    import About from './login.about.vue';
</script>

<template>
    <div class="min-h-screen mesh-bg flex flex-col items-center justify-center p-6 lg:p-12 font-secondary">
        <div class="w-full max-w-4xl flex flex-col lg:flex-row items-center gap-12 lg:gap-8 animate-fade-in">
            <div class="w-full lg:w-1/2">
                <About />
            </div>
            <div class="w-full lg:w-1/2">
                <Login />
            </div>
        </div>
    </div>
</template>
