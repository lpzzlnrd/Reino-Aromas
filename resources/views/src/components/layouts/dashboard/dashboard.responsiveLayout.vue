<script setup lang="ts">
    import { ref } from 'vue'
    import { useRouter } from 'vue-router'
    import { useAuth } from '@/composables/useAuth'

    import Logo from '../../logo.vue'
    import Home from '../../icons/icon.home.vue'
    import Message from '../../icons/icon.chat.vue'
    import Users from '../../icons/icon.users.vue'
    import Comments from '../../icons/icon.comments.vue'
    import Chart from '../../icons/icon.chart.vue'
    import Check from '../../icons/icon.check.vue'
    import User from '../../icons/icon.user.vue'
    import Gear from '../../icons/icon.setting.vue'
    import Close from '../../icons/icon.close.vue'
    import Bars from '../../icons/icon.bars.vue'
    import { useModal } from '@/composables/useModal'

    const router = useRouter()
    const { user, logout } = useAuth()

    const open = ref(false)
    const close = () => (open.value = false)

    /*
     * Escape y scroll-lock del drawer movil. Era `fixed inset-0` sin bloquear el
     * body, asi que el contenido de detras seguia scrolleando bajo el panel — el
     * bug clasico de iOS — y al cerrar el foco no volvia al boton hamburguesa.
     */
    const panelDrawer = ref<HTMLElement | null>(null)

    useModal(open, close, { panel: panelDrawer })
    const toggle = () => (open.value = !open.value)

    const goTo = (name: string) => { router.push({ name }); close() }
</script>

