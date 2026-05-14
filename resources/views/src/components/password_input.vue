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
    <label for="password-input" class="text-secondary text-sm font-semibold tracking-wide">CONTRASEÑA</label>
    <section class="input-group mt-1">
        <Lock class="text-primary opacity-60 transition-opacity"/>
        <input id="password_input" :type="showPassword ? 'text' : 'password'" placeholder="12345678" required>
        <button type="button" @click="togglePassword" class="text-primary opacity-60 hover:opacity-100 transition-opacity cursor-pointer px-1">
            <FontAwesomeIcon :icon="showPassword ? faEyeSlash : faEye" />
        </button>
    </section>
</template>
