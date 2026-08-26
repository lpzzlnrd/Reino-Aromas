<script setup lang="ts">
    import { ref, computed, onMounted } from 'vue'
    import Header from '../header/header.vue'
    import User from '../../icons/icon.user.vue'
    import Plus from '../../icons/icon.plus.vue'
    import Search from '../../icons/icon.search.vue'
    import Close from '../../icons/icon.close.vue'
    import api from '@/lib/axios'
    import { useModal } from '@/composables/useModal'

    type AdminUser = {
        id: number
        name: string
        email: string
        role: 'superadmin' | 'administrador'
        is_active: boolean
        avatar_url: string | null
        last_login_at: string | null
    }

    type UserForm = {
        name: string
        email: string
        password: string
        password_confirmation: string
        role: 'superadmin' | 'administrador'
        is_active: boolean
    }

    const users = ref<AdminUser[]>([])
    const loading = ref(false)
    const searchQuery = ref('')
    const showModal = ref(false)
    const editingUser = ref<AdminUser | null>(null)
    const formErrors = ref<Record<string, string>>({})
    const saving = ref(false)

    const emptyForm = (): UserForm => ({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'administrador',
        is_active: true,
    })

    const form = ref<UserForm>(emptyForm())

    const filteredUsers = computed(() =>
        users.value.filter(u =>
            u.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            u.email.toLowerCase().includes(searchQuery.value.toLowerCase())
        )
    )

    const fetchUsers = async () => {
        loading.value = true
        try {
            const res = await api.get<AdminUser[]>('/users')
            users.value = res.data
        } catch {
            users.value = []
        } finally {
            loading.value = false
        }
    }

    const openCreate = () => {
        editingUser.value = null
        form.value = emptyForm()
        formErrors.value = {}
        showModal.value = true
    }

    const openEdit = (user: AdminUser) => {
        editingUser.value = user
        form.value = {
            name: user.name,
            email: user.email,
            password: '',
            password_confirmation: '',
            role: user.role,
            is_active: user.is_active,
        }
        formErrors.value = {}
        showModal.value = true
    }

    const panelModal = ref<HTMLElement | null>(null)

    // Escape, foco y scroll-lock: ninguno de los modales los tenia.
    useModal(showModal, () => closeModal(), { panel: panelModal })

    const closeModal = () => {
        showModal.value = false
        editingUser.value = null
        formErrors.value = {}
    }

    const saveUser = async () => {
        saving.value = true
        formErrors.value = {}
        try {
            if (editingUser.value) {
                const payload: Partial<UserForm> = { ...form.value }
                if (!payload.password) {
                    delete payload.password
                    delete payload.password_confirmation
                }
                await api.put(`/users/${editingUser.value.id}`, payload)
            } else {
                await api.post('/users', form.value)
            }
            await fetchUsers()
            closeModal()
        } catch (err: any) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors as Record<string, string[]>
                formErrors.value = Object.fromEntries(
                    Object.entries(errors).map(([k, v]) => [k, v[0] ?? 'Campo inválido'])
                )
            }
        } finally {
            saving.value = false
        }
    }

    const toggleActive = async (user: AdminUser) => {
        try {
            await api.patch(`/users/${user.id}/toggle-active`)
            user.is_active = !user.is_active
        } catch {}
    }

    const formatDate = (date: string | null): string => {
        if (!date) return 'Nunca'
        return new Date(date).toLocaleDateString('es-VE', { day: '2-digit', month: 'short', year: 'numeric' })
    }

    const initials = (name: string) => name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()

    onMounted(fetchUsers)
</script>

