<script setup lang="ts">
    import Mail from '../../icons/icon.mail.vue'
    import Calendar from '../../icons/icon.calendar.vue'
    import Phone from '../../icons/icon.phone.vue'
</script>

<template>
    <div class="h-full border-l border-primary/10 bg-white/50 flex flex-col overflow-y-auto scroll">
        <!-- Header -->
        <div class="px-4 py-4 border-b border-primary/8">
            <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Información del contacto</p>
        </div>

        <!-- Datos del contacto -->
        <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2.5">
            <p class="flex items-center gap-2 text-sm text-primary/70">
                <Mail class="text-secondary shrink-0"/>
                <span class="truncate">reinoaromas@gmail.com</span>
            </p>
            <p class="flex items-center gap-2 text-sm text-primary/70">
                <Phone class="text-secondary shrink-0"/>
                0424-4445210
            </p>
            <p class="flex items-center gap-2 text-sm text-primary/70">
                <Calendar class="text-secondary shrink-0"/>
                Cliente desde 2024
            </p>
        </div>

        <!-- Datos del ticket -->
        <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2">
            <p class="text-xs font-bold text-primary/40 uppercase tracking-widest mb-1">Ticket #RE-8921</p>
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-primary/50">Canal</span>
                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700 border border-green-200">WhatsApp</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-primary/50">Última act.</span>
                <span class="text-xs text-primary/60">Hace 2 min</span>
            </div>
        </div>

        <!-- Notas internas -->
        <div class="px-4 py-4 border-b border-primary/8 flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Notas internas</p>
                <button type="button" class="text-[10px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer">Añadir</button>
            </div>
            <textarea
                class="w-full h-20 text-xs text-primary p-2.5 bg-white/70 border border-primary/12 rounded-xl resize-none focus:outline-none focus:border-secondary/50 transition-colors placeholder:text-primary/25"
                placeholder="Escribe una nota..."
            />
        </div>

        <!-- Etiquetas -->
        <div class="px-4 py-4 flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold text-primary/40 uppercase tracking-widest">Etiquetas</p>
                <button type="button" class="text-[10px] font-bold uppercase tracking-widest text-secondary hover:text-accent-hover transition-colors cursor-pointer">Añadir</button>
            </div>
            <div class="flex flex-wrap gap-1.5 min-h-6">
                <!-- Las etiquetas del ticket se cargan aquí -->
            </div>
        </div>
    </div>
</template>
