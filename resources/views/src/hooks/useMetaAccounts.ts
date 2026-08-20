import { ref } from 'vue'
import api from '@/lib/axios'
import { abrirPopupSignup, escucharSignupIds } from '@/lib/metaSdk'

export type MetaChannel = 'facebook' | 'instagram' | 'whatsapp'

/** Forma de MetaAccountStatusService::estadoDe() en el backend. */
export type MetaAccountStatus = {
    channel: MetaChannel
    connected: boolean
    source: 'crm' | 'env' | null
    name: string | null
    can_disconnect: boolean
    signup_config_id: string | null
}

const accounts = ref<MetaAccountStatus[]>([])
const appId = ref<string>('')
const loading = ref(false)
/** Canal cuyo popup está abierto ahora mismo, para deshabilitar su botón. */
const linkingChannel = ref<MetaChannel | null>(null)
const error = ref<string | null>(null)

export function useMetaAccounts() {
    const estadoDe = (channel: MetaChannel): MetaAccountStatus | undefined =>
        accounts.value.find((a) => a.channel === channel)

    const cargar = async () => {
        loading.value = true
        error.value = null
        try {
            const { data } = await api.get('/meta/accounts')
            appId.value = data.app_id
            accounts.value = data.accounts
        } catch {
            error.value = 'No se pudo cargar el estado de las cuentas.'
        } finally {
            loading.value = false
        }
    }

    /**
     * Flujo completo del botón "Vincular cuenta": abre el popup, canjea el
     * code de inmediato (vive 30s) y junta los ids que llegan por
     * postMessage. Cada paso puede fallar independientemente sin dejar el
     * botón colgado.
     */
    const vincular = async (channel: MetaChannel) => {
        const estado = estadoDe(channel)
        if (!estado?.signup_config_id || !appId.value) {
            error.value = 'Falta configurar este canal en el .env (ver META_SIGNUP_*_CONFIG_ID).'
            return
        }

        linkingChannel.value = channel
        error.value = null

        try {
            // Se dispara antes que el popup: el listener debe estar activo
            // cuando Meta manda el postMessage, que puede llegar antes de
            // que FB.login() resuelva.
            const idsPromise = escucharSignupIds()

            const code = await abrirPopupSignup(appId.value, estado.signup_config_id)
            await api.post(`/meta/accounts/${channel}/exchange`, { code })

            const ids = await idsPromise
            if (ids) {
                await api.post(`/meta/accounts/${channel}/verify`, {
                    external_id: ids.business_id,
                    waba_id: ids.waba_id ?? ids.phone_number_id,
                    meta_payload: ids,
                })
            }

            await cargar()
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'No se pudo completar la vinculación.'
        } finally {
            linkingChannel.value = null
        }
    }

    const desvincular = async (channel: MetaChannel) => {
        error.value = null
        try {
            await api.delete(`/meta/accounts/${channel}`)
            await cargar()
        } catch {
            error.value = 'No se pudo desvincular la cuenta.'
        }
    }

    return {
        accounts,
        loading,
        linkingChannel,
        error,
        estadoDe,
        cargar,
        vincular,
        desvincular,
    }
}
