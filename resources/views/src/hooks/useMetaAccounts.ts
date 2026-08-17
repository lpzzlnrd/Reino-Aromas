import { computed, ref } from 'vue'
import api from '@/lib/axios'
import { launchEmbeddedSignup, loadMetaSdk } from '@/lib/metaSdk'

/**
 * Cuentas de Meta vinculadas: estado, vinculación por popup y verificación.
 *
 * El estado vive DENTRO de la función (no es singleton como useInbox): esta
 * vista es la única que lo usa y no hay nada que compartir entre componentes.
 */

export type MetaChannel = 'whatsapp' | 'instagram' | 'facebook'

export type MetaAccountStatus = 'connected' | 'disconnected' | 'error'

/** Forma de MetaAccountStatusService::paraCanal(). */
export type MetaAccountState = {
    channel: MetaChannel
    label: string
    status: MetaAccountStatus
    connected: boolean
    display_name: string | null
    external_id: string | null
    expires_soon: boolean
    token_expires_at: string | null
    error_message: string | null
    verified_at: string | null
    connected_by: string | null
    /** 'crm' si se vinculó desde la UI, 'env' si viene del .env del servidor. */
    source: 'crm' | 'env' | null
    /** Variables del .env que faltan para este canal. */
    missing_env: string[]
    /** false si falta META_APP_ID: sin eso no hay popup posible. */
    can_connect: boolean
}

type MetaConfig = {
    app_id: string | null
    graph_version: string
    configurations: Record<MetaChannel, string | null>
}

export function useMetaAccounts() {
    const accounts = ref<MetaAccountState[]>([])
    const config = ref<MetaConfig | null>(null)

    const loading = ref(false)
    const error = ref<string | null>(null)

    /** Canal cuyo popup está abierto o cuyo request está en vuelo. */
    const working = ref<MetaChannel | null>(null)

    /** Mensaje de éxito, por canal. Se limpia al empezar otra acción. */
    const notice = ref<string | null>(null)

    const conectadas = computed(() => accounts.value.filter((a) => a.connected).length)

    /**
     * ¿El servidor puede lanzar el flujo de vinculación?
     *
     * Sin app_id el SDK de Meta no arranca, así que la UI deshabilita los
     * botones y explica qué falta en vez de abrir un popup roto.
     */
    const signupDisponible = computed(() => config.value?.app_id != null)

    const load = async (): Promise<void> => {
        loading.value = true
        error.value = null

        try {
            const { data } = await api.get('/meta/accounts')
            accounts.value = data.accounts ?? []
            config.value = data.config ?? null
        } catch {
            accounts.value = []
            error.value = 'No se pudo cargar el estado de las cuentas'
        } finally {
            loading.value = false
        }
    }

    /** Reemplaza una cuenta en la lista sin recargar todo. */
    const reemplazar = (actualizada: MetaAccountState): void => {
        const i = accounts.value.findIndex((a) => a.channel === actualizada.channel)
        if (i !== -1) accounts.value[i] = actualizada
    }

    /**
     * Abre el popup de Meta y guarda la cuenta.
     *
     * El `code` que devuelve el popup vive 30 segundos, así que se manda al
     * backend inmediatamente y sin pasos intermedios.
     */
    const connect = async (channel: MetaChannel): Promise<boolean> => {
        const configId = config.value?.configurations?.[channel]

        if (!config.value?.app_id) {
            error.value = 'Falta configurar META_APP_ID en el servidor.'
            return false
        }

        if (!configId) {
            error.value = `Falta el config_id de ${channel} en el servidor `
                + '(META_SIGNUP_' + channel.toUpperCase() + '_CONFIG_ID).'
            return false
        }

        working.value = channel
        error.value = null
        notice.value = null

        try {
            await loadMetaSdk(config.value.app_id, config.value.graph_version)

            const { code, data } = await launchEmbeddedSignup(configId)

            const { data: respuesta } = await api.post(`/meta/accounts/${channel}/exchange`, {
                code,
                waba_id: data.waba_id,
                phone_number_id: data.phone_number_id,
                external_id: data.business_id,
            })

            if (respuesta.account) reemplazar(respuesta.account)

            notice.value = 'Cuenta vinculada correctamente.'

            return true
        } catch (e: any) {
            // Se distingue el error del backend (que explica qué falta) del
            // error del popup (cancelación del usuario, SDK bloqueado).
            error.value = e?.response?.data?.message ?? e?.message ?? 'No se pudo vincular la cuenta'

            return false
        } finally {
            working.value = null
        }
    }

    /**
     * Comprueba contra la Graph API que el token sirve.
     *
     * "Configurado" no es "funciona": un token revocado o caducado pasa
     * cualquier chequeo estático y falla al enviar el primer mensaje.
     */
    const verify = async (channel: MetaChannel): Promise<boolean> => {
        working.value = channel
        error.value = null
        notice.value = null

        try {
            const { data } = await api.post(`/meta/accounts/${channel}/verify`)

            if (data.account) reemplazar(data.account)

            notice.value = data.meta?.name
                ? `Conexión verificada: ${data.meta.name}`
                : 'Conexión verificada.'

            return true
        } catch (e: any) {
            error.value = e?.response?.data?.message ?? 'No se pudo verificar la conexión'

            // El backend ya persistió el estado 'error'; se recarga para
            // reflejarlo en la tarjeta.
            void load()

            return false
        } finally {
            working.value = null
        }
    }

    const disconnect = async (channel: MetaChannel): Promise<boolean> => {
        working.value = channel
        error.value = null
        notice.value = null

        try {
            const { data } = await api.delete(`/meta/accounts/${channel}`)

            if (data.account) reemplazar(data.account)

            notice.value = 'Cuenta desvinculada.'

            return true
        } catch (e: any) {
            error.value = e?.response?.data?.message ?? 'No se pudo desvincular la cuenta'
            return false
        } finally {
            working.value = null
        }
    }

    return {
        accounts,
        config,
        loading,
        error,
        notice,
        working,
        conectadas,
        signupDisponible,
        load,
        connect,
        verify,
        disconnect,
    }
}
