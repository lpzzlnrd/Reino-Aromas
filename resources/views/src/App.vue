<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuth } from '@/composables/useAuth'

const { fetchUser } = useAuth()

// Al montar la SPA, carga el usuario autenticado desde GET /api/user.
// Si la sesión expiró, el interceptor de axios redirige automáticamente al login.
onMounted(fetchUser)
</script>

<template>
    <RouterView />
</template>
