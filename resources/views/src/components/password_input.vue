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
    <label for="password_input" class="block text-xs font-bold text-primary/60 uppercase tracking-widest mb-2 ml-1">Contraseña</label>
    <section class="input-group">
        <Lock class="text-primary opacity-40"/>
        <input id="password_input" :type="showPassword ? 'text' : 'password'" placeholder="••••••••" required>
        <button type="button" @click="togglePassword" class="text-primary opacity-40 hover:opacity-100 transition-opacity cursor-pointer px-1">
            <FontAwesomeIcon :icon="showPassword ? faEyeSlash : faEye" />
        </button>
    </section>
</template>
