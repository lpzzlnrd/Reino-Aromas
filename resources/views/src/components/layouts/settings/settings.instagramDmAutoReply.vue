<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue'
    import Info from '../../icons/icon.info.vue'
    import { useInstagramDmAutoReply, type DmResponseType, type DmTriggerMode } from '@/hooks/useInstagramDmAutoReply'
    import { useInstagramQuickReplyMenus } from '@/hooks/useInstagramQuickReplyMenus'

    /*
     * Respuesta automática a quien escribe un DM.
     *
     * Hermano de settings.instagramCommentDm: aquél atiende a quien COMENTA un
     * post, éste a quien ESCRIBE al buzón. Va como sección de la misma
     * pantalla por el mismo motivo -- para el negocio es una sola idea
     * ("Instagram responde solo") y separarlo obligaría a recordar en cuál de
     * dos pantallas está cada cosa.
     *
     * Las plantillas llegan por prop porque la vista padre ya las pidió.
     */

    type PlantillaBreve = { id: number; name: string; city: string | null; is_active: boolean }

    const props = defineProps<{ plantillas: PlantillaBreve[] }>()

    const {
        settings,
        stats,
        recent,
        cargando,
        guardando,
        guardado,
        error,
        roto,
        cargar,
        guardar,
        alternar,
    } = useInstagramDmAutoReply()

    onMounted(cargar)

    /*
     * Borrador local del formulario. No se edita `settings` directo: el
     * interruptor de encendido guarda solo su campo y arrastraría los cambios
     * a medio escribir.
     */
    const tipo = ref<DmResponseType>('menu')
    const texto = ref('')
    const plantillaId = ref<number | null>(null)
    const menuId = ref<number | null>(null)
    const disparo = ref<DmTriggerMode>('first')
    const saltarAsignados = ref(true)
    const espera = ref(60)
    const tope = ref(200)
    const clavesTexto = ref('')

    /** Ya se copió el estado del servidor al borrador al menos una vez. */
    const iniciado = ref(false)

    const { menus, cargar: cargarMenus } = useInstagramQuickReplyMenus()

    onMounted(cargarMenus)

    /*
     * Solo los menús enviables: activos y con alguna opción activa. Ofrecer
     * uno vacío dejaría elegir algo que llegaría al cliente sin botones.
     */
    const menusDisponibles = computed(() => menus.value.filter((m) => m.is_complete))

    /*
     * El Array.isArray NO sobra: este computed solo se evalúa cuando el
     * template pinta el bloque de «Plantilla», así que un prop mal formado no
     * explotaría al montar sino al cambiar de tipo — y como el error sube por
     * el render, se llevaría la sección entera. Es el bug que ya mordió en la
     * sección de al lado.
     */
    const plantillasDisponibles = computed(
        () => (Array.isArray(props.plantillas) ? props.plantillas : []).filter((t) => t.is_active),
    )

    const etiquetaTipo = (t: DmResponseType): string => {
        if (t === 'text') return 'Texto fijo'
        if (t === 'template') return 'Plantilla'

        return 'Menú de opciones'
    }

    const claves = computed(() =>
        clavesTexto.value
            .split(',')
            .map((c) => c.trim())
            .filter((c) => c !== ''),
    )

    /** Cambió algo respecto de lo guardado. */
    const hayCambios = computed(() => {
        if (settings.value === null) return false

        return (
            tipo.value !== settings.value.response_type ||
            texto.value !== (settings.value.response_text ?? '') ||
            plantillaId.value !== settings.value.template_id ||
            menuId.value !== (settings.value.quick_reply_menu_id ?? null) ||
            disparo.value !== settings.value.trigger_mode ||
            saltarAsignados.value !== settings.value.skip_if_assigned ||
            espera.value !== settings.value.cooldown_minutes ||
            tope.value !== settings.value.daily_limit ||
            claves.value.join(',') !== (settings.value.keywords ?? []).join(',')
        )
    })

    /*
     * Sincroniza el borrador con lo que llega del servidor.
     *
     * Va DESPUÉS de hayCambios a propósito: con { immediate: true } el watch
     * corre en esta misma línea, y declararlo antes lo haría leer hayCambios
     * en su zona muerta temporal (ReferenceError al montar).
     */
    watch(
        settings,
        (valor) => {
            if (valor === null) return

            // Solo se pisa el borrador la primera vez y cuando no hay nada sin
            // guardar: guardar() llama a cargar(), que reemplaza el objeto
            // entero, y sin esta guarda se perdería lo recién elegido.
            if (iniciado.value && hayCambios.value) return

            iniciado.value = true

            tipo.value = valor.response_type
            texto.value = valor.response_text ?? ''
            plantillaId.value = valor.template_id
            menuId.value = valor.quick_reply_menu_id ?? null
            disparo.value = valor.trigger_mode
            saltarAsignados.value = valor.skip_if_assigned
            espera.value = valor.cooldown_minutes
            tope.value = valor.daily_limit
            clavesTexto.value = (valor.keywords ?? []).join(', ')
        },
        { immediate: true },
    )

    const enviar = (): void => {
        guardar({
            response_type: tipo.value,
            response_text: tipo.value === 'text' ? texto.value : null,
            template_id: tipo.value === 'template' ? plantillaId.value : null,
            quick_reply_menu_id: tipo.value === 'menu' ? menuId.value : null,
            trigger_mode: disparo.value,
            skip_if_assigned: saltarAsignados.value,
            cooldown_minutes: espera.value,
            keywords: claves.value,
            daily_limit: tope.value,
        })
    }

    const largo = computed(() => texto.value.length)

    const etiquetaEstado = (estado: string): string =>
        estado === 'sent' ? 'Enviado' : estado === 'failed' ? 'Falló' : 'Omitido'

    const claseEstado = (estado: string): string =>
        estado === 'sent'
            ? 'text-green-700 bg-green-50 border-green-200'
            : estado === 'failed'
                ? 'text-red-700 bg-red-50 border-red-200'
                : 'text-primary/50 bg-surface border-primary/10'

    const cuando = (iso: string | null): string => {
        if (!iso) return ''

        return new Date(iso).toLocaleString('es-VE', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        })
    }
