<script setup lang="ts">
    import Login from './login.component.vue'
    import About from './login.about.vue';
</script>

<template>
    <div class="min-h-screen mesh-bg flex items-center justify-center p-6 font-secondary">
        <div class="w-full max-w-4xl flex flex-col lg:flex-row items-center gap-10 lg:gap-16 animate-fade-in">

            <!-- Panel izquierdo — branding -->
            <div class="w-full lg:w-1/2">
                <About />
            </div>

            <!-- Panel derecho — formulario -->
            <div class="w-full lg:w-1/2">
                <Login />
            </div>
        </div>
    </div>
</template>
