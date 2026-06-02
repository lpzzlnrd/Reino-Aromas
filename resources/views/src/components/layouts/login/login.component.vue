<script setup lang="ts">
    import { ref } from 'vue'
    import At from '../../icons/icon.at.vue'
    import password_input from '../../password_input.vue'

    const email    = ref('')
    const password = ref('')
    const remember = ref(false)
    const loading  = ref(false)
    const errorMsg = ref<string | null>(null)

    /**
     * Submit contra POST /login (ruta web de Laravel — Blade).
     *
     * El login NO va por /api — usa la sesión web clásica de Laravel.
     * Al autenticar, Laravel redirige a /app donde Vue toma el control.
     *
     * Necesita el token CSRF del meta tag que app.blade.php inyecta.
     * fetch con credentials:'include' envía las cookies para que Laravel
     * pueda crear la sesión correctamente.
     */
    const submit = async (): Promise<void> => {
        loading.value  = true
        errorMsg.value = null

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

        try {
            const response = await fetch('/login', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    email: email.value,
                    password: password.value,
                    remember: remember.value,
                }),
            })

            if (response.ok || response.redirected) {
                // Laravel respondió con redirect a /app — seguimos la redirección
                window.location.href = response.url || '/app'
                return
            }

            // Laravel devuelve 422 con errores de validación en JSON
            if (response.status === 422) {
                const data = await response.json()
                const firstError = Object.values(data.errors ?? {})[0]
                errorMsg.value = Array.isArray(firstError) ? firstError[0] : 'Credenciales inválidas.'
                return
            }

            // Rate limit
            if (response.status === 429) {
                errorMsg.value = 'Demasiados intentos. Espera un momento.'
                return
            }

            errorMsg.value = 'Ocurrió un error. Intenta de nuevo.'
        } catch {
            errorMsg.value = 'No se pudo conectar con el servidor.'
        } finally {
            loading.value = false
        }
    }
</script>

<template>
    <div id="login-div" class="txt bg-background border-2 border-primary px-6 py-12 mx-2
        sm:mx-28 md:mx-50 lg:mx-80 xl:mx-110
        flex items-center justify-center rounded-3xl">

        <form id="login-form" class="txt w-full" @submit.prevent="submit">

            <!-- Error de credenciales -->
            <div v-if="errorMsg" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-600">
                {{ errorMsg }}
            </div>

            <label class="mt-4 text-secondary" for="email-input">EMAIL</label>
            <section class="group w-full mt-1 mb-3 p-1 gap-1 border-2 border-secondary focus-within:border-accent hover:border-accent-hover flex flex-row rounded-xl">
                <At class="group-hover:text-accent-hover" />
                <input
                    id="email-input"
                    v-model="email"
                    class="focus:outline-none w-full"
                    type="email"
                    placeholder="nombre@reinoaromas.com"
                    required
                    autocomplete="email"
                >
            </section>

            <password_input v-model="password" />

            <section class="mt-4 flex flex-row items-center gap-1">
                <input
                    v-model="remember"
                    class="appearance-none h-5 w-5 border-2 border-secondary rounded-md
                           hover:cursor-pointer hover:bg-accent-hover
                           checked:border-secondary checked:bg-accent
                           focus:outline-accent-hover"
                    type="checkbox"
                    id="remember"
                >
                <span class="text-secondary">Recordarme</span>
            </section>

            <hr class="my-4 text-secondary">

            <section class="flex items-center justify-center">
                <button
                    type="submit"
                    :disabled="loading"
                    id="login-btn"
                    class="border-2 border-primary bg-accent hover:bg-primary hover:text-secondary
                           w-50 p-2 rounded-2xl transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ loading ? 'Ingresando...' : 'Iniciar Sesión' }}
                </button>
            </section>

        </form>
    </div>
</template>
