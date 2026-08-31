<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuth } from '@/composables/useAuth'
import { useTheme } from '@/composables/useTheme'

const { fetchUser } = useAuth()

// Monta el tema guardado y deja escuchando el cambio del sistema. Va en la
// raiz para que aplique tambien en las vistas sin header (login, 404).
useTheme()

// Al montar la SPA, carga el usuario autenticado desde GET /api/user.
// Si la sesión expiró, el interceptor de axios redirige automáticamente al login.
onMounted(fetchUser)
</script>

<template>
    <RouterView />
</template>
