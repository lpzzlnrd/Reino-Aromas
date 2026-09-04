<script setup lang="ts">
    import { computed, onMounted, ref } from 'vue'
    import Header from '../header/header.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Close from '../../icons/icon.close.vue'
    import Info from '../../icons/icon.info.vue'
    import { useModal } from '@/composables/useModal'
    import {
        useInstagramAutomations,
        type AutomationKind,
        type InstagramAutomation,
        type ResponseType,
    } from '@/hooks/useInstagramAutomations'
    import api from '@/lib/axios'

    /*
     * Instagram no tiene WhatsApp Flows. Ice Breakers (preguntas frecuentes
     * antes de escribir) y Persistent Menu (menú fijo en el chat) son lo más
     * cercano, y esta vista es donde se configuran sin entrar al panel de Meta.
     */
    const {
        iceBreakers,
        menuItems,
        limits,
        pendingSync,
        cargando,
        sincronizando,
        error,
        ultimoResultado,
        puedeAgregarIceBreaker,
        puedeAgregarMenuItem,
        roto,
        cargar,
        crear,
        actualizar,
        eliminar,
        sincronizar,
    } = useInstagramAutomations()

    /*
     * Las plantillas se piden directo, igual que en templates.home.vue: no hay
     * un hook de plantillas en el proyecto y crear uno solo para este selector
     * seria mas codigo que el que ahorra.
     */
    type PlantillaBreve = { id: number; name: string; city: string | null; is_active: boolean }

    const plantillas = ref<PlantillaBreve[]>([])

    const cargarPlantillas = async (): Promise<void> => {
        try {
            const res = await api.get('/templates')
            plantillas.value = res.data?.data ?? res.data ?? []
        } catch {
            // El selector queda vacio y el aviso de "sin respuesta" hace el
            // resto: no vale la pena romper la vista por esto.
        }
    }

    onMounted(() => {
        cargar()
        cargarPlantillas()
    })

    /** Solo las activas: una plantilla desactivada no responde nada. */
    const plantillasDisponibles = computed(() => plantillas.value.filter((t) => t.is_active))

    // --- Modal de alta / edición ---

    const modalAbierto = ref(false)
    const panelModal = ref<HTMLElement | null>(null)
    const editando = ref<InstagramAutomation | null>(null)
    const guardando = ref(false)

    const form = ref({
        kind: 'ice_breaker' as AutomationKind,
        title: '',
        payload: '',
        response_type: 'template' as ResponseType,
        template_id: null as number | null,
        response_text: '',
        url: '',
        is_active: true,
    })

    useModal(modalAbierto, () => cerrarModal(), { panel: panelModal })

    const abrirNuevo = (kind: AutomationKind): void => {
        editando.value = null
        form.value = {
            kind,
            title: '',
            payload: '',
            response_type: 'template',
            template_id: null,
            response_text: '',
            url: '',
            is_active: true,
        }
        error.value = null
        modalAbierto.value = true
    }

    const abrirEdicion = (boton: InstagramAutomation): void => {
        editando.value = boton
        form.value = {
            kind: boton.kind,
            title: boton.title,
            payload: boton.payload,
            response_type: boton.response_type,
            template_id: boton.template_id,
            response_text: boton.response_text ?? '',
            url: boton.url ?? '',
            is_active: boton.is_active,
        }
        error.value = null
        modalAbierto.value = true
    }

    const cerrarModal = (): void => {
        modalAbierto.value = false
        editando.value = null
    }

    /**
     * Sugiere el identificador a partir del título.
     *
     * El payload es lo que Meta devuelve en el webhook y debe ser MAYUSCULAS,
     * números y guión bajo. Pedirle a alguien de negocio que lo escriba a mano
     * es la forma más rápida de que el botón no responda nada, así que se
     * genera solo mientras no lo hayan tocado.
     */
    const payloadTocado = ref(false)

    const alEscribirTitulo = (): void => {
        if (payloadTocado.value || editando.value !== null) return

        form.value.payload = form.value.title
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 120)
    }

    const guardar = async (): Promise<void> => {
        guardando.value = true

        const datos = {
            ...form.value,
            // El backend rechaza url en un ice_breaker; se manda null en vez de
            // cadena vacía para que la validación de 'url' no se dispare.
            url: form.value.kind === 'menu_item' && form.value.url !== '' ? form.value.url : null,
            response_text: form.value.response_type === 'text' ? form.value.response_text : null,
            template_id: form.value.response_type === 'template' ? form.value.template_id : null,
        }

        const ok = editando.value !== null
            ? await actualizar(editando.value.id, datos)
            : await crear(datos)

        guardando.value = false

        if (ok) {
            payloadTocado.value = false
            cerrarModal()
        }
    }

    // --- Borrado ---

    const confirmandoBorrado = ref<InstagramAutomation | null>(null)
    const panelBorrado = ref<HTMLElement | null>(null)

    useModal(confirmandoBorrado, () => (confirmandoBorrado.value = null), {
        panel: panelBorrado,
        // Escape no cierra una confirmación destructiva: es demasiado fácil
        // pulsarlo sin querer.
        cerrarConEscape: false,
    })

    const confirmarBorrado = async (): Promise<void> => {
        if (confirmandoBorrado.value === null) return

        await eliminar(confirmandoBorrado.value.id)
        confirmandoBorrado.value = null
    }

    const etiquetaRespuesta = (b: InstagramAutomation): string => {
        if (b.url) return 'Abre un enlace'

        return {
            template: b.template_name !== null ? `Plantilla: ${b.template_name}` : 'Plantilla borrada',
            text: 'Texto fijo',
            handoff: 'Lo atiende un agente',
        }[b.response_type]
    }
