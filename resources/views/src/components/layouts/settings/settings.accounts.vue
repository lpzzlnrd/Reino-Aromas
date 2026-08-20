<script setup lang="ts">
    import { onMounted, computed } from 'vue'
    import Header from '../header/header.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Check from '../../icons/icon.check.vue'
    import Alert from '../../icons/icon.alert.vue'
    import Info from '../../icons/icon.info.vue'
    import { useMetaAccounts, type MetaAccountState, type MetaChannel } from '@/hooks/useMetaAccounts'

    /**
     * Cuentas vinculadas de Meta.
     *
     * Antes eran tres tarjetas maquetadas con "No vinculado" escrito a mano y
     * botones sin @click. Ahora el estado sale de GET /api/meta/accounts (que
     * une la tabla meta_accounts con el .env) y el botón abre el popup de
     * Embedded Signup de Meta.
     *
     * Degrada a propósito: sin META_APP_ID el botón queda deshabilitado y la
     * tarjeta explica qué variable falta, en vez de abrir un popup roto.
     */

    const {
        accounts,
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
    } = useMetaAccounts()

    const ICONOS: Record<MetaChannel, unknown> = {
        whatsapp: Whatsapp,
        instagram: Instagram,
        facebook: Facebook,
    }

    /** Degradado de marca de cada canal, para el cuadro del icono. */
    const GRADIENTES: Record<MetaChannel, string> = {
        whatsapp: 'bg-gradient-to-br from-green-400 to-green-600',
        instagram: 'bg-gradient-to-bl from-purple-500 via-pink-600 to-yellow-300',
        facebook: 'bg-blue-600',
    }

    const DESCRIPCIONES: Record<MetaChannel, string> = {
        whatsapp: 'Recibe y responde mensajes desde tu número de WhatsApp Business.',
        instagram: 'Atiende los mensajes directos de tu cuenta de Instagram.',
        facebook: 'Conecta el Messenger de tu página de Facebook.',
    }

    const total = computed(() => accounts.value.length)

    /** Texto de estado bajo el nombre del canal. */
    const estadoTexto = (cuenta: MetaAccountState): string => {
        if (cuenta.status === 'error') return 'Error de conexión'
        if (!cuenta.connected) return 'No vinculado'
        if (cuenta.expires_soon) return 'Vinculado · el token caduca pronto'

        return cuenta.display_name ?? 'Vinculado'
    }

    const estadoClase = (cuenta: MetaAccountState): string => {
        if (cuenta.status === 'error') return 'text-red-600'
        if (cuenta.expires_soon) return 'text-amber-600'
        if (cuenta.connected) return 'text-green-600'

        return 'text-primary/40'
    }

    const fecha = (iso: string | null): string => {
        if (!iso) return ''

        return new Date(iso).toLocaleDateString('es-VE', {
            day: '2-digit', month: 'short', year: 'numeric',
        })
    }

    onMounted(load)
</script>

