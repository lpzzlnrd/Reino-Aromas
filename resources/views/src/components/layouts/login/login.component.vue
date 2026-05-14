<script setup lang="ts">
    import At from '../../icons/icon.at.vue'
    import password_input from '../../password_input.vue';
</script>

<template>
    <div id="login-div" class="txt
      bg-background border-2 border-primary px-6 py-12 mx-2
        sm:mx-28
        md:mx-50
        lg:mx-80
        xl:mx-110
        flex items-center justify-center rounded-3xl">
        <form id="login-form" class="w-full" action="submit">
            <label class="mt-4 text-secondary text-sm font-semibold tracking-wide" for="email-input">EMAIL</label>
            <section class="input-group mt-1 mb-4">
                <At class="text-primary opacity-60 transition-opacity" />
                <input id="email-input" type="email" placeholder="nombre@reinoaromas.com" required>
            </section>
            <password_input />
            <section class="mt-4 flex flex-row">
                <input class="appearance-none h-5 w-5
                    border-2 border-secondary rounded-md
                    hover:cursor-pointer hover:bg-accent-hover
                    checked:border-secondary checked:bg-accent
                    focus:outline-accent-hover"
                    type="checkbox" name="remember" id="remember">
                <span class="ml-1 text-secondary">Recordarme</span>
            </section>
            <hr class="my-4 text-secondary">
            <section class="flex items-center justify-center">
                <button id="login-btn" class="btn-primary w-50 justify-center">Iniciar Sesion</button>
            </section>
        </form>
    </div>
</template>