</script>

<template>
    <section class="w-full max-w-3xl flex flex-col gap-3">

        <div>
            <h2 class="font-primary text-xl text-primary">Respuesta a quien escribe</h2>
            <p class="text-xs text-primary/50 mt-0.5">
                Cuando alguien manda un mensaje al buzón, se le responde al instante
                sin esperar a que haya un agente disponible.
            </p>
        </div>

        <!-- La condición es `settings === null` y NO `cargando`: guardar llama
             a cargar(), y con v-if="cargando" la sección se desmontaría en cada
             guardado — el formulario desaparecería y volvería. -->
        <p v-if="settings === null && cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

        <template v-else-if="settings">

            <div
                v-if="roto"
                class="px-4 py-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2.5"
            >
                <Info class="text-red-500 shrink-0 mt-0.5" />
                <p class="text-xs text-red-900 leading-relaxed">
                    <span class="font-bold">Está activa pero no puede responder.</span>
                    La plantilla o el menú elegidos se borraron o se desactivaron: los
                    mensajes se registran como omitidos y nadie recibe nada.
                </p>
            </div>

            <!-- Las reglas de la casa. Van en la UI y no solo en el código
                 porque responden a la pregunta que el negocio hace siempre:
                 "¿por qué a éste no le respondió?" -->
            <div class="px-4 py-3 rounded-xl bg-surface/60 border border-primary/10 flex items-start gap-2.5">
                <Info class="text-secondary shrink-0 mt-0.5" />
                <div class="text-[11px] text-primary/60 leading-relaxed">
                    <p class="font-bold text-primary/75">Cuándo NO responde</p>
                    <p>
                        Si un agente ya tomó el caso, si a esa persona se le respondió
                        hace poco, o si el mensaje es una foto o un audio sin texto.
                        Son los cortes que evitan que el robot pise una conversación
                        que ya está atendiendo alguien.
                    </p>
                </div>
            </div>

            <div class="glass-card p-5 flex flex-col gap-5">

                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-primary">Respuesta automática</p>
                        <p class="text-[11px] text-primary/50 leading-relaxed mt-0.5">
                            Sale en el momento en que llega el mensaje, a cualquier hora.
                        </p>
                    </div>

                    <button
                        @click="alternar()"
                        :disabled="guardando"
                        class="text-xs font-bold uppercase tracking-widest px-4 py-2 rounded-xl border-2 transition-all cursor-pointer shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="settings.is_active
                            ? 'border-green-500 bg-green-500 text-white hover:brightness-105'
                            : 'border-secondary/40 text-primary/60 hover:border-primary hover:bg-primary hover:text-white'"
                    >
                        {{ settings.is_active ? 'Activo' : 'Inactivo' }}
                    </button>
                </div>

                <!-- A quién se le responde -->
                <div class="flex flex-col gap-2">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        A quién responder
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="modo in (['first', 'always'] as DmTriggerMode[])"
                            :key="modo"
                            @click="disparo = modo"
                            class="text-xs font-semibold px-3.5 py-2 rounded-xl border transition-all cursor-pointer"
                            :class="disparo === modo
                                ? 'border-secondary bg-secondary/10 text-primary'
                                : 'border-primary/12 text-primary/50 hover:border-primary/30'"
                        >
                            {{ modo === 'first' ? 'Solo el primer mensaje' : 'Todos los mensajes' }}
                        </button>
                    </div>

                    <p class="text-[11px] text-primary/45 leading-relaxed">
                        <template v-if="disparo === 'first'">
                            Se responde a quien escribe por primera vez. Quien ya venía
                            conversando no recibe nada: es lo que evita que el robot
                            interrumpa.
                        </template>
                        <template v-else>
                            Se responde a cada mensaje entrante. Útil para un menú de
                            autoservicio, pero puede resultar repetitivo.
                        </template>
                    </p>
                </div>

                <!-- Qué se envía -->
                <div class="flex flex-col gap-2">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Qué se envía
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="t in (['menu', 'text', 'template'] as DmResponseType[])"
                            :key="t"
                            @click="tipo = t"
                            class="text-xs font-semibold px-3.5 py-2 rounded-xl border transition-all cursor-pointer"
                            :class="tipo === t
                                ? 'border-secondary bg-secondary/10 text-primary'
                                : 'border-primary/12 text-primary/50 hover:border-primary/30'"
                        >
                            {{ etiquetaTipo(t) }}
                        </button>
                    </div>
                </div>

                <!-- Menú de opciones -->
                <div v-if="tipo === 'menu'" class="flex flex-col gap-1.5">
                    <select
                        v-model="menuId"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                    >
                        <option :value="null">Elegí un menú...</option>
                        <option v-for="m in menusDisponibles" :key="m.id" :value="m.id">
                            {{ m.name }}
                        </option>
                    </select>

                    <p v-if="menusDisponibles.length === 0" class="text-[11px] text-amber-700">
                        No hay menús completos. Creá uno en la sección de menús, con al
                        menos una opción activa.
                    </p>
                    <p v-else class="text-[11px] text-primary/45 leading-relaxed">
                        La persona recibe el texto del menú con sus botones. Al tocar uno,
                        recibe esa respuesta al instante.
                    </p>
                </div>

                <!-- Texto fijo -->
                <div v-else-if="tipo === 'text'" class="flex flex-col gap-1.5">
                    <textarea
                        v-model="texto"
                        rows="3"
                        maxlength="900"
                        placeholder="¡Hola! Gracias por escribirnos 🌿 ¿Qué te gustaría saber?"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors resize-none"
                    />
                    <p class="text-[10px] text-primary/40 text-right">{{ largo }}/900</p>
                </div>

                <!-- Plantilla -->
                <div v-else class="flex flex-col gap-1.5">
                    <select
                        v-model="plantillaId"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                    >
                        <option :value="null">Elegí una plantilla...</option>
                        <option v-for="p in plantillasDisponibles" :key="p.id" :value="p.id">
                            {{ p.name }}
                        </option>
                    </select>
                </div>

                <!-- Palabras clave -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Solo si el mensaje contiene
                    </label>
                    <input
                        v-model="clavesTexto"
                        type="text"
                        placeholder="precio, curso, info"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                    >
                    <p class="text-[11px] text-primary/45 leading-relaxed">
                        Separadas por comas. No distingue mayúsculas ni acentos.
                        <span class="font-semibold">Vacío = responde a todos.</span>
                    </p>
                </div>

                <!-- Los frenos -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Esperar antes de repetir
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model.number="espera"
                                type="number"
                                min="0"
                                max="1440"
                                class="w-24 text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                            >
                            <span class="text-xs text-primary/50">minutos</span>
                        </div>
                        <p class="text-[11px] text-primary/40">
                            Evita cinco respuestas a quien manda cinco mensajes seguidos.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                            Tope por día
                        </label>
                        <input
                            v-model.number="tope"
                            type="number"
                            min="0"
                            max="5000"
                            class="w-24 text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                        >
                        <p class="text-[11px] text-primary/40">0 = sin tope.</p>
                    </div>
                </div>

                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input
                        v-model="saltarAsignados"
                        type="checkbox"
                        class="mt-0.5 w-4 h-4 rounded border-primary/25 text-secondary focus:ring-secondary/40 cursor-pointer"
                    >
                    <span class="text-xs text-primary/70 leading-relaxed">
                        No responder si un agente ya tomó el caso
                        <span class="block text-[11px] text-primary/40">
                            Recomendado: evita que el mensaje automático caiga en medio de
                            una conversación que ya está atendiendo una persona.
                        </span>
                    </span>
                </label>

                <!-- Guardar -->
                <div class="flex items-center gap-3 pt-1">
                    <button
                        @click="enviar"
                        :disabled="guardando || !hayCambios"
                        class="btn-primary text-sm py-2.5 px-6 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ guardando ? 'Guardando...' : 'Guardar' }}
                    </button>

                    <p v-if="guardado" class="text-xs text-green-700">Guardado.</p>
                    <p v-else-if="error" class="text-xs text-red-600">{{ error }}</p>
                </div>
            </div>

            <!-- Métricas -->
            <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div class="glass-card px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-widest text-primary/40">Hoy</p>
                    <p class="text-lg font-semibold text-primary">{{ stats.sent_today }}</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-widest text-primary/40">Total</p>
                    <p class="text-lg font-semibold text-primary">{{ stats.sent_total }}</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-widest text-primary/40">Omitidos hoy</p>
                    <p class="text-lg font-semibold text-primary/60">{{ stats.skipped_today }}</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-widest text-primary/40">Fallidos hoy</p>
                    <p
                        class="text-lg font-semibold"
                        :class="stats.failed_today > 0 ? 'text-red-600' : 'text-primary/60'"
                    >{{ stats.failed_today }}</p>
                </div>
            </div>

            <!-- Últimos intentos.

                 Se muestran también los omitidos: la pregunta que trae a
                 alguien acá suele ser "¿por qué NO respondió?", y el motivo
                 está justo en esa columna. -->
            <div v-if="recent.length > 0" class="flex flex-col gap-1.5">
                <p class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                    Últimos mensajes
                </p>

                <div
                    v-for="r in recent"
                    :key="r.id"
                    class="flex items-start gap-3 px-3 py-2 rounded-xl bg-surface/60 border border-primary/8"
                >
                    <span
                        class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full border shrink-0"
                        :class="claseEstado(r.status)"
                    >{{ etiquetaEstado(r.status) }}</span>

                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-primary truncate">
                            <span class="font-semibold">{{ r.username ?? 'Sin nombre' }}</span>
                            <span v-if="r.message" class="text-primary/50"> — {{ r.message }}</span>
                        </p>
                        <p v-if="r.reason" class="text-[11px] text-primary/40">{{ r.reason }}</p>
                    </div>

                    <span class="text-[10px] text-primary/35 shrink-0">{{ cuando(r.created_at) }}</span>
                </div>
            </div>
        </template>
    </section>
</template>
