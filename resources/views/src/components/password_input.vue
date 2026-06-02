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
    <label for="password-input" class="text-secondary">CONTRASEÑA</label>
    <section class="group w-full mt-1 p-1 gap-1 border-2 border-secondary focus-within:border-accent hover:border-accent-hover flex flex-row rounded-xl">
        <Lock class="group-hover:text-accent-hover"/>
        <input id="password_input" class='focus:outline-none w-full' :type="showPassword ? 'text' : 'password'" placeholder="12345678" required>
        <button type="button" @click="togglePassword" class="text-txt group">
            <FontAwesomeIcon class="mt-1 hover:cursor-pointer group-hover:text-accent-hover" :icon="showPassword ? faEyeSlash : faEye" />
        </button>
    </section>
</template>
