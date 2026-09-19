<script setup lang="ts">
    import { computed, onMounted, ref } from 'vue'
    import Plus from '../../icons/icon.plus.vue'
    import Close from '../../icons/icon.close.vue'
    import Info from '../../icons/icon.info.vue'
    import { useModal } from '@/composables/useModal'
    import {
        useInstagramQuickReplyMenus,
        type QuickReplyMenu,
        type QuickReplyOption,
    } from '@/hooks/useInstagramQuickReplyMenus'
    import type { ResponseType } from '@/hooks/useInstagramAutomations'

    /*
     * Menús de opciones de Instagram: un mensaje con burbujas que el cliente
     * toca para elegir. Al tocar una, Meta dispara el mismo webhook de postback
     * que los Ice Breakers y el CRM responde con la plantilla de esa opción.
     *
     * La vista se apoya en una previsualización del chat porque "13 burbujas de
     * 20 caracteres" no le dice nada a quien configura: ver el mensaje como le
     * llega al cliente sí.
     */
    const {
        menus,
        limits,
        cargando,
        guardando,
        error,
        cargar,
        crearMenu,
        actualizarMenu,
        eliminarMenu,
        crearOpcion,
        actualizarOpcion,
        eliminarOpcion,
    } = useInstagramQuickReplyMenus()

    /*
     * Las plantillas llegan por prop desde la vista que monta este componente,
     * igual que en settings.instagramCommentDm.vue: la pantalla ya las pidió
     * una vez y volver a pedirlas sería una llamada de más por la misma lista.
     */
    type PlantillaBreve = { id: number; name: string; is_active: boolean }

    const props = defineProps<{ plantillas: PlantillaBreve[] }>()

    onMounted(() => cargar())

    /** Solo las activas: una plantilla desactivada no responde nada. */
    const plantillasDisponibles = computed(() => props.plantillas.filter((t) => t.is_active))

    /** El menú abierto en el panel de edición. null = ninguno. */
    const menuAbierto = ref<QuickReplyMenu | null>(null)

    const menuActual = computed(
        () => menus.value.find((m) => m.id === menuAbierto.value?.id) ?? null,
    )

    // --- Modal de menú (crear / renombrar) ---

    const modalMenu = ref(false)
    const panelMenu = ref<HTMLElement | null>(null)
    const editandoMenu = ref<QuickReplyMenu | null>(null)

    const formMenu = ref({ name: '', body: '', is_active: true })

    useModal(modalMenu, () => cerrarModalMenu(), { panel: panelMenu })

    const abrirNuevoMenu = (): void => {
        editandoMenu.value = null
        formMenu.value = { name: '', body: '', is_active: true }
        modalMenu.value = true
    }

    const abrirEdicionMenu = (menu: QuickReplyMenu): void => {
        editandoMenu.value = menu
        formMenu.value = { name: menu.name, body: menu.body, is_active: menu.is_active }
        modalMenu.value = true
    }

    const cerrarModalMenu = (): void => {
        modalMenu.value = false
        editandoMenu.value = null
    }

    const guardarMenu = async (): Promise<void> => {
        const ok = editandoMenu.value
            ? await actualizarMenu(editandoMenu.value.id, formMenu.value)
            : await crearMenu(formMenu.value)

        if (ok) cerrarModalMenu()
    }

    // --- Modal de opción ---

    const modalOpcion = ref(false)
    const panelOpcion = ref<HTMLElement | null>(null)
    const editandoOpcion = ref<QuickReplyOption | null>(null)

    const formOpcion = ref({
        title: '',
        response_type: 'template' as ResponseType,
        template_id: null as number | null,
        response_text: '',
        is_active: true,
    })

    useModal(modalOpcion, () => cerrarModalOpcion(), { panel: panelOpcion })

    const abrirNuevaOpcion = (): void => {
        editandoOpcion.value = null
        formOpcion.value = {
            title: '',
            response_type: 'template',
            template_id: null,
            response_text: '',
            is_active: true,
        }
        modalOpcion.value = true
    }

    const abrirEdicionOpcion = (opcion: QuickReplyOption): void => {
        editandoOpcion.value = opcion
        formOpcion.value = {
            title: opcion.title,
            response_type: opcion.response_type,
            template_id: opcion.template_id,
            response_text: opcion.response_text ?? '',
            is_active: opcion.is_active,
        }
        modalOpcion.value = true
    }

    const cerrarModalOpcion = (): void => {
        modalOpcion.value = false
        editandoOpcion.value = null
    }

    const guardarOpcion = async (): Promise<void> => {
        const menu = menuActual.value
        if (!menu) return

        // Los campos del tipo que NO se eligió se mandan en null: dejarlos con
        // su valor anterior guardaría un texto que nunca se va a usar y que
        // reaparecería al cambiar de tipo.
        const datos = {
            title: formOpcion.value.title,
            response_type: formOpcion.value.response_type,
            template_id: formOpcion.value.response_type === 'template'
                ? formOpcion.value.template_id
                : null,
            response_text: formOpcion.value.response_type === 'text'
                ? formOpcion.value.response_text
                : null,
            is_active: formOpcion.value.is_active,
        }

        const ok = editandoOpcion.value
            ? await actualizarOpcion(menu.id, editandoOpcion.value.id, datos)
            : await crearOpcion(menu.id, datos)

        if (ok) cerrarModalOpcion()
    }

    // --- Confirmaciones de borrado ---

    const borrandoMenu = ref<QuickReplyMenu | null>(null)
    const borrandoOpcion = ref<QuickReplyOption | null>(null)

    useModal(borrandoMenu, () => (borrandoMenu.value = null))
    useModal(borrandoOpcion, () => (borrandoOpcion.value = null))

    const confirmarBorrarMenu = async (): Promise<void> => {
        if (!borrandoMenu.value) return

        const id = borrandoMenu.value.id
        const ok = await eliminarMenu(id)

        if (ok && menuAbierto.value?.id === id) menuAbierto.value = null

        borrandoMenu.value = null
    }

    const confirmarBorrarOpcion = async (): Promise<void> => {
        const menu = menuActual.value
        if (!borrandoOpcion.value || !menu) return

        await eliminarOpcion(menu.id, borrandoOpcion.value.id)
        borrandoOpcion.value = null
    }

    // --- Helpers de presentación ---

    const puedeAgregarOpcion = computed(
        () => (menuActual.value?.options.length ?? 0) < limits.value.opciones,
    )

    /** Las burbujas que se verían hoy: solo las activas, en orden. */
    const opcionesVisibles = computed(
        () => menuActual.value?.options.filter((o) => o.is_active) ?? [],
    )

    const etiquetaRespuesta = (o: QuickReplyOption): string => {
        if (o.response_type === 'handoff') return 'Lo atiende un agente'
        if (o.response_type === 'text') return 'Texto propio'

        return o.template_name ? `Plantilla: ${o.template_name}` : 'Plantilla sin elegir'
    }
