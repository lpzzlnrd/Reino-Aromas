<script setup lang="ts">
    import Logo from '../../logo.vue'
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-6 px-6 py-12 text-center">
        <div class="relative">
            <!-- Halo decorativo -->
            <div class="absolute inset-0 rounded-full bg-gradient-to-br from-secondary/30 to-accent/40 blur-2xl scale-150"></div>
            <Logo class="relative w-36 drop-shadow-lg" />
        </div>

        <div>
            <h1 class="font-primary text-5xl text-primary tracking-tight leading-tight">Reino Aromas</h1>
            <p class="text-sm text-secondary font-medium uppercase tracking-[0.25em] mt-2 opacity-80">Gourmet & Artesanal</p>
        </div>

        <p class="text-sm text-primary/50 max-w-xs leading-relaxed">
            Plataforma de gestión de clientes y comunicaciones para el equipo de Reino Aromas.
        </p>
    </div>
</template>
