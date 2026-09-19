<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue'
    import Info from '../../icons/icon.info.vue'
    import { useInstagramCommentDm, type CommentResponseType } from '@/hooks/useInstagramCommentDm'
    import { useInstagramQuickReplyMenus } from '@/hooks/useInstagramQuickReplyMenus'

    /*
     * Respuesta automática a quien comenta un post: el comentario público
     * debajo del suyo y el DM privado. Son dos interruptores independientes
     * porque responden a necesidades distintas -- el público avisa (y lo ve
     * todo el mundo), el privado vende.
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
        alternarAviso,
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
    const menuId = ref<number | null>(null)
    const tope = ref(100)
    const avisoTexto = ref('')

    /*
     * Los menús se cargan acá y no llegan por prop como las plantillas: la
     * pantalla que monta este componente no los pide (los pide la sección de
     * menús, que es hermana y no padre), y este selector los necesita para
     * ofrecer algo.
     */
    const { menus, cargar: cargarMenus } = useInstagramQuickReplyMenus()

    onMounted(cargarMenus)

    /*
     * Solo los menús que se pueden enviar: activos y con alguna opción activa.
     * Ofrecer uno vacío dejaría elegir algo que llegaría al cliente sin botones
     * — y como Instagram permite UN solo DM por comentario, sin arreglo posible
     * para esa persona.
     */
    const menusDisponibles = computed(() => menus.value.filter((m) => m.is_complete))

    /*
     * Las palabras clave se editan como una sola línea separada por comas: es
     * como las dicta el negocio ("precio, curso, info") y una lista de inputs
     * para esto sería más UI de la que el problema pide.
     */
    const clavesTexto = ref('')

    /** Ya se copió el estado del servidor al borrador al menos una vez. */
    const iniciado = ref(false)

    /*
     * Solo las activas: una plantilla desactivada no responde nada.
     *
     * El Array.isArray NO sobra: este computed solo se evalua cuando el
     * template pinta el bloque de «Plantilla», asi que un prop mal formado no
     * explotaba al montar sino al cambiar de tipo -- y como el error sube por
     * el render, se llevaba por delante la seccion entera y parecia que el
     * modal "se rompia". Con esto el selector queda vacio en vez de tumbar
     * la vista.
     */
    const plantillasDisponibles = computed(
        () => (Array.isArray(props.plantillas) ? props.plantillas : []).filter((t) => t.is_active),
    )

    const etiquetaTipo = (t: CommentResponseType): string => {
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
            tope.value !== settings.value.daily_limit ||
            avisoTexto.value !== (settings.value.public_reply_text ?? '') ||
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

            /*
             * Solo se pisa el borrador la primera vez y cuando no hay nada sin
             * guardar.
             *
             * guardar() llama a cargar(), que reemplaza el objeto `settings`
             * entero. Sin esta guarda, cualquier recarga devolvía los campos al
             * valor del servidor y se perdía lo que el usuario acababa de
             * elegir.
             */
            if (iniciado.value && hayCambios.value) return

            iniciado.value = true

            tipo.value = valor.response_type
            texto.value = valor.response_text ?? ''
            plantillaId.value = valor.template_id
            menuId.value = valor.quick_reply_menu_id ?? null
            tope.value = valor.daily_limit
            avisoTexto.value = valor.public_reply_text ?? ''
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
            public_reply_text: avisoTexto.value,
            keywords: claves.value,
            daily_limit: tope.value,
        })
    }

    /** Cuántos caracteres van; Instagram recorta los mensajes muy largos. */
    const largo = computed(() => texto.value.length)

    const largoAviso = computed(() => avisoTexto.value.length)

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
            <h2 class="font-primary text-xl text-primary">Respuesta a quien comenta</h2>
            <p class="text-xs text-primary/50 mt-0.5">
                Cuando alguien comenta una publicación, se le responde debajo del
                comentario y se le escribe por privado.
            </p>
        </div>

        <!-- La condición es `settings === null` y NO `cargando`: guardar llama
             a cargar(), que pone cargando en true por unos milisegundos. Con
             v-if="cargando" la sección entera se desmontaba en cada guardado y
             en cada recarga —el formulario desaparecía y volvía— que es
             exactamente el síntoma de "cambio de tipo y se va todo".

             Con settings ya cargado el formulario se queda en pantalla y solo
             los botones se deshabilitan con `guardando`. -->
        <p v-if="settings === null && cargando" class="text-sm text-primary/40 py-4">Cargando...</p>

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
                        Un solo mensaje privado por comentario, dentro de los 7 días
                        siguientes. No se puede insistir después: si la persona responde,
                        ahí se abre la conversación normal y la atiende un agente.
                    </p>
                    <p class="mt-1">
                        Solo se responde a comentarios del post. A las respuestas dentro
                        de un hilo no, porque Instagram las colgaría del comentario
                        original y se vería duplicado.
                    </p>
                </div>
            </div>

            <!-- PASO 1: el comentario público.

                 Va primero porque es lo que pasa primero y lo que la persona ve
                 sin salir del post. Sin él, el DM cae en Solicitudes -- una
                 carpeta que casi nadie mira -- y nadie se entera de nada. -->
            <div class="glass-card p-5 flex flex-col gap-4">

                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-primary">1. Respuesta pública</p>
                        <p class="text-[11px] text-primary/50 leading-relaxed mt-0.5">
                            Se publica debajo del comentario, a la vista de todos. Sirve para
                            avisarle que le escribiste al privado.
                        </p>
                    </div>

                    <!-- Interruptor propio: el aviso público y el privado se
                         activan por separado. Guarda solo su campo, para no
                         arrastrar el formulario a medio escribir. -->
                    <button
                        @click="alternarAviso()"
                        :disabled="guardando"
                        class="text-xs font-bold uppercase tracking-widest px-4 py-2 rounded-xl border-2 transition-all cursor-pointer shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="settings.public_reply_active
                            ? 'border-green-500 bg-green-500 text-white hover:brightness-105'
                            : 'border-secondary/40 text-primary/60 hover:border-primary hover:bg-primary hover:text-white'"
                    >
                        {{ settings.public_reply_active ? 'Activo' : 'Inactivo' }}
                    </button>
                </div>

                <div class="flex flex-col gap-1.5">
                    <input
                        v-model="avisoTexto"
                        type="text"
                        maxlength="280"
                        placeholder="¡Gracias por comentar! Te escribimos por privado 💌"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors"
                    >
                    <p class="text-[10px] text-primary/40 text-right">{{ largoAviso }}/280</p>
                </div>
            </div>

            <!-- PASO 2: el DM privado. -->
            <div class="glass-card p-5 flex flex-col gap-5">

                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-primary">2. Mensaje privado</p>
                        <p class="text-[11px] text-primary/50 leading-relaxed mt-0.5">
                            Llega a su bandeja. Es el mensaje que vende: corto, con lo esencial
                            y una pregunta al final.
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

                <!-- Qué se envía -->
                <div class="flex flex-col gap-2">
                    <label class="text-[11px] font-bold uppercase tracking-widest text-primary/50">
                        Qué se envía
                    </label>

                    <div class="flex gap-2">
                        <button
                            v-for="opcion in (['text', 'template', 'menu'] as CommentResponseType[])"
                            :key="opcion"
                            @click="tipo = opcion"
                            class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-all cursor-pointer"
                            :class="tipo === opcion
                                ? 'bg-primary text-white border-primary'
                                : 'bg-surface text-primary/60 border-primary/15 hover:border-primary/40'"
                        >
                            {{ etiquetaTipo(opcion) }}
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

                <div v-else-if="tipo === 'menu'" class="flex flex-col gap-1.5">
                    <!-- Menú de opciones: el DM llega con botones que el
                         cliente toca, y cada uno responde por su cuenta.

                         El comentario va DENTRO del bloque y no entre este y
                         el anterior: Vue corta la cadena v-if/v-else-if con
                         cualquier nodo en medio, y un comentario HTML cuenta
                         como nodo. Puesto afuera, el v-else-if y el v-else
                         quedaban huérfanos y al cambiar de tipo no se pintaba
                         ninguno de los tres. -->
                    <select
                        v-model="menuId"
                        class="w-full text-sm text-primary bg-surface border border-primary/12 rounded-xl px-3 py-2.5 focus:outline-none focus:border-secondary/50 transition-colors cursor-pointer"
                    >
                        <option :value="null">Elige un menú...</option>
                        <option v-for="m in menusDisponibles" :key="m.id" :value="m.id">
                            {{ m.name }} ({{ m.options.filter((o) => o.is_active).length }} opciones)
                        </option>
                    </select>

                    <p v-if="menusDisponibles.length === 0" class="text-[10px] text-amber-700">
                        No hay menús listos. Créalos más abajo, en «Menús de opciones».
                    </p>

                    <p v-else class="text-[10px] text-primary/40 leading-relaxed">
                        El cliente recibe el mensaje con sus botones y elige. Cada opción
                        responde sola, sin esperar a un agente.
                    </p>
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
                            <!-- El aviso público es un envío aparte: pudo salir
                                 aunque el privado no, y al revés. -->
                            <p v-if="r.replied" class="text-[10px] text-green-700 mt-0.5">
                                Respondido en el post
                            </p>
                            <p v-else-if="r.reply_error" class="text-[10px] text-red-600 mt-0.5">
                                No se pudo comentar: {{ r.reply_error }}
                            </p>
                        </div>

                        <span class="text-[10px] text-primary/35 shrink-0">{{ cuando(r.created_at) }}</span>
                    </div>
                </div>
            </details>
        </template>
    </section>
</template>
