<script setup lang="ts">
    import password_input from '../../password_input.vue';
</script>

<template>
    <div class="login txt bg-contrast border-2 border-title px-6 py-12 s:mx-50 s:mt-10 md:mx-130 md:my-12 flex items-center justify-center rounded-3xl">
        <form class="w-full" action="submit">
            <label class="mt-4" for="login-email">EMAIL</label>
            <section class="w-full mt-1 mb-3 p-1 border-2 border-title focus-within:border-accent hover:border-accent-hover flex flex-row rounded-xl">
                <span>@</span>
                <input class='focus:outline-none w-full' id="login-email" type="email" placeholder="nombre@reinoaromas.com" required>
            </section>
            <password_input />
            <section class="mt-4 flex flex-row">
                <input class="appearance-none h-5 w-5
                    border-2 border-title rounded-md
                    hover:cursor-pointer hover:bg-accent-hover
                    checked:border-title checked:bg-accent
                    focus:outline-accent-hover"
                    type="checkbox" name="remember" id="remember">
                <span class="ml-1">Recordarme</span>
            </section>
            <hr class="my-4 text-title">
            <section class="flex items-center justify-center">
                <button class="login-btn border-2 border-title bg-accent hover:bg-accent-hover w-50 p-2 rounded-2xl">Iniciar Sesion</button>
            </section>
        </form>
    </div>
</template>