<template>
    <div class="px-4 lg:px-8 py-6 w-full animate-fade-in overflow-x-hidden">
        <Header class="mb-8" />

        <!-- Título + acción -->
        <section class="flex items-center justify-between mb-6 px-1">
            <div>
                <h1 class="text-3xl font-primary text-primary">Administradores</h1>
                <p class="text-sm text-primary/50 mt-0.5">Gestiona los accesos al sistema</p>
            </div>
            <button @click="openCreate" class="btn-primary shadow-md shadow-secondary/20 text-sm py-2.5 px-5 group">
                <Plus class="group-hover:rotate-90 transition-transform duration-200 shrink-0" />
                <span>Nuevo admin</span>
            </button>
        </section>

        <!-- Buscador -->
        <div class="mb-5">
            <label class="input-group w-full max-w-xs group cursor-text">
                <Search class="text-primary/40 group-focus-within:text-primary/70 shrink-0 transition-colors" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre o correo..."
                    class="bg-transparent text-sm focus:outline-none w-full placeholder:text-primary/30"
                >
            </label>
        </div>

        <!-- Tabla de usuarios -->
        <div class="glass-card overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-primary/8">
                        <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest">Usuario</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest hidden sm:table-cell">Rol</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest hidden lg:table-cell">Último acceso</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-bold text-primary/40 uppercase tracking-widest">Estado</th>
                        <th class="px-5 py-3.5 text-right text-[11px] font-bold text-primary/40 uppercase tracking-widest">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-primary/40">Cargando...</td>
                    </tr>
                    <tr v-else-if="filteredUsers.length === 0">
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-primary/40">
                            No hay administradores registrados.
                        </td>
                    </tr>
                    <tr
                        v-for="u in filteredUsers"
                        :key="u.id"
                        class="border-b border-primary/5 hover:bg-primary/3 transition-colors"
                    >
                        <!-- Nombre + email -->
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary text-xs font-bold shrink-0">
                                    {{ initials(u.name) }}
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-primary leading-tight">{{ u.name }}</p>
                                    <p class="text-[11px] text-primary/40">{{ u.email }}</p>
                                </div>
                            </div>
                        </td>

                        <!-- Rol -->
                        <td class="px-5 py-4 hidden sm:table-cell">
                            <span :class="[
                                'text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full border',
                                u.role === 'superadmin'
                                    ? 'bg-primary/8 text-primary border-primary/20'
                                    : 'bg-secondary/15 text-primary/70 border-secondary/25'
                            ]">
                                {{ u.role === 'superadmin' ? 'Superadmin' : 'Administrador' }}
                            </span>
                        </td>

                        <!-- Último acceso -->
                        <td class="px-5 py-4 hidden lg:table-cell">
                            <span class="text-xs text-primary/50">{{ formatDate(u.last_login_at) }}</span>
                        </td>

                        <!-- Estado toggle -->
                        <td class="px-5 py-4">
                            <button
                                @click="toggleActive(u)"
                                :class="[
                                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                                    u.is_active ? 'bg-green-400' : 'bg-primary/20'
                                ]"
                                :title="u.is_active ? 'Desactivar' : 'Activar'"
                            >
                                <span :class="[
                                    'inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform',
                                    u.is_active ? 'translate-x-[1.125rem]' : 'translate-x-0.5'
                                ]" />
                            </button>
                        </td>

                        <!-- Acciones -->
                        <td class="px-5 py-4 text-right">
                            <button
                                @click="openEdit(u)"
                                class="text-[11px] font-bold uppercase tracking-widest px-3 py-1.5 rounded-lg border border-primary/15 text-primary/60 hover:bg-primary hover:text-white hover:border-primary transition-all"
                            >
                                Editar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
          </div>
        </div>

    <!-- Modal crear / editar -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-primary/30 backdrop-blur-sm" @click="closeModal" />

                <!-- Panel del modal -->
                <div
                    ref="panelModal"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Datos del usuario"
                    class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-y-auto max-h-[90vh]"
                >
                    <!-- Header modal -->
                    <div class="flex items-center justify-between px-6 py-5 border-b border-primary/8">
                        <div>
                            <h2 class="text-lg font-primary text-primary">
                                {{ editingUser ? 'Editar administrador' : 'Nuevo administrador' }}
                            </h2>
                            <p class="text-xs text-primary/40 mt-0.5">
                                {{ editingUser ? 'Actualiza los datos del usuario' : 'Completa los datos del nuevo acceso' }}
                            </p>
                        </div>
                        <button @click="closeModal" class="p-2 rounded-xl hover:bg-primary/6 text-primary/40 hover:text-primary transition-colors">
                            <Close />
                        </button>
                    </div>

                    <!-- Body modal -->
                    <form @submit.prevent="saveUser" class="px-6 py-5 flex flex-col gap-4">
                        <!-- Nombre -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Nombre completo</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                placeholder="María González"
                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                :class="{ 'border-red-300 bg-red-50': formErrors.name }"
                            >
                            <p v-if="formErrors.name" class="text-xs text-red-500">{{ formErrors.name }}</p>
                        </div>

                        <!-- Email -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Correo electrónico</label>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                placeholder="maria@reinoaromas.com"
                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                :class="{ 'border-red-300 bg-red-50': formErrors.email }"
                            >
                            <p v-if="formErrors.email" class="text-xs text-red-500">{{ formErrors.email }}</p>
                        </div>

                        <!-- Contraseña (obligatoria solo en crear) -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">
                                Contraseña
                                <span v-if="editingUser" class="normal-case font-normal opacity-60">(dejar vacío para no cambiar)</span>
                            </label>
                            <input
                                v-model="form.password"
                                type="password"
                                :required="!editingUser"
                                placeholder="••••••••"
                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                                :class="{ 'border-red-300 bg-red-50': formErrors.password }"
                            >
                            <p v-if="formErrors.password" class="text-xs text-red-500">{{ formErrors.password }}</p>
                        </div>

                        <!-- Confirmar contraseña -->
                        <div v-if="form.password" class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Confirmar contraseña</label>
                            <input
                                v-model="form.password_confirmation"
                                type="password"
                                placeholder="••••••••"
                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                            >
                        </div>

                        <!-- Rol -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-primary/50 uppercase tracking-widest">Rol</label>
                            <select
                                v-model="form.role"
                                class="px-4 py-2.5 rounded-xl border border-primary/12 text-sm text-primary focus:outline-none focus:border-secondary/50 transition-colors bg-white"
                            >
                                <option value="administrador">Administrador</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>

                        <!-- Estado activo -->
                        <div class="flex items-center justify-between py-1">
                            <div>
                                <p class="text-sm font-semibold text-primary">Usuario activo</p>
                                <p class="text-[11px] text-primary/40">Puede iniciar sesión en el sistema</p>
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
                        <div class="flex gap-3 pt-2 border-t border-primary/8">
                            <button
                                type="button"
                                @click="closeModal"
                                class="flex-1 py-2.5 rounded-xl border border-primary/15 text-sm font-semibold text-primary/60 hover:bg-primary/6 transition-colors"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="saving"
                                class="flex-1 py-2.5 rounded-xl btn-primary text-sm font-bold shadow-md shadow-secondary/20 disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {{ saving ? 'Guardando...' : (editingUser ? 'Actualizar' : 'Crear usuario') }}
                            </button>
                        </div>
                    </form>
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
