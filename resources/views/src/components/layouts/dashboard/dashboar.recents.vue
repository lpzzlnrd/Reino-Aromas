<script setup lang="ts">
    import User from '../../icons/icon.user.vue'
    import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
    import { faArrowRight } from '@fortawesome/free-solid-svg-icons'
</script>

<template>
    <div id="recents-div" class="glass-card h-full p-6 flex flex-col">
        <header class="flex flex-row justify-between items-center mb-6 px-2">
            <h2 class="text-xl font-primary text-primary">Actividad Reciente</h2>
            <nav class="text-xs font-bold text-secondary uppercase tracking-widest hover:text-accent-hover cursor-pointer transition-colors flex items-center gap-2 group">
                Ver todo
                <FontAwesomeIcon :icon="faArrowRight" class="group-hover:translate-x-1 transition-transform" />
            </nav>
        </header>

        <div id="recent-chats" class="flex-1 flex flex-col gap-4">
            <!-- Item 1 -->
            <div id="chat1" class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-colors group">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-12 h-12 rounded-full bg-accent/20 flex items-center justify-center text-primary overflow-hidden">
                            <User class="text-4xl translate-y-1 opacity-80" />
                        </div>
                        <div class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-bold text-primary text-sm">Nombre usuario</span>
                            <span class="text-xs text-primary/40">•</span>
                            <span class="text-xs text-primary/60">Compró <span class="text-primary font-medium">"Fragancia Rosas"</span></span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-secondary font-medium uppercase tracking-wider opacity-60">
                            <span>Hace 2 mins</span>
                            <span class="w-1 h-1 bg-secondary rounded-full opacity-30"></span>
                            <span>Caracas</span>
                        </div>
                    </div>
                </div>
                <span class="bg-accent/30 text-primary text-[10px] font-bold uppercase tracking-widest py-1.5 px-3 rounded-lg group-hover:bg-primary group-hover:text-white transition-all">Fragancia</span>
            </div>

            <!-- Item 2 -->
            <div id="chat2" class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-colors group">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-12 h-12 rounded-full bg-secondary/20 flex items-center justify-center text-secondary overflow-hidden">
                            <User class="text-4xl translate-y-1 opacity-80" />
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-bold text-primary text-sm">Nombre usuario</span>
                            <span class="text-xs text-primary/40">•</span>
                            <span class="text-xs text-primary/60">Reservó <span class="text-primary font-medium">"Curso Jabones"</span></span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-secondary font-medium uppercase tracking-wider opacity-60">
                            <span>Hace 15 mins</span>
                            <span class="w-1 h-1 bg-secondary rounded-full opacity-30"></span>
                            <span>Valencia</span>
                        </div>
                    </div>
                </div>
                <span class="bg-secondary/30 text-primary text-[10px] font-bold uppercase tracking-widest py-1.5 px-3 rounded-lg group-hover:bg-primary group-hover:text-white transition-all">Reserva</span>
            </div>

            <!-- Item 3 -->
            <div id="chat3" class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-colors group">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center text-primary overflow-hidden">
                            <User class="text-4xl translate-y-1 opacity-80" />
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-bold text-primary text-sm">Nombre usuario</span>
                            <span class="text-xs text-primary/40">•</span>
                            <span class="text-xs text-primary/60">Completó <span class="text-primary font-medium">Pago Móvil</span></span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-secondary font-medium uppercase tracking-wider opacity-60">
                            <span>Hace 1 hora</span>
                            <span class="w-1 h-1 bg-secondary rounded-full opacity-30"></span>
                            <span>Barquisimeto</span>
                        </div>
                    </div>
                </div>
                <span class="bg-primary/10 text-primary text-[10px] font-bold uppercase tracking-widest py-1.5 px-3 rounded-lg group-hover:bg-primary group-hover:text-white transition-all">Venta</span>
            </div>

            <!-- Item 4 -->
            <div id="chat4" class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-colors group">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-12 h-12 rounded-full bg-accent/20 flex items-center justify-center text-primary overflow-hidden">
                            <User class="text-4xl translate-y-1 opacity-80" />
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-bold text-primary text-sm">Nombre usuario</span>
                            <span class="text-xs text-primary/40">•</span>
                            <span class="text-xs text-primary/60">Inició <span class="text-primary font-medium">Nueva Consulta</span></span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-secondary font-medium uppercase tracking-wider opacity-60">
                            <span>Hace 3 horas</span>
                            <span class="w-1 h-1 bg-secondary rounded-full opacity-30"></span>
                            <span>Caracas</span>
                        </div>
                    </div>
                </div>
                <span class="bg-accent/30 text-primary text-[10px] font-bold uppercase tracking-widest py-1.5 px-3 rounded-lg group-hover:bg-primary group-hover:text-white transition-all">Atención</span>
            </div>
        </div>
    </div>
</template>
