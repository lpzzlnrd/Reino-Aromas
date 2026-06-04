<script setup lang="ts">
    import User from '../../icons/icon.user.vue'
</script>

<template>
    <div class="glass-card h-full p-6 flex flex-col gap-4">
        <header class="flex items-center justify-between border-b border-primary/8 pb-4">
            <h2 class="text-xl font-primary text-primary">Actividad reciente</h2>
            <button class="text-[11px] font-bold text-secondary hover:text-accent-hover uppercase tracking-widest transition-colors cursor-pointer">
                Ver todo
            </button>
        </header>

        <div class="flex flex-col gap-1 flex-1 overflow-y-auto scroll">
            <!-- Item 1 -->
            <div class="flex items-center justify-between p-3 rounded-xl hover:bg-primary/4 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0">
                        <User class="text-lg translate-y-0.5" />
                    </div>
                    <div>
                        <p class="text-sm text-primary leading-tight">
                            <span class="font-semibold">Nombre usuario</span>
                            <span class="font-normal text-txt"> compró </span>
                            <span class="font-semibold">"Nombre producto"</span>
                        </p>
                        <p class="text-[11px] text-primary/40 mt-0.5">Hace 0 minutos · <span class="text-secondary font-medium">Caracas</span></p>
                    </div>
                </div>
                <span class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-accent/30 text-primary border border-accent/50 shrink-0">Fragancia</span>
            </div>

            <!-- Item 2 -->
            <div class="flex items-center justify-between p-3 rounded-xl hover:bg-primary/4 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0">
                        <User class="text-lg translate-y-0.5" />
                    </div>
                    <div>
                        <p class="text-sm text-primary leading-tight">
                            <span class="font-semibold">Nombre usuario</span>
                            <span class="font-normal text-txt"> reservó </span>
                            <span class="font-semibold">"Nombre producto"</span>
                        </p>
                        <p class="text-[11px] text-primary/40 mt-0.5">Hace 0 minutos · <span class="text-secondary font-medium">Valencia</span></p>
                    </div>
                </div>
                <span class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-secondary/20 text-primary border border-secondary/30 shrink-0">Reserva</span>
            </div>

            <!-- Item 3 -->
            <div class="flex items-center justify-between p-3 rounded-xl hover:bg-primary/4 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0">
                        <User class="text-lg translate-y-0.5" />
                    </div>
                    <div>
                        <p class="text-sm text-primary leading-tight">
                            <span class="font-semibold">Nombre usuario</span>
                            <span class="font-normal text-txt"> completó </span>
                            <span class="font-semibold">el pago</span>
                        </p>
                        <p class="text-[11px] text-primary/40 mt-0.5">Hace 0 minutos · <span class="text-secondary font-medium">Barquisimeto</span></p>
                    </div>
                </div>
                <span class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-green-100 text-green-700 border border-green-200 shrink-0">Venta</span>
            </div>

            <!-- Item 4 -->
            <div class="flex items-center justify-between p-3 rounded-xl hover:bg-primary/4 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary/40 to-accent/60 flex items-center justify-center text-primary shrink-0">
                        <User class="text-lg translate-y-0.5" />
                    </div>
                    <div>
                        <p class="text-sm text-primary leading-tight">
                            <span class="font-semibold">Nombre usuario</span>
                            <span class="font-normal text-txt"> inició </span>
                            <span class="font-semibold">un nuevo chat</span>
                        </p>
                        <p class="text-[11px] text-primary/40 mt-0.5">Hace 0 minutos · <span class="text-secondary font-medium">Caracas</span></p>
                    </div>
                </div>
                <span class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full bg-primary/8 text-primary border border-primary/15 shrink-0">Atención</span>
            </div>
        </div>
    </div>
</template>
