import { computed, ref } from 'vue'
import api from '@/lib/axios'

/**
 * Los agentes a los que se le puede asignar un caso.
 *
 * Sale de GET /api/users, el mismo endpoint que la vista de Usuarios. Se
 * cachea en un singleton porque la plantilla de agentes casi nunca cambia
 * durante una sesión y el selector aparece en dos sitios (el panel del chat y
 * la tarjeta del tablero): pedirlo cada vez que se abre un desplegable sería
 * un request por clic.
 *
 * Solo lista los activos: asignarle un caso a alguien desactivado lo deja sin
 * dueño real y el filtro "Asignados a mí" nunca se lo mostraría a nadie.
 */

export type AssignableUser = {
    id: number
    name: string
    email: string
    role: string
    is_active: boolean
    avatar_url: string | null
}

const usuarios = ref<AssignableUser[]>([])
const cargando = ref(false)
const error = ref<string | null>(null)

/** Para no repetir el GET en cada desplegable que se abre. */
let cargado = false

export function useAssignableUsers() {
    const activos = computed(() => usuarios.value.filter((u) => u.is_active))

    /**
     * Carga la lista una sola vez.
     *
     * @param forzar true para volver a pedirla (tras crear un usuario nuevo).
     */
    const loadUsers = async (forzar = false): Promise<void> => {
        if (cargado && !forzar) return
        if (cargando.value) return

        cargando.value = true
        error.value = null

        try {
            const { data } = await api.get<AssignableUser[]>('/users')

            usuarios.value = data ?? []
            cargado = true
        } catch (e: any) {
            usuarios.value = []

            // Se guarda el error en vez de tragarlo: sin la lista el selector
            // queda vacío, y "no hay agentes" es un mensaje falso cuando lo que
            // pasó fue que falló la red.
            error.value = e?.response?.data?.message
                ?? 'No se pudieron cargar los agentes.'
        } finally {
            cargando.value = false
        }
    }

    return {
        usuarios,
        activos,
        cargando,
        error,
        loadUsers,
    }
}