</script>

<template>
    <section class="w-full max-w-3xl flex flex-col gap-3">

        <!-- Encabezado -->
        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="font-primary text-xl text-primary">Menús de opciones</h2>
                <p class="text-xs text-primary/50 mt-0.5">
                    Un mensaje con botones para que el cliente elija.
                    Cada opción responde distinto, sin que intervenga un agente.
                </p>
            </div>
            <button
                @click="abrirNuevoMenu"
                class="btn-primary text-xs py-2 px-4 flex items-center gap-1.5 shrink-0"
            >
                <Plus /> Nuevo menú
            </button>
        </div>

        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

        <p v-if="cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

        <!-- Sin menús -->
        <div
            v-else-if="menus.length === 0"
            class="glass-card p-6 flex flex-col items-center gap-2 text-center"
        >
            <p class="text-sm text-primary/60">Todavía no hay menús.</p>
            <p class="text-xs text-primary/40 max-w-sm leading-relaxed">
                Un menú convierte un comentario en una conversación guiada: la persona
                toca «Ver precios» o «Cómo reservar» y recibe la respuesta al instante.
            </p>
        </div>

        <!-- Lista de menús -->
        <div v-else class="flex flex-col gap-2">
            <div
                v-for="m in menus"
                :key="m.id"
                class="glass-card px-4 py-3 flex items-center gap-3 cursor-pointer hover:border-secondary/30 transition-colors"
                :class="{ 'opacity-50': !m.is_active, 'border-secondary/40': menuAbierto?.id === m.id }"
                @click="menuAbierto = menuAbierto?.id === m.id ? null : m"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-primary truncate">{{ m.name }}</p>
                    <p class="text-[11px] text-primary/45">
                        {{ m.options.length }}
                        {{ m.options.length === 1 ? 'opción' : 'opciones' }}
                        <span v-if="m.sends > 0"> · {{ m.sends }} {{ m.sends === 1 ? 'envío' : 'envíos' }}</span>
                    </p>
                </div>

                <span
                    v-if="m.is_active && !m.is_complete"
                    class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-red-100 text-red-700 shrink-0"
                >Sin opciones</span>

                <span
                    v-else-if="m.broken_options > 0"
                    class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full bg-red-100 text-red-700 shrink-0"
                >{{ m.broken_options }} sin respuesta</span>

                <button
                    @click.stop="abrirEdicionMenu(m)"
                    class="text-[11px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer shrink-0"
                >Editar</button>

                <button
                    @click.stop="borrandoMenu = m"
                    class="p-1.5 rounded-lg text-primary/30 hover:text-red-500 hover:bg-red-50 transition-colors cursor-pointer shrink-0"
                    aria-label="Eliminar menú"
                ><Close /></button>
            </div>
        </div>

        <!-- Detalle del menú abierto: opciones + previsualización -->
        <div v-if="menuActual" class="glass-card p-5 flex flex-col gap-5 mt-1">

            <div class="flex items-center justify-between gap-4">
                <h3 class="font-primary text-lg text-primary">{{ menuActual.name }}</h3>
                <button
                    @click="abrirNuevaOpcion"
                    :disabled="!puedeAgregarOpcion"
                    class="btn-primary text-xs py-2 px-4 flex items-center gap-1.5 shrink-0 disabled:opacity-40 disabled:cursor-not-allowed"
                    :title="puedeAgregarOpcion ? '' : `Instagram permite hasta ${limits.opciones} opciones`"
                >
                    <Plus /> Agregar opción
                </button>
            </div>

            <!-- Aviso: menú sin opciones.
                 Es rojo y no ámbar porque Meta permite UN solo DM por
                 comentario: si sale sin burbujas, esa persona se queda sin
                 opciones para siempre. -->
            <div
                v-if="menuActual.is_active && !menuActual.is_complete"
                class="px-4 py-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2.5"
            >
                <Info class="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                <p class="text-xs text-red-900 leading-relaxed">
                    <span class="font-bold">Este menú no tiene opciones activas.</span>
                    Si se envía así, el cliente recibe el mensaje sin botones que tocar —
                    y como Instagram permite un solo mensaje por comentario, no hay forma
                    de mandárselos después.
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-5">

                <!-- Columna de opciones -->
                <div class="flex flex-col gap-2">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Opciones ({{ menuActual.options.length }}/{{ limits.opciones }})
                    </p>

                    <p
                        v-if="menuActual.options.length === 0"
                        class="text-xs text-primary/40 py-3"
                    >
                        Todavía sin opciones. Agrega la primera.
                    </p>

                    <div
                        v-for="o in menuActual.options"
                        :key="o.id"
                        class="px-3 py-2.5 rounded-xl border border-primary/10 flex items-center gap-2.5"
                        :class="{ 'opacity-50': !o.is_active }"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-primary truncate">{{ o.title }}</p>
                            <p class="text-[10px] text-primary/45">
                                {{ etiquetaRespuesta(o) }}
                                <span v-if="o.hits > 0"> · {{ o.hits }} {{ o.hits === 1 ? 'toque' : 'toques' }}</span>
                            </p>
                        </div>

                        <span
                            v-if="o.is_broken"
                            class="text-[9px] font-bold uppercase tracking-widest px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 shrink-0"
                        >Sin respuesta</span>

                        <button
                            @click="abrirEdicionOpcion(o)"
                            class="text-[10px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer shrink-0"
                        >Editar</button>

                        <button
                            @click="borrandoOpcion = o"
                            class="p-1 rounded-lg text-primary/30 hover:text-red-500 hover:bg-red-50 transition-colors cursor-pointer shrink-0"
                            aria-label="Eliminar opción"
                        ><Close /></button>
                    </div>
                </div>

                <!-- Previsualización del chat.
                     Es la pieza que hace entendible la configuración: "13
                     burbujas de 20 caracteres" no significa nada hasta que se
                     ve el mensaje como le llega al cliente. -->
                <div class="flex flex-col gap-2">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Así lo recibe el cliente
                    </p>

                    <div class="rounded-2xl border border-primary/10 bg-primary/[0.03] p-4 flex flex-col gap-3">
                        <!-- El mensaje -->
                        <div class="self-start max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-bl-md bg-white border border-primary/10">
                            <p class="text-xs text-primary leading-relaxed whitespace-pre-wrap break-words">{{ menuActual.body }}</p>
                        </div>

                        <!-- Las burbujas -->
                        <div v-if="opcionesVisibles.length > 0" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="o in opcionesVisibles"
                                :key="o.id"
                                class="text-[11px] px-3 py-1.5 rounded-full border border-secondary/40 text-secondary bg-secondary/5"
                            >{{ o.title }}</span>
                        </div>

                        <p v-else class="text-[11px] text-red-600 italic">
                            Sin botones: el cliente solo podría responder escribiendo.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: crear / editar menú -->
        <Teleport to="body">
            <div
                v-if="modalMenu"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-primary/20 backdrop-blur-sm"
            >
                <div ref="panelMenu" class="glass-card w-full max-w-lg p-6 flex flex-col gap-4 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-primary text-xl text-primary">
                            {{ editandoMenu ? 'Editar menú' : 'Nuevo menú' }}
                        </h3>
                        <button
                            @click="cerrarModalMenu"
                            class="p-1.5 rounded-lg text-primary/40 hover:text-primary transition-colors cursor-pointer"
                            aria-label="Cerrar"
                        ><Close /></button>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Nombre
                        </span>
                        <input
                            v-model="formMenu.name"
                            type="text"
                            maxlength="120"
                            placeholder="Menú de bienvenida"
                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                        />
                        <span class="text-[10px] text-primary/35">Solo para reconocerlo acá. El cliente no lo ve.</span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Mensaje
                        </span>
                        <textarea
                            v-model="formMenu.body"
                            rows="4"
                            :maxlength="limits.cuerpo"
                            placeholder="¡Hola! Gracias por escribirnos 🌿 ¿Qué te gustaría saber?"
                            class="px-4 py-3 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25 resize-y leading-relaxed"
                        />
                        <span class="text-[10px] text-primary/35 ml-auto">
                            {{ formMenu.body.length }} / {{ limits.cuerpo }}
                        </span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="formMenu.is_active" type="checkbox" class="accent-secondary" />
                        <span class="text-xs text-primary/70">Activo</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button
                            @click="cerrarModalMenu"
                            class="text-xs font-bold uppercase tracking-widest text-primary/50 hover:text-primary px-4 py-2.5 transition-colors cursor-pointer"
                        >Cancelar</button>
                        <button
                            @click="guardarMenu"
                            :disabled="guardando || !formMenu.name.trim() || !formMenu.body.trim()"
                            class="btn-primary text-xs py-2.5 px-5 disabled:opacity-50 disabled:cursor-not-allowed"
                        >{{ guardando ? 'Guardando...' : 'Guardar' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal: crear / editar opción -->
        <Teleport to="body">
            <div
                v-if="modalOpcion"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-primary/20 backdrop-blur-sm"
            >
                <div ref="panelOpcion" class="glass-card w-full max-w-lg p-6 flex flex-col gap-4 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-primary text-xl text-primary">
                            {{ editandoOpcion ? 'Editar opción' : 'Nueva opción' }}
                        </h3>
                        <button
                            @click="cerrarModalOpcion"
                            class="p-1.5 rounded-lg text-primary/40 hover:text-primary transition-colors cursor-pointer"
                            aria-label="Cerrar"
                        ><Close /></button>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Texto del botón
                        </span>
                        <input
                            v-model="formOpcion.title"
                            type="text"
                            :maxlength="limits.titulo"
                            placeholder="Ver precios"
                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                        />
                        <span class="text-[10px] text-primary/35 ml-auto">
                            {{ formOpcion.title.length }} / {{ limits.titulo }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Al tocarlo responde
                        </span>
                        <select
                            v-model="formOpcion.response_type"
                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                        >
                            <option value="template">Una plantilla</option>
                            <option value="text">Un texto que escribo acá</option>
                            <option value="handoff">Nada, lo atiende un agente</option>
                        </select>
                    </label>

                    <label v-if="formOpcion.response_type === 'template'" class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Plantilla
                        </span>
                        <select
                            v-model="formOpcion.template_id"
                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                        >
                            <option :value="null">Elige una...</option>
                            <option v-for="t in plantillasDisponibles" :key="t.id" :value="t.id">
                                {{ t.name }}
                            </option>
                        </select>
                    </label>

                    <label v-if="formOpcion.response_type === 'text'" class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Respuesta
                        </span>
                        <textarea
                            v-model="formOpcion.response_text"
                            rows="4"
                            maxlength="900"
                            placeholder="Nuestros cursos van desde..."
                            class="px-4 py-3 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25 resize-y leading-relaxed"
                        />
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="formOpcion.is_active" type="checkbox" class="accent-secondary" />
                        <span class="text-xs text-primary/70">Activa</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button
                            @click="cerrarModalOpcion"
                            class="text-xs font-bold uppercase tracking-widest text-primary/50 hover:text-primary px-4 py-2.5 transition-colors cursor-pointer"
                        >Cancelar</button>
                        <button
                            @click="guardarOpcion"
                            :disabled="guardando || !formOpcion.title.trim()"
                            class="btn-primary text-xs py-2.5 px-5 disabled:opacity-50 disabled:cursor-not-allowed"
                        >{{ guardando ? 'Guardando...' : 'Guardar' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Confirmación: borrar menú -->
        <Teleport to="body">
            <div
                v-if="borrandoMenu"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-primary/20 backdrop-blur-sm"
            >
                <div class="glass-card w-full max-w-sm p-6 flex flex-col gap-3">
                    <h3 class="font-primary text-lg text-primary">¿Eliminar «{{ borrandoMenu.name }}»?</h3>
                    <p class="text-xs text-primary/55 leading-relaxed">
                        Sus opciones dejan de estar agrupadas, pero no se borran: pueden
                        haberse enviado ya y sus respuestas siguen funcionando.
                    </p>
                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button
                            @click="borrandoMenu = null"
                            class="text-xs font-bold uppercase tracking-widest text-primary/50 hover:text-primary px-4 py-2.5 transition-colors cursor-pointer"
                        >Cancelar</button>
                        <button
                            @click="confirmarBorrarMenu"
                            class="text-xs font-bold uppercase tracking-widest text-white bg-red-500 hover:bg-red-600 px-5 py-2.5 rounded-xl transition-colors cursor-pointer"
                        >Eliminar</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Confirmación: borrar opción -->
        <Teleport to="body">
            <div
                v-if="borrandoOpcion"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-primary/20 backdrop-blur-sm"
            >
                <div class="glass-card w-full max-w-sm p-6 flex flex-col gap-3">
                    <h3 class="font-primary text-lg text-primary">¿Eliminar «{{ borrandoOpcion.title }}»?</h3>
                    <p class="text-xs text-primary/55 leading-relaxed">
                        Quien ya haya recibido este menú y toque el botón no recibirá nada.
                    </p>
                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button
                            @click="borrandoOpcion = null"
                            class="text-xs font-bold uppercase tracking-widest text-primary/50 hover:text-primary px-4 py-2.5 transition-colors cursor-pointer"
                        >Cancelar</button>
                        <button
                            @click="confirmarBorrarOpcion"
                            class="text-xs font-bold uppercase tracking-widest text-white bg-red-500 hover:bg-red-600 px-5 py-2.5 rounded-xl transition-colors cursor-pointer"
                        >Eliminar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>
