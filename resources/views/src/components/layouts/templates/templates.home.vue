<script setup lang="ts">
    import { ref, computed, onMounted, watch } from 'vue'
    import Header from '../header/header.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Search from '../../icons/icon.search.vue'
    import Close from '../../icons/icon.close.vue'
    import Alert from '../../icons/icon.alert.vue'
    import api from '@/lib/axios'

    type Ciudad = 'caracas' | 'valencia' | 'barquisimeto' | 'maracay' | 'margarita'
    type Canal  = 'whatsapp' | 'instagram' | 'facebook'

    type Template = {
        id: number
        name: string
        body: string
        city: Ciudad | null
        channel: Canal | null
        category: string | null
        is_active: boolean
        usage_count: number
        last_used_at: string | null
        meta_template_name: string | null
        variables: string[]
        variables_desconocidas: string[]
        // Datos del curso. Los consume el endpoint de WhatsApp Flows para
        // armar la pantalla de información, y son la fuente de verdad del
        // precio — el texto del cuerpo es solo para copiar y pegar.
        price: string | null
        deposit: string | null
        includes: string | null
        visit_frequency: string | null
        schedule: string | null
    }

    type TemplateForm = {
        name: string
        body: string
        city: Ciudad | ''
        channel: Canal | ''
        category: string
        is_active: boolean
        meta_template_name: string
        price: string
        deposit: string
        includes: string
        visit_frequency: string
        schedule: string
    }

    const CIUDADES: { valor: Ciudad; etiqueta: string }[] = [
        { valor: 'caracas',      etiqueta: 'Caracas' },
        { valor: 'valencia',     etiqueta: 'Valencia' },
        { valor: 'barquisimeto', etiqueta: 'Barquisimeto' },
        { valor: 'maracay',      etiqueta: 'Maracay' },
        { valor: 'margarita',    etiqueta: 'Margarita' },
    ]

    const CANALES: { valor: Canal; etiqueta: string }[] = [
        { valor: 'whatsapp',  etiqueta: 'WhatsApp' },
        { valor: 'instagram', etiqueta: 'Instagram' },
        { valor: 'facebook',  etiqueta: 'Facebook' },
    ]

    const templates    = ref<Template[]>([])
    // Catálogo de variables que envía el backend: la vista no tiene su propia
    // copia para que agregar una variable nueva sea tocar solo el servidor.
    const variables    = ref<Record<string, string>>({})
    const loading      = ref(false)
    const searchQuery  = ref('')
    const filtroCiudad = ref<'todas' | Ciudad>('todas')
    const filtroCanal  = ref<'todos' | Canal>('todos')

    const showModal    = ref(false)
    const editando     = ref<Template | null>(null)
    const formErrors   = ref<Record<string, string>>({})
    const saving       = ref(false)
    const confirmandoBorrado = ref<Template | null>(null)

    const preview      = ref('')
    const previewVarsDesconocidas = ref<string[]>([])
    const bodyRef      = ref<HTMLTextAreaElement | null>(null)

    const formVacio = (): TemplateForm => ({
        name: '',
        body: '',
        city: '',
        channel: '',
        category: '',
        is_active: true,
        meta_template_name: '',
        price: '',
        deposit: '',
        includes: '',
        visit_frequency: '',
        schedule: '',
    })

    const form = ref<TemplateForm>(formVacio())

    /* ---------------------------------------------------------------------
     * Filtrado en el cliente.
     * La lista completa son decenas de registros, no miles: traerla entera y
     * filtrar en memoria evita un round-trip por cada tecla del buscador.
     * ------------------------------------------------------------------ */
    const templatesFiltradas = computed(() => {
        const q = searchQuery.value.toLowerCase().trim()

        return templates.value.filter(t => {
            const coincideTexto = q === ''
                || t.name.toLowerCase().includes(q)
                || t.body.toLowerCase().includes(q)

            const coincideCiudad = filtroCiudad.value === 'todas' || t.city === filtroCiudad.value
            const coincideCanal  = filtroCanal.value === 'todos'  || t.channel === filtroCanal.value

            return coincideTexto && coincideCiudad && coincideCanal
        })
    })

    const hayFiltrosActivos = computed(() =>
        searchQuery.value !== '' || filtroCiudad.value !== 'todas' || filtroCanal.value !== 'todos'
    )

    const limpiarFiltros = () => {
        searchQuery.value  = ''
        filtroCiudad.value = 'todas'
        filtroCanal.value  = 'todos'
    }

    const fetchTemplates = async () => {
        loading.value = true
        try {
            const res = await api.get('/templates')
            templates.value = res.data.templates
            variables.value = res.data.variables
        } catch {
            templates.value = []
        } finally {
            loading.value = false
        }
    }

    /* ---------------------------------------------------------------------
     * Vista previa en vivo.
     * Se pide al backend para que la sustitución use exactamente la misma
     * lógica que al enviar el mensaje — duplicarla en JS garantizaría que
     * ambas se desincronicen con el tiempo.
     * ------------------------------------------------------------------ */
    let previewTimer: ReturnType<typeof setTimeout> | undefined

    const pedirPreview = async () => {
        if (!form.value.body.trim()) {
            preview.value = ''
            previewVarsDesconocidas.value = []
            return
        }
        try {
            const res = await api.post('/templates/preview', { body: form.value.body })
            preview.value = res.data.rendered
            previewVarsDesconocidas.value = res.data.variables_desconocidas
        } catch {
            preview.value = ''
        }
    }

    // Debounce: sin esto cada tecla dispara un POST.
    watch(() => form.value.body, () => {
        clearTimeout(previewTimer)
        previewTimer = setTimeout(pedirPreview, 350)
    })

    /**
     * Construye el marcador de una variable.
     *
     * Se arma concatenando en JS y no escribiéndolo literal en el template:
     * las dobles llaves son la sintaxis de interpolación de Vue y el
     * compilador las intenta evaluar.
     */
    const marcador = (nombre: string) => '{' + '{' + nombre + '}' + '}'

    /**
     * Inserta el marcador de una variable en la posición del cursor.
     * Escribir las llaves a mano es donde se producen los typos que dejan
     * marcadores crudos en el mensaje al cliente.
     */
    const insertarVariable = (nombre: string) => {
        const el = bodyRef.value
        const texto = marcador(nombre)

        if (!el) {
            form.value.body += texto
            return
        }

        const inicio = el.selectionStart ?? form.value.body.length
        const fin    = el.selectionEnd ?? inicio

        form.value.body = form.value.body.slice(0, inicio) + texto + form.value.body.slice(fin)

        // Devolver el foco y dejar el cursor después del marcador insertado.
        requestAnimationFrame(() => {
            el.focus()
            const pos = inicio + texto.length
            el.setSelectionRange(pos, pos)
        })
    }

    const abrirCrear = () => {
        editando.value  = null
        form.value      = formVacio()
        formErrors.value = {}
        preview.value   = ''
        previewVarsDesconocidas.value = []
        showModal.value = true
    }

    const abrirEditar = (t: Template) => {
        editando.value = t
        form.value = {
            name: t.name,
            body: t.body,
            city: t.city ?? '',
            channel: t.channel ?? '',
            category: t.category ?? '',
            is_active: t.is_active,
            meta_template_name: t.meta_template_name ?? '',
            price: t.price ?? '',
            deposit: t.deposit ?? '',
            includes: t.includes ?? '',
            visit_frequency: t.visit_frequency ?? '',
            schedule: t.schedule ?? '',
        }
        formErrors.value = {}
        showModal.value = true
        pedirPreview()
    }

    const cerrarModal = () => {
        showModal.value = false
        editando.value  = null
        formErrors.value = {}
    }

    const guardar = async () => {
        saving.value = true
        formErrors.value = {}

        // El backend espera null (no cadena vacía) para "aplica a todas".
        const payload = {
            ...form.value,
            city:    form.value.city    || null,
            channel: form.value.channel || null,
            category: form.value.category || null,
            meta_template_name: form.value.meta_template_name || null,
            price: form.value.price !== '' ? form.value.price : null,
            deposit: form.value.deposit !== '' ? form.value.deposit : null,
            includes: form.value.includes || null,
            visit_frequency: form.value.visit_frequency || null,
            schedule: form.value.schedule || null,
        }

        try {
            if (editando.value) {
                await api.put(`/templates/${editando.value.id}`, payload)
            } else {
                await api.post('/templates', payload)
            }
            await fetchTemplates()
            cerrarModal()
        } catch (err: any) {
            if (err.response?.status === 422) {
                const errores = err.response.data.errors as Record<string, string[]>
                formErrors.value = Object.fromEntries(
                    Object.entries(errores).map(([k, v]) => [k, v[0] ?? 'Campo inválido'])
                )
            }
        } finally {
            saving.value = false
        }
    }

    const toggleActive = async (t: Template) => {
        try {
            const res = await api.patch(`/templates/${t.id}/toggle-active`)
            t.is_active = res.data.is_active
        } catch {}
    }

    const borrar = async () => {
        if (!confirmandoBorrado.value) return
        try {
            await api.delete(`/templates/${confirmandoBorrado.value.id}`)
            templates.value = templates.value.filter(t => t.id !== confirmandoBorrado.value!.id)
        } catch {
        } finally {
            confirmandoBorrado.value = null
        }
    }

    const etiquetaCiudad = (c: Ciudad | null) =>
        c === null ? 'Todas' : (CIUDADES.find(x => x.valor === c)?.etiqueta ?? c)

    const etiquetaCanal = (c: Canal | null) =>
        c === null ? 'Todos' : (CANALES.find(x => x.valor === c)?.etiqueta ?? c)

    // Primeras líneas del cuerpo, para la vista de tarjeta.
    const resumen = (body: string, max = 110) => {
        const plano = body.replace(/\n+/g, ' ').trim()
        return plano.length > max ? plano.slice(0, max) + '…' : plano
    }

    onMounted(fetchTemplates)
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <Header class="mb-8" />

        <!-- Título + acción -->
        <section class="flex items-center justify-between mb-6 px-1 gap-4">
            <div>
                <h1 class="text-3xl font-primary text-primary">Plantillas</h1>
                <p class="text-sm text-primary/50 mt-0.5">Respuestas rápidas para WhatsApp, Instagram y Facebook</p>
            </div>
            <button @click="abrirCrear" class="btn-primary shadow-md shadow-secondary/20 text-sm py-2.5 px-5 group shrink-0">
                <Plus class="group-hover:rotate-90 transition-transform duration-200 shrink-0" />
                <span class="hidden sm:inline">Nueva plantilla</span>
                <span class="sm:hidden">Nueva</span>
            </button>
        </section>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <label class="input-group flex-1 min-w-[200px] max-w-sm group cursor-text">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre o contenido..."
                    class="bg-transparent text-sm focus:outline-none w-full placeholder:text-primary/30"
                >
            </label>

            <select
                v-model="filtroCiudad"
                class="px-3 py-2.5 rounded-xl border border-primary/12 text-sm text-primary bg-white focus:outline-none focus:border-secondary/50 transition-colors"
            >
                <option value="todas">Todas las ciudades</option>
                <option v-for="c in CIUDADES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
            </select>

            <select
                v-model="filtroCanal"
                class="px-3 py-2.5 rounded-xl border border-primary/12 text-sm text-primary bg-white focus:outline-none focus:border-secondary/50 transition-colors"
            >
                <option value="todos">Todos los canales</option>
                <option v-for="c in CANALES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
            </select>

            <button
                v-if="hayFiltrosActivos"
                @click="limpiarFiltros"
                class="text-[11px] font-bold uppercase tracking-widest px-3 py-2 rounded-lg text-primary/50 hover:text-primary hover:bg-primary/6 transition-colors"
            >
                Limpiar
            </button>
        </div>

        <!-- Listado -->
        <div v-if="loading" class="glass-card px-5 py-16 text-center text-sm text-primary/40">
            Cargando plantillas...
        </div>

        <div v-else-if="templatesFiltradas.length === 0" class="glass-card px-5 py-16 text-center">
            <p class="text-sm text-primary/40">
                {{ hayFiltrosActivos ? 'Ninguna plantilla coincide con los filtros.' : 'Todavía no hay plantillas.' }}
            </p>
            <button
                v-if="hayFiltrosActivos"
                @click="limpiarFiltros"
                class="mt-3 text-[11px] font-bold uppercase tracking-widest text-primary/60 hover:text-primary transition-colors"
            >
                Quitar filtros
            </button>
            <button
                v-else
                @click="abrirCrear"
                class="mt-3 text-[11px] font-bold uppercase tracking-widest text-primary/60 hover:text-primary transition-colors"
            >
                Crear la primera
            </button>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="t in templatesFiltradas"
                :key="t.id"
                class="glass-card p-4 flex flex-col gap-3 transition-all hover:shadow-md"
                :class="{ 'opacity-55': !t.is_active }"
            >
                <!-- Cabecera de la tarjeta -->
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-primary leading-tight truncate">{{ t.name }}</h3>
                        <p v-if="t.category" class="text-[10px] text-primary/40 uppercase tracking-widest mt-0.5">
                            {{ t.category }}
                        </p>
                    </div>
                    <button
                        @click="toggleActive(t)"
                        :class="[
                            'relative inline-flex h-5 w-9 items-center rounded-full transition-colors shrink-0',
                            t.is_active ? 'bg-green-400' : 'bg-primary/20'
                        ]"
                        :title="t.is_active ? 'Desactivar' : 'Activar'"
                    >
                        <span :class="[
                            'inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform',
                            t.is_active ? 'translate-x-[1.125rem]' : 'translate-x-0.5'
                        ]" />
                    </button>
                </div>

                <!-- Cuerpo resumido -->
                <p class="text-xs text-primary/60 leading-relaxed flex-1">{{ resumen(t.body) }}</p>

                <!-- Aviso de variable mal escrita -->
                <div
                    v-if="t.variables_desconocidas.length"
                    class="flex items-start gap-2 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5"
                >
                    <Alert class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                    <span>
                        Variable sin reconocer:
                        <strong>{{ t.variables_desconocidas.join(', ') }}</strong>
                    </span>
                </div>

                <!-- Metadatos -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-primary/6 text-primary/60 border border-primary/10">
                        {{ etiquetaCiudad(t.city) }}
                    </span>
                    <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-secondary/15 text-primary/60 border border-secondary/20">
                        {{ etiquetaCanal(t.channel) }}
                    </span>
                    <!-- El precio distingue las plantillas que alimentan el Flow -->
                    <span
                        v-if="t.price"
                        class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200"
                    >
                        ${{ Number(t.price).toFixed(0) }}
                    </span>
                    <span
                        v-for="v in t.variables"
                        :key="v"
                        class="text-[10px] px-2 py-0.5 rounded-full bg-accent/10 text-primary/50 border border-accent/20 font-mono"
                    >
                        {{ v }}
                    </span>
                </div>

                <!-- Pie -->
                <div class="flex items-center justify-between pt-2 border-t border-primary/8">
                    <span class="text-[10px] text-primary/35">
                        {{ t.usage_count === 0 ? 'Sin usar' : `Usada ${t.usage_count}×` }}
                    </span>
                    <div class="flex gap-1">
                        <button
                            @click="abrirEditar(t)"
                            class="text-[11px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-lg border border-primary/15 text-primary/60 hover:bg-primary hover:text-white hover:border-primary transition-all"
                        >
                            Editar
                        </button>
                        <button
                            @click="confirmandoBorrado = t"
                            class="text-[11px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-lg border border-transparent text-primary/35 hover:text-red-500 hover:border-red-200 hover:bg-red-50 transition-all"
                        >
                            Borrar
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <!-- Modal crear / editar -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-primary/30 backdrop-blur-sm" @click="cerrarModal" />

                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-y-auto max-h-[92vh]">
                        <!-- Header -->
                        <div class="flex items-center justify-between px-6 py-5 border-b border-primary/8 sticky top-0 bg-white z-10">
                            <div>
                                <h2 class="text-lg font-primary text-primary">
                                    {{ editando ? 'Editar plantilla' : 'Nueva plantilla' }}
                                </h2>
                                <p class="text-xs text-primary/40 mt-0.5">
                                    Usa variables para personalizar cada mensaje
                                </p>
                            </div>
                            <button @click="cerrarModal" class="p-2 rounded-xl hover:bg-primary/6 text-primary/40 hover:text-primary transition-colors">
                                <Close />
                            </button>
                        </div>

                        <form @submit.prevent="guardar" class="px-6 py-5 flex flex-col gap-4">
                            <!-- Nombre -->
                            <div class="flex flex-col gap-1">
                                <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                    Nombre de la plantilla
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="Precio Caracas"
                                    class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                    :class="{ 'border-red-300 bg-red-50': formErrors.name }"
                                >
                                <p v-if="formErrors.name" class="text-xs text-red-500">{{ formErrors.name }}</p>
                            </div>

                            <!-- Ciudad + Canal -->
                            <div class="grid grid-cols-2 gap-3">
                                <div class="flex flex-col gap-1">
                                    <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Ciudad</label>
                                    <select
                                        v-model="form.city"
                                        class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary bg-white focus:outline-none focus:border-secondary/50 transition-colors"
                                    >
                                        <option value="">Todas las ciudades</option>
                                        <option v-for="c in CIUDADES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
                                    </select>
                                </div>

                                <div class="flex flex-col gap-1">
                                    <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Canal</label>
                                    <select
                                        v-model="form.channel"
                                        class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary bg-white focus:outline-none focus:border-secondary/50 transition-colors"
                                    >
                                        <option value="">Todos los canales</option>
                                        <option v-for="c in CANALES" :key="c.valor" :value="c.valor">{{ c.etiqueta }}</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Variables disponibles -->
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                    Insertar variable
                                </label>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="(descripcion, nombre) in variables"
                                        :key="nombre"
                                        type="button"
                                        @click="insertarVariable(String(nombre))"
                                        :title="descripcion"
                                        class="text-[11px] font-mono px-2.5 py-1 rounded-lg border border-accent/30 bg-accent/10 text-primary/70 hover:bg-accent/25 hover:border-accent/50 transition-all"
                                    >
                                        {{ marcador(String(nombre)) }}
                                    </button>
                                </div>
                            </div>

                            <!-- Cuerpo -->
                            <div class="flex flex-col gap-1">
                                <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                    Mensaje
                                </label>
                                <textarea
                                    ref="bodyRef"
                                    v-model="form.body"
                                    required
                                    rows="6"
                                    :placeholder="`¡Hola ${marcador('nombre')}! Te comparto nuestros precios en ${marcador('ciudad')}...`"
                                    class="px-4 py-3 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25 resize-y font-mono leading-relaxed"
                                    :class="{ 'border-red-300 bg-red-50': formErrors.body }"
                                />
                                <div class="flex items-center justify-between">
                                    <p v-if="formErrors.body" class="text-xs text-red-500">{{ formErrors.body }}</p>
                                    <span class="text-[10px] text-primary/30 ml-auto">{{ form.body.length }} / 4000</span>
                                </div>
                            </div>

                            <!-- Aviso de variables desconocidas -->
                            <div
                                v-if="previewVarsDesconocidas.length"
                                class="flex items-start gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2"
                            >
                                <Alert class="w-4 h-4 shrink-0 mt-0.5" />
                                <span>
                                    Estas variables no existen y saldrán vacías:
                                    <strong>{{ previewVarsDesconocidas.join(', ') }}</strong>
                                </span>
                            </div>

                            <!-- Vista previa -->
                            <div v-if="preview" class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                    Así lo recibe el cliente
                                </label>
                                <div class="rounded-xl bg-[#DCF8C6]/45 border border-green-600/15 px-4 py-3">
                                    <p class="text-sm text-primary/85 whitespace-pre-wrap leading-relaxed">{{ preview }}</p>
                                </div>
                            </div>

                            <!-- Datos del curso — alimentan el Flow de WhatsApp -->
                            <details class="group" :open="form.city !== ''">
                                <summary class="text-[11px] font-bold text-primary/50 uppercase tracking-widest cursor-pointer hover:text-primary/70 transition-colors list-none flex items-center gap-1.5">
                                    <span class="transition-transform group-open:rotate-90">▸</span>
                                    Datos del curso
                                    <span class="font-normal normal-case tracking-normal text-primary/35">
                                        — los usa el formulario de WhatsApp
                                    </span>
                                </summary>

                                <div class="mt-3 flex flex-col gap-3 pl-4 border-l-2 border-secondary/25">
                                    <p class="text-[11px] text-primary/45 leading-relaxed">
                                        Cuando un cliente nuevo escribe por WhatsApp, el formulario automático
                                        le muestra estos datos según su ciudad. Es la fuente de verdad del
                                        precio: cámbialo aquí y se actualiza en todos lados.
                                    </p>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="flex flex-col gap-1">
                                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                                Precio (USD)
                                            </label>
                                            <input
                                                v-model="form.price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="130"
                                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                                :class="{ 'border-red-300 bg-red-50': formErrors.price }"
                                            >
                                            <p v-if="formErrors.price" class="text-xs text-red-500">{{ formErrors.price }}</p>
                                        </div>

                                        <div class="flex flex-col gap-1">
                                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                                Reserva (USD)
                                            </label>
                                            <input
                                                v-model="form.deposit"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="20"
                                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                                :class="{ 'border-red-300 bg-red-50': formErrors.deposit }"
                                            >
                                            <p v-if="formErrors.deposit" class="text-xs text-red-500">{{ formErrors.deposit }}</p>
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-1">
                                        <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                            Qué incluye
                                        </label>
                                        <input
                                            v-model="form.includes"
                                            type="text"
                                            placeholder="Materiales e insumos, desayuno, refrigerio y café."
                                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                        >
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="flex flex-col gap-1">
                                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                                Frecuencia
                                            </label>
                                            <input
                                                v-model="form.visit_frequency"
                                                type="text"
                                                placeholder="Cada mes"
                                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                            >
                                        </div>

                                        <div class="flex flex-col gap-1">
                                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                                Horario
                                            </label>
                                            <input
                                                v-model="form.schedule"
                                                type="text"
                                                placeholder="10:00 am a 6:00 pm"
                                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                            >
                                        </div>
                                    </div>

                                    <!-- Aviso: sin precio la ciudad no sale en el formulario -->
                                    <div
                                        v-if="form.city !== '' && form.price === '' && form.is_active"
                                        class="flex items-start gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2"
                                    >
                                        <Alert class="w-4 h-4 shrink-0 mt-0.5" />
                                        <span>
                                            Sin precio, <strong>{{ etiquetaCiudad(form.city as Ciudad) }}</strong>
                                            no aparecerá como opción en el formulario de WhatsApp.
                                        </span>
                                    </div>
                                </div>
                            </details>

                            <!-- Avanzado -->
                            <details class="group">
                                <summary class="text-[11px] font-bold text-primary/40 uppercase tracking-widest cursor-pointer hover:text-primary/60 transition-colors list-none flex items-center gap-1.5">
                                    <span class="transition-transform group-open:rotate-90">▸</span>
                                    Opciones avanzadas
                                </summary>

                                <div class="mt-3 flex flex-col gap-3 pl-4 border-l-2 border-primary/8">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Categoría</label>
                                        <input
                                            v-model="form.category"
                                            type="text"
                                            placeholder="Precios, Bienvenida, Seguimiento..."
                                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                        >
                                    </div>

                                    <div class="flex flex-col gap-1">
                                        <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                            Nombre en Meta
                                        </label>
                                        <input
                                            v-model="form.meta_template_name"
                                            type="text"
                                            placeholder="precio_caracas"
                                            class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25 font-mono"
                                            :class="{ 'border-red-300 bg-red-50': formErrors.meta_template_name }"
                                        >
                                        <p v-if="formErrors.meta_template_name" class="text-xs text-red-500">
                                            {{ formErrors.meta_template_name }}
                                        </p>
                                        <p class="text-[11px] text-primary/40 leading-relaxed">
                                            Solo si esta plantilla está aprobada en Meta. Hace falta para escribirle
                                            a alguien que lleva más de 24 h sin responder.
                                        </p>
                                    </div>
                                </div>
                            </details>

                            <!-- Activa -->
                            <div class="flex items-center justify-between py-1">
                                <div>
                                    <p class="text-sm font-semibold text-primary">Plantilla activa</p>
                                    <p class="text-[11px] text-primary/40">Aparece en el selector del chat</p>
                                </div>
                                <button
                                    type="button"
                                    @click="form.is_active = !form.is_active"
                                    :class="[
                                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                                        form.is_active ? 'bg-green-400' : 'bg-primary/20'
                                    ]"
                                >
                                    <span :class="[
                                        'inline-block h-4 w-4 rounded-full bg-white shadow transition-transform',
                                        form.is_active ? 'translate-x-6' : 'translate-x-1'
                                    ]" />
                                </button>
                            </div>

                            <!-- Botones -->
                            <div class="flex gap-3 pt-3 border-t border-primary/8">
                                <button
                                    type="button"
                                    @click="cerrarModal"
                                    class="flex-1 py-2.5 rounded-xl border border-primary/15 text-sm font-semibold text-primary/60 hover:bg-primary/6 transition-colors"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    :disabled="saving"
                                    class="flex-1 py-2.5 rounded-xl btn-primary text-sm font-bold shadow-md shadow-secondary/20 disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    {{ saving ? 'Guardando...' : (editando ? 'Actualizar' : 'Crear plantilla') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Confirmación de borrado -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="confirmandoBorrado" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-primary/30 backdrop-blur-sm" @click="confirmandoBorrado = null" />

                    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
                        <h3 class="text-base font-primary text-primary">¿Borrar esta plantilla?</h3>
                        <p class="text-sm text-primary/55 mt-2 leading-relaxed">
                            Se eliminará <strong class="text-primary">{{ confirmandoBorrado.name }}</strong>.
                            Los mensajes ya enviados con ella no se ven afectados.
                        </p>
                        <p class="text-xs text-primary/40 mt-3">
                            Si solo quieres dejar de usarla por ahora, desactívala en vez de borrarla.
                        </p>

                        <div class="flex gap-3 mt-5">
                            <button
                                @click="confirmandoBorrado = null"
                                class="flex-1 py-2.5 rounded-xl border border-primary/15 text-sm font-semibold text-primary/60 hover:bg-primary/6 transition-colors"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="borrar"
                                class="flex-1 py-2.5 rounded-xl bg-red-500 text-white text-sm font-bold hover:bg-red-600 transition-colors"
                            >
                                Borrar
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