</script>

<template>
    <div class="flex flex-col min-h-screen">

        <section class="p-2">
            <Header class="hidden md:flex w-full flex-col" />
        </section>

        <div class="flex-1 px-6 lg:px-12 py-10 flex flex-col items-center gap-8">

            <!-- Intro -->
            <section class="text-center flex flex-col gap-3 max-w-xl">
                <h1 class="font-primary text-3xl text-primary">Automatizaciones de Instagram</h1>
                <p class="text-sm text-primary/55 leading-relaxed">
                    Botones que aparecen en el chat de Instagram y responden solos.
                    Reemplazan los primeros mensajes que hoy escribe un agente a mano.
                </p>
            </section>

            <!-- Aviso: hay cambios sin enviar a Instagram -->
            <div
                v-if="pendingSync && !cargando"
                class="w-full max-w-3xl px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2.5"
            >
                <Info class="text-amber-500 shrink-0 mt-0.5" />
                <div class="flex-1 text-xs text-amber-900 leading-relaxed">
                    <p class="font-bold">Hay cambios sin publicar</p>
                    <p>
                        Los clientes siguen viendo la configuración anterior hasta que
                        la envíes a Instagram.
                    </p>
                </div>
                <button
                    @click="sincronizar"
                    :disabled="sincronizando"
                    class="btn-primary text-xs py-2 px-4 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ sincronizando ? 'Enviando...' : 'Publicar' }}
                </button>
            </div>

            <!-- Aviso: botones apuntando a plantillas muertas -->
            <div
                v-if="roto && !cargando"
                class="w-full max-w-3xl px-4 py-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2.5"
            >
                <Info class="text-red-500 shrink-0 mt-0.5" />
                <p class="text-xs text-red-900 leading-relaxed">
                    <span class="font-bold">Hay botones sin respuesta.</span>
                    Apuntan a una plantilla borrada o desactivada: si alguien los toca,
                    no recibirá nada y el caso queda esperando a un agente.
                </p>
            </div>

            <p v-if="error" class="text-xs text-red-600 max-w-3xl">{{ error }}</p>

            <p v-if="ultimoResultado" class="text-xs text-primary/60 max-w-3xl">
                {{ ultimoResultado }}
            </p>

            <!-- Ice Breakers -->
            <section class="w-full max-w-3xl flex flex-col gap-3">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <h2 class="font-primary text-xl text-primary flex items-center gap-2">
                            <Instagram class="text-secondary" />
                            Preguntas frecuentes
                        </h2>
                        <p class="text-xs text-primary/50 mt-0.5">
                            Se muestran ANTES de que el cliente escriba.
                            Máximo {{ limits.ice_breakers }}.
                        </p>
                    </div>
                    <button
                        @click="abrirNuevo('ice_breaker')"
                        :disabled="!puedeAgregarIceBreaker"
                        class="btn-primary text-xs py-2 px-4 shrink-0 disabled:opacity-40 disabled:cursor-not-allowed"
                        :title="puedeAgregarIceBreaker ? '' : `Instagram permite solo ${limits.ice_breakers}`"
                    >
                        <Plus /> Agregar
                    </button>
                </div>

                <p v-if="cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

                <p v-else-if="iceBreakers.length === 0" class="glass-card p-6 text-sm text-primary/50 text-center">
                    Sin preguntas configuradas. El cliente verá el chat vacío y tendrá
                    que escribir por su cuenta.
                </p>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="b in iceBreakers"
                        :key="b.id"
                        class="glass-card px-4 py-3 flex items-center gap-3"
                        :class="{ 'opacity-50': !b.is_active }"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-primary truncate">{{ b.title }}</p>
                            <p class="text-[11px] text-primary/45">
                                {{ etiquetaRespuesta(b) }}
                                <span v-if="b.hits > 0"> · {{ b.hits }} {{ b.hits === 1 ? 'toque' : 'toques' }}</span>
                            </p>
                        </div>

                        <span
                            v-if="b.broken"
                            class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-red-100 text-red-700 shrink-0"
                        >Sin respuesta</span>

                        <span
                            v-else-if="b.needs_sync"
                            class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-amber-100 text-amber-800 shrink-0"
                        >Sin publicar</span>

                        <button
                            @click="abrirEdicion(b)"
                            class="text-[11px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer shrink-0"
                        >Editar</button>

                        <button
                            @click="confirmandoBorrado = b"
                            class="p-1.5 rounded-lg text-primary/30 hover:text-red-500 hover:bg-red-50 transition-colors cursor-pointer shrink-0"
                            aria-label="Eliminar"
                        ><Close /></button>
                    </div>
                </div>
            </section>

            <!-- Persistent Menu -->
            <section class="w-full max-w-3xl flex flex-col gap-3">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <h2 class="font-primary text-xl text-primary">Menú del chat</h2>
                        <p class="text-xs text-primary/50 mt-0.5">
                            Siempre visible dentro de la conversación.
                            Recomendado hasta {{ limits.menu_items }}.
                        </p>
                    </div>
                    <button
                        @click="abrirNuevo('menu_item')"
                        :disabled="!puedeAgregarMenuItem"
                        class="btn-primary text-xs py-2 px-4 shrink-0 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <Plus /> Agregar
                    </button>
                </div>

                <p v-if="cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

                <p v-else-if="menuItems.length === 0" class="glass-card p-6 text-sm text-primary/50 text-center">
                    Sin menú configurado.
                </p>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="b in menuItems"
                        :key="b.id"
                        class="glass-card px-4 py-3 flex items-center gap-3"
                        :class="{ 'opacity-50': !b.is_active }"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-primary truncate">{{ b.title }}</p>
                            <p class="text-[11px] text-primary/45">
                                {{ etiquetaRespuesta(b) }}
                                <span v-if="b.hits > 0"> · {{ b.hits }} {{ b.hits === 1 ? 'toque' : 'toques' }}</span>
                            </p>
                        </div>

                        <span
                            v-if="b.broken"
                            class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-red-100 text-red-700 shrink-0"
                        >Sin respuesta</span>

                        <span
                            v-else-if="b.needs_sync"
                            class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-amber-100 text-amber-800 shrink-0"
                        >Sin publicar</span>

                        <button
                            @click="abrirEdicion(b)"
                            class="text-[11px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer shrink-0"
                        >Editar</button>

                        <button
                            @click="confirmandoBorrado = b"
                            class="p-1.5 rounded-lg text-primary/30 hover:text-red-500 hover:bg-red-50 transition-colors cursor-pointer shrink-0"
                            aria-label="Eliminar"
                        ><Close /></button>
                    </div>
                </div>
            </section>

            <!-- Publicar -->
            <section class="w-full max-w-3xl flex justify-center pt-2">
                <button
                    @click="sincronizar"
                    :disabled="sincronizando || cargando"
                    class="btn-primary text-sm py-2.5 px-6 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ sincronizando ? 'Enviando a Instagram...' : 'Publicar en Instagram' }}
                </button>
            </section>

            <p class="text-[11px] text-primary/40 max-w-xl text-center leading-relaxed">
                Instagram no actualiza el menú en tiempo real: quien ya tenga el chat
                abierto verá los cambios al refrescar su bandeja.
            </p>
        </div>

        <!-- Modal de alta / edición -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="modalAbierto" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-primary/30 backdrop-blur-sm" @click="cerrarModal" />

                    <div
                        ref="panelModal"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Configurar botón"
                        class="relative w-full max-w-md bg-surface rounded-2xl shadow-2xl max-h-[90dvh] overflow-y-auto"
                    >
                        <header class="px-6 py-5 border-b border-primary/8 flex items-center justify-between gap-3">
                            <h2 class="font-primary text-lg text-primary">
                                {{ editando ? 'Editar botón' : 'Nuevo botón' }}
                            </h2>
                            <button
                                @click="cerrarModal"
                                class="p-2 rounded-xl hover:bg-primary/5 text-primary/40 hover:text-primary transition-colors cursor-pointer"
                                aria-label="Cerrar"
                            ><Close /></button>
                        </header>

                        <div class="px-6 py-5 flex flex-col gap-4">

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">
                                    Texto del botón
                                </span>
                                <input
                                    v-model="form.title"
                                    @input="alEscribirTitulo"
                                    type="text"
                                    maxlength="80"
                                    placeholder="¿Cuánto cuesta el curso?"
                                    class="input-group text-sm py-2 px-3 w-full"
                                >
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">
                                    Identificador interno
                                </span>
                                <input
                                    v-model="form.payload"
                                    @input="payloadTocado = true"
                                    type="text"
                                    placeholder="PRECIO_CURSO"
                                    class="input-group text-sm py-2 px-3 w-full font-mono"
                                >
                                <span class="text-[10px] text-primary/40 leading-relaxed">
                                    Solo MAYÚSCULAS, números y guión bajo. Se genera solo
                                    desde el texto; no hace falta tocarlo.
                                </span>
                            </label>

                            <label class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">¿Qué responde?</span>
                                <select v-model="form.response_type" class="input-group text-sm py-2 px-3 w-full cursor-pointer">
                                    <option value="template">Una plantilla</option>
                                    <option value="text">Un texto que escribo acá</option>
                                    <option value="handoff">Nada, lo atiende un agente</option>
                                </select>
                            </label>

                            <label v-if="form.response_type === 'template'" class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">Plantilla</span>
                                <select v-model="form.template_id" class="input-group text-sm py-2 px-3 w-full cursor-pointer">
                                    <option :value="null">Elegí una...</option>
                                    <option v-for="t in plantillasDisponibles" :key="t.id" :value="t.id">
                                        {{ t.name }}{{ t.city ? ` — ${t.city}` : '' }}
                                    </option>
                                </select>
                            </label>

                            <label v-if="form.response_type === 'text'" class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">Respuesta</span>
                                <textarea
                                    v-model="form.response_text"
                                    rows="4"
                                    maxlength="900"
                                    class="input-group text-sm py-2 px-3 w-full resize-none"
                                    placeholder="¡Hola! El curso cuesta..."
                                />
                            </label>

                            <label v-if="form.kind === 'menu_item'" class="flex flex-col gap-1">
                                <span class="text-[11px] font-semibold text-primary/60">
                                    Enlace (opcional)
                                </span>
                                <input
                                    v-model="form.url"
                                    type="url"
                                    placeholder="https://reinoaromas.tech"
                                    class="input-group text-sm py-2 px-3 w-full"
                                >
                                <span class="text-[10px] text-primary/40 leading-relaxed">
                                    Si lo llenás, el botón abre esa página en vez de responder
                                    un mensaje.
                                </span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="cursor-pointer">
                                <span class="text-xs text-primary/70">Activo</span>
                            </label>

                            <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
                        </div>

                        <footer class="px-6 py-4 border-t border-primary/8 flex gap-2">
                            <button
                                @click="guardar"
                                :disabled="guardando"
                                class="btn-primary text-xs py-2 px-4 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {{ guardando ? 'Guardando...' : 'Guardar' }}
                            </button>
                            <button
                                @click="cerrarModal"
                                class="text-xs font-semibold text-primary/50 hover:text-primary px-3 py-2 rounded-xl hover:bg-primary/5 transition-colors cursor-pointer"
                            >Cancelar</button>
                        </footer>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Confirmar borrado -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="confirmandoBorrado" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-primary/30 backdrop-blur-sm" />

                    <div
                        ref="panelBorrado"
                        role="dialog"
                        aria-modal="true"
                        class="relative w-full max-w-sm bg-surface rounded-2xl shadow-2xl p-6 flex flex-col gap-4"
                    >
                        <h2 class="font-primary text-lg text-primary">¿Eliminar este botón?</h2>
                        <p class="text-sm text-primary/60 leading-relaxed">
                            «{{ confirmandoBorrado.title }}» dejará de aparecer en Instagram
                            cuando publiques los cambios.
                        </p>
                        <div class="flex gap-2">
                            <button
                                @click="confirmarBorrado"
                                class="text-xs font-bold uppercase tracking-widest bg-red-500 hover:bg-red-600 text-white py-2 px-4 rounded-xl transition-colors cursor-pointer"
                            >Eliminar</button>
                            <button
                                @click="confirmandoBorrado = null"
                                class="text-xs font-semibold text-primary/50 hover:text-primary px-3 py-2 rounded-xl hover:bg-primary/5 transition-colors cursor-pointer"
                            >Cancelar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
