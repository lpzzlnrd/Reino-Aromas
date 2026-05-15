<script setup lang="ts">
    import At from '../../icons/icon.at.vue'
    import password_input from '../../password_input.vue';
</script>

<template>
    <div id="login-div" class="glass-card w-full p-8 lg:p-12 shadow-2xl">
        <div class="mb-10">
            <h2 class="text-2xl font-primary text-primary mb-2">Bienvenido</h2>
            <p class="text-xs font-bold text-secondary uppercase tracking-widest opacity-60">Ingresa tus credenciales</p>
        </div>

        <form id="login-form" class="space-y-6" @submit.prevent>
            <div>
                <label class="block text-xs font-bold text-primary/60 uppercase tracking-widest mb-2 ml-1" for="email-input">Correo Electrónico</label>
                <div class="input-group">
                    <At class="text-primary opacity-40" />
                    <input id="email-input" type="email" placeholder="admin@reinoaromas.com" required>
                </div>
            </div>

            <div>
                <password_input />
            </div>

            <div class="flex items-center justify-between px-1">
                <label class="flex items-center gap-2 cursor-pointer group">
                    <input class="appearance-none h-4 w-4 border-2 border-primary/20 rounded bg-white/50 checked:bg-primary checked:border-primary transition-all cursor-pointer" type="checkbox" name="remember" id="remember">
                    <span class="text-xs font-medium text-primary/60 group-hover:text-primary transition-colors">Recordarme</span>
                </label>
                <a href="#" class="text-xs font-bold text-secondary uppercase tracking-widest hover:text-accent-hover transition-colors">¿Olvidaste tu clave?</a>
            </div>

            <div class="pt-4">
                <button id="login-btn" class="btn-primary w-full py-4 text-sm tracking-widest uppercase font-bold shadow-xl shadow-secondary/20">
                    Iniciar Sesión
                </button>
            </div>
        </form>

        <div class="mt-10 text-center">
            <p class="text-[10px] text-primary/40 font-bold uppercase tracking-[0.2em]">Reino Aromas v2.0</p>
        </div>
    </div>
</template>
