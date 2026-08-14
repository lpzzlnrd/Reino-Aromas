import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import { disconnectEcho } from '@/lib/echo'

// Tipo del usuario autenticado — espeja los campos seguros del modelo User de Laravel
export type AuthUser = {
    id: number
    name: string
    email: string
    role: 'superadmin' | 'administrador'
    avatar_url: string | null
    is_active: boolean
    last_login_at: string | null
}

const user = ref<AuthUser | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

export function useAuth() {
    const router = useRouter()

    // Carga el usuario autenticado desde GET /api/user.
    // El Vue lo llama al montar App.vue — si falla (401) el usuario no tiene sesión.
    const fetchUser = async (): Promise<void> => {
        loading.value = true
        error.value = null
        try {
            const response = await api.get<AuthUser>('/user')
            user.value = response.data
        } catch {
            user.value = null
        } finally {
            loading.value = false
        }
    }

    // Cierra la sesión contra POST /logout (ruta web de Laravel).
    // Laravel destruye la sesión y redirige al login de Blade.
    const logout = async (): Promise<void> => {
        try {
            // El form de logout de Laravel espera el método POST en la ruta web,
            // no en /api — por eso usamos fetch directo con el token CSRF del meta tag.
            const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
            await fetch('/logout', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token },
            })
        } finally {
            user.value = null
            // El WebSocket quedaría abierto y autenticado como el usuario que
            // acaba de salir. La recarga de abajo también lo cerraría, pero
            // hacerlo explícito evita que quede vivo si algún día el logout
            // deja de recargar la página.
            disconnectEcho()
            // Laravel ya redirige al login via RedirectResponse — recargamos para
            // que el browser siga esa redirección.
            window.location.href = '/login'
        }
    }

    const isSuperadmin = (): boolean => user.value?.role === 'superadmin'

    return { user, loading, error, fetchUser, logout, isSuperadmin }
}