<template>
    <div class="w-full font-secondary flex flex-col gap-1">
        <section class="p-2">
            <Header class="hidden md:flex w-full flex-col" />
        </section>

        <div class="flex-1 px-6 lg:px-12 py-10 flex flex-col items-center gap-8">

            <!-- Intro -->
            <section class="text-center flex flex-col gap-3 max-w-lg">
                <h1 class="font-primary text-3xl text-primary">Cuentas vinculadas</h1>
                <p class="text-sm text-primary/55 leading-relaxed">
                    Vincula tus cuentas para obtener un control más claro de tus mensajes
                    y clientela a través de tus plataformas.
                </p>
                <p v-if="!loading && total > 0" class="text-[11px] font-bold uppercase tracking-widest text-primary/40">
                    {{ conectadas }} de {{ total }} conectadas
                </p>
            </section>

            <!-- Aviso: el servidor no puede lanzar el flujo -->
            <div
                v-if="!loading && !signupDisponible"
                class="w-full max-w-3xl px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2.5"
            >
                <Info class="text-amber-600 shrink-0 mt-0.5" />
                <div class="text-xs text-amber-900 leading-relaxed">
                    <p class="font-semibold">La vinculación automática no está disponible</p>
                    <p class="mt-0.5">
                        Falta configurar <code class="font-mono bg-amber-100 px-1 rounded">META_APP_ID</code>
                        en el servidor. Mientras tanto, los canales se pueden configurar a mano
                        en el <code class="font-mono bg-amber-100 px-1 rounded">.env</code>.
                    </p>
                </div>
            </div>

            <!-- Error / éxito -->
            <div
                v-if="error"
                class="w-full max-w-3xl px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700 flex items-start gap-2.5"
            >
                <Alert class="shrink-0 mt-0.5" />
                <span>{{ error }}</span>
            </div>

            <div
                v-if="notice"
                class="w-full max-w-3xl px-4 py-3 rounded-xl bg-green-50 border border-green-100 text-sm text-green-700 flex items-start gap-2.5"
            >
                <Check class="shrink-0 mt-0.5" />
                <span>{{ notice }}</span>
            </div>

            <!-- Cargando -->
            <div v-if="loading" class="w-full max-w-3xl grid grid-cols-1 md:grid-cols-3 gap-5">
                <div
                    v-for="n in 3"
                    :key="n"
                    class="glass-card p-6 flex flex-col items-center gap-4 animate-pulse"
                >
                    <div class="w-16 h-16 rounded-2xl bg-primary/8"></div>
                    <div class="h-3 w-20 rounded bg-primary/8"></div>
                    <div class="h-2 w-16 rounded bg-primary/5"></div>
                    <div class="h-9 w-32 rounded-xl bg-primary/8 mt-1"></div>
                </div>
            </div>

            <!-- Tarjetas -->
            <div v-else class="w-full max-w-3xl grid grid-cols-1 md:grid-cols-3 gap-5">
                <div
                    v-for="cuenta in accounts"
                    :key="cuenta.channel"
                    class="glass-card p-6 flex flex-col items-center gap-4 hover:-translate-y-0.5 hover:shadow-md transition-all relative"
                >
                    <!-- Insignia de conectado -->
                    <span
                        v-if="cuenta.connected"
                        class="absolute top-3 right-3 flex items-center gap-1 text-[9px] font-bold uppercase tracking-widest text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full"
                    >
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Activo
                    </span>

                    <span
                        v-else-if="cuenta.status === 'error'"
                        class="absolute top-3 right-3 text-[9px] font-bold uppercase tracking-widest text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full"
                    >
                        Error
                    </span>

                    <!-- Icono -->
                    <div
                        class="w-16 h-16 rounded-2xl flex items-center justify-center shadow-md transition-all"
                        :class="[GRADIENTES[cuenta.channel], cuenta.connected ? '' : 'grayscale opacity-60']"
                    >
                        <component :is="ICONOS[cuenta.channel]" class="text-2xl text-white" />
                    </div>

                    <!-- Nombre y estado -->
                    <div class="text-center">
                        <p class="font-semibold text-primary">{{ cuenta.label }}</p>
                        <p class="text-[11px] mt-0.5" :class="estadoClase(cuenta)">
                            {{ estadoTexto(cuenta) }}
                        </p>
                        <p
                            v-if="cuenta.connected && cuenta.external_id"
                            class="text-[10px] text-primary/35 mt-0.5 font-mono truncate max-w-[160px]"
                            :title="cuenta.external_id"
                        >
                            ID {{ cuenta.external_id }}
                        </p>
                    </div>

                    <p class="text-[11px] text-primary/45 text-center leading-relaxed min-h-[2.5rem]">
                        {{ DESCRIPCIONES[cuenta.channel] }}
                    </p>

                    <!-- El mensaje de Meta suele decir exactamente qué permiso falta -->
                    <p
                        v-if="cuenta.error_message"
                        class="text-[10px] text-red-600 text-center leading-relaxed bg-red-50 border border-red-100 rounded-lg px-2 py-1.5"
                    >
                        {{ cuenta.error_message }}
                    </p>

                    <!-- Qué falta en el .env, para que el dev no adivine -->
                    <p
                        v-else-if="!cuenta.connected && cuenta.missing_env.length"
                        class="text-[10px] text-primary/40 text-center leading-relaxed"
                    >
                        Falta en el servidor:
                        <span class="font-mono">{{ cuenta.missing_env.join(', ') }}</span>
                    </p>

                    <!-- Acciones -->
                    <div class="flex flex-col items-stretch gap-2 w-full mt-auto">
                        <button
                            v-if="!cuenta.connected"
                            :disabled="!cuenta.can_connect || working === cuenta.channel"
                            @click="connect(cuenta.channel)"
                            class="flex items-center justify-center gap-2 text-sm font-bold px-5 py-2.5 rounded-xl border-2 border-primary/20 text-primary/70 transition-all hover:bg-primary hover:text-white hover:border-primary disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-primary/70 disabled:hover:border-primary/20 cursor-pointer"
                            :title="cuenta.can_connect ? 'Abre la ventana de Meta' : 'Falta META_APP_ID en el servidor'"
                        >
                            <Plus v-if="working !== cuenta.channel" class="text-xs shrink-0" />
                            {{ working === cuenta.channel ? 'Conectando...' : 'Vincular cuenta' }}
                        </button>

                        <template v-else>
                            <button
                                :disabled="working === cuenta.channel"
                                @click="verify(cuenta.channel)"
                                class="text-[11px] font-bold uppercase tracking-widest px-3 py-2 rounded-xl border border-primary/15 text-primary/60 hover:bg-primary/5 hover:text-primary transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                            >
                                {{ working === cuenta.channel ? 'Verificando...' : 'Verificar conexión' }}
                            </button>

                            <!-- Solo lo vinculado desde el CRM se puede
                                 desvincular: si vino del .env, hay que editar
                                 el servidor y el botón mentiría. -->
                            <button
                                v-if="cuenta.source === 'crm'"
                                :disabled="working === cuenta.channel"
                                @click="disconnect(cuenta.channel)"
                                class="text-[11px] font-bold uppercase tracking-widest px-3 py-2 rounded-xl text-red-500 hover:bg-red-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                            >
                                Desvincular
                            </button>

                            <p v-else class="text-[10px] text-primary/35 text-center leading-relaxed">
                                Configurado en el servidor
                            </p>
                        </template>
                    </div>

                    <!-- Pie con la trazabilidad -->
                    <div
                        v-if="cuenta.connected && (cuenta.connected_by || cuenta.verified_at)"
                        class="w-full pt-2 border-t border-primary/8 text-[10px] text-primary/35 text-center leading-relaxed"
                    >
                        <p v-if="cuenta.connected_by">Vinculó {{ cuenta.connected_by }}</p>
                        <p v-if="cuenta.verified_at">Verificado el {{ fecha(cuenta.verified_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- Nota al pie: por qué el popup es de Meta y no del CRM -->
            <p v-if="!loading" class="text-[11px] text-primary/35 text-center max-w-md leading-relaxed">
                La vinculación se hace en una ventana de Meta: el CRM nunca ve tu contraseña.
                Podés revocar el acceso cuando quieras desde la configuración de tu cuenta de Meta.
            </p>
        </div>
    </div>
</template>
