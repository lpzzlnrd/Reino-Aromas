<script setup lang="ts">
    import { ref } from 'vue'
    import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
    import { faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons'
    import Lock from './icons/icon.lock.vue'

    const showPassword = ref(false)
    const togglePassword = () => {
        showPassword.value = !showPassword.value
    }
</script>

<template>
    <label for="login-password">CONTRASEÑA</label>
    <section class="w-full mt-1 p-1 border-2 border-title focus-within:border-accent hover:border-accent-hover flex flex-row rounded-xl">
        <Lock />
        <input class='focus:outline-none w-full' id="login-password" :type="showPassword ? 'text' : 'password'" placeholder="12345678" required>
        <button type="button" @click="togglePassword" class="text-text">
            <FontAwesomeIcon class="mt-1 hover:cursor-pointer" :icon="showPassword ? faEyeSlash : faEye" />
        </button>
    </section>
</template>