<template>
    <!-- El breakpoint del flex-row debe coincidir con el del sidebar (md), si no
         entre 768px y 1024px el sidebar se apilaba encima del contenido. -->
    <div class="min-h-screen mesh-bg flex flex-col md:flex-row font-secondary">

        <!-- Header móvil -->
        <header class="md:hidden sticky top-0 z-50 flex items-center justify-between px-4 py-3
                        bg-surface/80 backdrop-blur-md border-b border-primary/8 shadow-sm">
            <button @click="toggle" aria-label="Abrir menú"
                    class="p-2 rounded-xl bg-accent/30 text-primary hover:bg-accent transition-colors">
                <Bars />
            </button>
            <Logo class="h-8" />
            <!-- Antes había un botón "Venta" acá. Ventas y pagos están fuera
                 del alcance acordado, así que prometía una función que no
                 existe y no llegaba a ninguna parte. Se reemplaza por el acceso
                 a la bandeja, que es la acción diaria real del agente. -->
            <button
                @click="goTo('Messages Home')"
                class="btn-primary px-3 py-2 text-xs shadow-sm"
                aria-label="Ir a mensajes"
            >
                <Message class="text-sm" />
                <span>Mensajes</span>
            </button>
        </header>

        <!-- Sidebar desktop -->
        <aside class="hidden md:flex glass-sidebar h-screen w-60 flex-col sticky top-0 px-3 py-6 shrink-0 z-40">

            <!-- Logo + nombre -->
            <div class="mb-8 px-2 flex flex-col items-center gap-2 text-center">
                <Logo class="w-24 drop-shadow-sm" />
                <div>
                    <p class="text-primary font-primary text-lg tracking-tight leading-tight">Reino Aromas</p>
                    <p class="text-[9px] text-secondary font-bold uppercase tracking-[0.2em] opacity-70">Gourmet & Artesanal</p>
                </div>
            </div>

            <!-- Acción rápida.
                 Era "Nueva Venta", que no hacía nada: ventas y pagos quedaron
                 fuera del alcance. Ahora lleva a la bandeja, que es donde
                 empieza todo el trabajo del agente. -->
            <div class="px-2 mb-6">
                <button
                    @click="goTo('Messages Home')"
                    class="btn-primary w-full shadow-md shadow-secondary/20 group text-sm py-2.5"
                >
                    <Message class="group-hover:scale-110 transition-transform duration-200" />
                    <span>Ir a mensajes</span>
                </button>
            </div>

            <!-- Navegación principal -->
            <nav class="flex flex-col gap-0.5 flex-1 overflow-y-auto">
                <p class="px-3 text-[10px] font-bold text-primary/40 uppercase tracking-widest mb-2">Principal</p>
                <button class="nav-item" @click="goTo('Dashboard Home')"><Home /><span>Dashboard</span></button>
                <button class="nav-item" @click="goTo('Messages Home')"><Message /><span>Mensajería</span></button>

                <button class="nav-item" @click="goTo('Tickets Board')"><Check /><span>Tablero</span></button>

                <button class="nav-item" @click="goTo('Clients')"><Users /><span>Clientes</span></button>

                <button class="nav-item" @click="goTo('Reports')"><Chart /><span>Reportes</span></button>
            </nav>

            <!-- Sistema -->
            <nav class="flex flex-col gap-0.5 mt-auto pt-4 border-t border-primary/8">
                <p class="px-3 text-[10px] font-bold text-primary/40 uppercase tracking-widest mb-2">Sistema</p>
                <button class="nav-item" @click="goTo('Templates')"><Comments /><span>Plantillas</span></button>
                <button class="nav-item" @click="goTo('Users')"><Users /><span>Administradores</span></button>
                <button class="nav-item" @click="goTo('Accounts')"><User /><span>Mi Perfil</span></button>
                <button class="nav-item" @click="goTo('Accounts')"><Gear /><span>Ajustes</span></button>

                <!-- Usuario logueado -->
                <div v-if="user" class="mt-3 mx-1 px-3 py-2.5 rounded-xl bg-primary/5 border border-primary/8 flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-secondary to-accent-hover flex items-center justify-center text-white text-xs font-bold shrink-0">
                        {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-primary truncate">{{ user.name }}</p>
                        <p class="text-[10px] text-primary/50 capitalize">{{ user.role }}</p>
                    </div>
                </div>

                <button @click="logout"
                        class="nav-item text-red-400 hover:bg-red-50 hover:text-red-600 mt-1">
                    <span>↩</span><span>Cerrar sesión</span>
                </button>
            </nav>
        </aside>

        <!-- Menú móvil overlay -->
        <transition name="slide">
            <div v-if="open" class="fixed inset-0 z-50 flex md:hidden">
                <div
                    ref="panelDrawer"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Menú de navegación"
                    class="w-72 bg-surface/95 backdrop-blur-xl h-full shadow-2xl flex flex-col p-6"
                >
                    <header class="flex items-center justify-between mb-8">
                        <Logo class="w-20" />
                        <button @click="close" aria-label="Cerrar menú"
                                class="p-2 rounded-full bg-accent/20 text-primary hover:bg-accent transition-colors">
                            <Close />
                        </button>
                    </header>
                    <nav class="flex flex-col gap-1 flex-1">
                        <button @click="goTo('Dashboard Home')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Home /><span>Dashboard</span></button>
                        <button @click="goTo('Messages Home')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Message /><span>Mensajería</span></button>
                        <button @click="goTo('Tickets Board')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Check /><span>Tablero</span></button>
                        <button @click="goTo('Clients')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Users /><span>Clientes</span></button>
                        <button @click="goTo('Reports')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Chart /><span>Reportes</span></button>
                        <button @click="goTo('Templates')" class="flex items-center gap-3 text-primary/70 font-medium p-3 rounded-xl hover:bg-accent/20 hover:text-primary transition-colors"><Comments /><span>Plantillas</span></button>
                    </nav>
                    <footer v-if="user" class="mt-auto border-t border-primary/10 pt-4 flex items-center gap-2 p-2">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-secondary to-accent-hover flex items-center justify-center text-white text-xs font-bold">
                            {{ user.name.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-primary">{{ user.name }}</p>
                            <p class="text-xs text-primary/50 capitalize">{{ user.role }}</p>
                        </div>
                    </footer>
                </div>
                <div class="flex-1 bg-primary/20 backdrop-blur-sm" @click="close"></div>
            </div>
        </transition>

        <!-- Contenido principal -->
        <main class="flex-1 min-w-0 flex flex-col overflow-x-hidden">
            <router-view />
        </main>
    </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.slide-enter-from, .slide-leave-to { transform: translateX(-100%); }
.slide-enter-to, .slide-leave-from { transform: translateX(0); }
</style>
