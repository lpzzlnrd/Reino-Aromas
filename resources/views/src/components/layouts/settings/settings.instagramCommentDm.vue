<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue'
    import Info from '../../icons/icon.info.vue'
    import { useInstagramCommentDm, type CommentResponseType } from '@/hooks/useInstagramCommentDm'

    /*
     * DM de bienvenida a quien comenta un post.
     *
     * Componente aparte y no otra pantalla: es la misma idea que los botones
     * ("Instagram responde solo") y separarlo obligaría al negocio a recordar
     * en cuál de dos pantallas está cada automatización. Va como sección al
     * final de Automatizar IG.
     *
     * Las plantillas llegan por prop porque la vista padre ya las pidió: dos
     * componentes hermanos pidiendo /templates al montar sería una llamada de
     * más para la misma lista.
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
    } = useInstagramCommentDm()

    onMounted(cargar)

    /*
     * Borrador local del formulario.
     *
     * No se edita `settings` directo: si se hiciera, el interruptor de encendido
     * (que guarda solo su propio campo) arrastraría los cambios a medio escribir
     * del texto. El borrador se sincroniza cuando llegan datos del servidor.
     */
    const tipo = ref<CommentResponseType>('text')
    const texto = ref('')
    const plantillaId = ref<number | null>(null)
    const tope = ref(100)

    /*
     * Las palabras clave se editan como una sola línea separada por comas: es
     * como las dicta el negocio ("precio, curso, info") y una lista de inputs
     * para esto sería más UI de la que el problema pide.
     */
    const clavesTexto = ref('')

    watch(
        settings,
        (valor) => {
            if (valor === null) return

            tipo.value = valor.response_type
            texto.value = valor.response_text ?? ''
            plantillaId.value = valor.template_id
            tope.value = valor.daily_limit
            clavesTexto.value = (valor.keywords ?? []).join(', ')
        },
        { immediate: true },
    )

    /** Solo las activas: una plantilla desactivada no responde nada. */
    const plantillasDisponibles = computed(() => props.plantillas.filter((t) => t.is_active))

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
            tope.value !== settings.value.daily_limit ||
            claves.value.join(',') !== (settings.value.keywords ?? []).join(',')
        )
    })

    const enviar = (): void => {
        guardar({
            response_type: tipo.value,
            response_text: tipo.value === 'text' ? texto.value : null,
            template_id: tipo.value === 'template' ? plantillaId.value : null,
            keywords: claves.value,
            daily_limit: tope.value,
        })
    }

    /** Cuántos caracteres van; Instagram recorta los mensajes muy largos. */
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

        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="font-primary text-xl text-primary">Mensaje a quien comenta</h2>
                <p class="text-xs text-primary/50 mt-0.5">
                    Cuando alguien comenta una publicación, recibe un privado de bienvenida.
                </p>
            </div>

            <!-- El interruptor guarda solo su campo: ver el borrador local. -->
            <button
                v-if="settings"
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

        <p v-if="cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

        <template v-else-if="settings">

            <!-- Una plantilla borrada deja la automatización muda: hay que
                 decirlo acá y no dejar que se descubra por los DM que no salen. -->
            <div
                v-if="roto"
                class="px-4 py-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2.5"
            >
                <Info class="text-red-500 shrink-0 mt-0.5" />
                <p class="text-xs text-red-900 leading-relaxed">
                    <span class="font-bold">Está activo pero no puede responder.</span>
                    La plantilla elegida se borró o se desactivó: los comentarios se
                    registran como omitidos y nadie recibe nada.
                </p>
            </div>

            <!-- Las reglas de Meta. Van en la UI y no solo en el código porque
                 son la respuesta a las dos preguntas que el negocio hace
                 siempre: "¿por qué solo uno?" y "¿por qué dejó de funcionar?" -->
            <div class="px-4 py-3 rounded-xl bg-surface/60 border border-primary/10 flex items-start gap-2.5">
                <Info class="text-secondary shrink-0 mt-0.5" />
                <div class="text-[11px] text-primary/60 leading-relaxed">
                    <p class="font-bold text-primary/75">Lo que permite Instagram</p>
                    <p>
                        Un solo mensaje por comentario, dentro de los 7 días siguientes.
                        No se puede insistir después: si la persona responde, ahí se abre
                        la conversación normal y la atiende un agente.
                    </p>
                </div>
            </div>

            <div class="glass-card p-5 flex flex-col gap-5">

                <!-- Qué se envía -->
                <div class="flex flex-col gap-2">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Qué se envía
                    </label>

                    <div class="flex gap-2">
                        <button
                            v-for="opcion in (['text', 'template'] as CommentResponseType[])"
                            :key="opcion"
                            @click="tipo = opcion"
                            class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-all cursor-pointer"
                            :class="tipo === opcion
                                ? 'bg-primary text-white border-primary'
                                : 'bg-surface text-primary/60 border-primary/15 hover:border-primary/40'"
                        >
                            {{ opcion === 'text' ? 'Texto fijo' : 'Plantilla' }}
                        </button>
                    </div>
                </div>

                <div v-if="tipo === 'text'" class="flex flex-col gap-1.5">
                    <textarea
                        v-model="texto"
                        rows="4"
                        maxlength="900"
                        placeholder="¡Hola! Gracias por comentar 🌿 ¿Te cuento sobre nuestros cursos?"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors resize-y"
                    />
                    <p class="text-[10px] text-primary/40 text-right">{{ largo }}/900</p>
                </div>

                <div v-else class="flex flex-col gap-1.5">
                    <select
                        v-model="plantillaId"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                    >
                        <option :value="null">Elige una plantilla...</option>
                        <option v-for="t in plantillasDisponibles" :key="t.id" :value="t.id">
                            {{ t.name }}{{ t.city ? ` — ${t.city}` : '' }}
                        </option>
                    </select>
                    <p v-if="plantillasDisponibles.length === 0" class="text-[10px] text-amber-700">
                        No hay plantillas activas. Créalas en Ajustes → Plantillas.
                    </p>
                </div>

                <!-- Palabras clave -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Solo si el comentario dice...
                    </label>
                    <input
                        v-model="clavesTexto"
                        type="text"
                        placeholder="precio, info, curso"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                    >
                    <p class="text-[10px] text-primary/45 leading-relaxed">
                        Separadas por comas. No importan mayúsculas ni acentos.
                        <span class="font-semibold">Déjalo vacío para responder a todos los comentarios.</span>
                    </p>
                </div>

                <!-- Tope diario -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Tope de mensajes por día
                    </label>
                    <input
                        v-model.number="tope"
                        type="number"
                        min="0"
                        max="5000"
                        class="w-32 text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                    >
                    <p class="text-[10px] text-primary/45 leading-relaxed">
                        Una publicación que se mueve puede traer cientos de comentarios.
                        <span class="font-semibold">0 = sin tope.</span>
                    </p>
                </div>

                <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

                <div class="flex items-center gap-3">
                    <button
                        @click="enviar()"
                        :disabled="guardando || !hayCambios"
                        class="btn-primary text-xs py-2 px-5 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        {{ guardando ? 'Guardando...' : 'Guardar' }}
                    </button>

                    <span v-if="guardado" class="text-xs font-semibold text-green-700">Guardado</span>
                    <span v-else-if="hayCambios" class="text-xs text-primary/40">Hay cambios sin guardar</span>
                </div>
            </div>

            <!-- Métricas. Sin esto no hay forma de saber si funciona sin
                 pedirle al dev que mire los logs del servidor. -->
            <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div class="glass-card px-3 py-2.5">
                    <p class="text-lg font-primary text-primary">{{ stats.sent_today }}</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-primary/40">Hoy</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-lg font-primary text-primary">{{ stats.sent_total }}</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-primary/40">Total</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-lg font-primary text-primary/60">{{ stats.skipped_today }}</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-primary/40">Omitidos hoy</p>
                </div>
                <div class="glass-card px-3 py-2.5">
                    <p class="text-lg font-primary" :class="stats.failed_today > 0 ? 'text-red-600' : 'text-primary/60'">
                        {{ stats.failed_today }}
                    </p>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-primary/40">Fallidos hoy</p>
                </div>
            </div>

            <!-- Últimos intentos. Incluye los omitidos a propósito: la pregunta
                 que trae a alguien acá es "¿por qué NO se envió?", y el motivo
                 está en esta columna. -->
            <details v-if="recent.length > 0" class="glass-card px-4 py-3">
                <summary class="text-xs font-bold uppercase tracking-widest text-primary/50 cursor-pointer">
                    Últimos {{ recent.length }} comentarios
                </summary>

                <div class="mt-3 flex flex-col divide-y divide-primary/5">
                    <div v-for="r in recent" :key="r.id" class="py-2 flex items-start gap-3">
                        <span
                            class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border shrink-0 mt-0.5"
                            :class="claseEstado(r.status)"
                        >
                            {{ etiquetaEstado(r.status) }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-primary truncate">
                                {{ r.username ? '@' + r.username : 'Sin usuario' }}
                            </p>
                            <p v-if="r.comment" class="text-[11px] text-primary/50 line-clamp-2">
                                {{ r.comment }}
                            </p>
                            <p v-if="r.reason" class="text-[11px] text-primary/40 italic mt-0.5">
                                {{ r.reason }}
                            </p>
                        </div>

                        <span class="text-[10px] text-primary/35 shrink-0">{{ cuando(r.created_at) }}</span>
                    </div>
                </div>
            </details>
        </template>
    </section>
</template>
