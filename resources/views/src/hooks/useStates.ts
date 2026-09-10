import { ref } from 'vue'
import api from '@/lib/axios'

/**
 * Los estados de Venezuela, para los desplegables de ubicación.
 *
 * Sale de GET /api/states, que sirve App\Models\Contact::stateLabels(). NO se
 * escribe la lista aquí a propósito: los 24 slugs también los usa la validación
 * de los PATCH de contacto y de ticket, y una copia en TypeScript se
 * desincronizaría del backend en el primer cambio. Ya pasó con las etiquetas de
 * estado de ticket, que hay que traducir a mano entre el front y el back.
 *
 * Singleton cacheado igual que useAssignableUsers: la división
 * político-territorial de Venezuela no cambia durante una sesión, y el
 * desplegable aparece en tres sitios (panel del chat, ficha del cliente y
 * filtro del listado).
 */

export type StateOption = {
    /** Slug que se guarda en la BD (ej: 'distrito_capital'). */
    value: string
    /** Nombre con acentos para mostrar (ej: 'Distrito Capital'). */
    label: string
}

const estados = ref<StateOption[]>([])
const cargando = ref(false)
const error = ref<string | null>(null)

/** Para no repetir el GET en cada desplegable que se monta. */
let cargado = false

export function useStates() {
    const loadStates = async (forzar = false): Promise<void> => {
        if (cargado && !forzar) return
        if (cargando.value) return

        cargando.value = true
        error.value = null

        try {
            const { data } = await api.get<StateOption[]>('/states')

            estados.value = data ?? []
            cargado = true
        } catch (e: any) {
            estados.value = []

            // Se guarda el error en vez de tragarlo: un desplegable vacío sin
            // explicación parece que el CRM no tiene estados configurados.
            error.value = e?.response?.data?.message
                ?? 'No se pudieron cargar los estados.'
        } finally {
            cargando.value = false
        }
    }

    /**
     * Nombre legible de un slug.
     *
     * Un slug que no está en el catálogo se devuelve tal cual y no como
     * "Sin estado": si la BD trae un valor raro, esconderlo hace que el dato
     * parezca vacío cuando en realidad está mal.
     */
    const labelFor = (slug: string | null | undefined): string => {
        if (!slug) return 'Sin estado'

        return estados.value.find((e) => e.value === slug)?.label ?? slug
    }

    return {
        estados,
        cargando,
        error,
        loadStates,
        labelFor,
    }
}
