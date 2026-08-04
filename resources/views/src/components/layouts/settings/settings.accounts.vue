<script setup lang="ts">
    import Header from '../header/header.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Plus from '../../icons/icon.plus.vue'

    const socialStyle = 'flex flex-col items-center gap-5 flex-1'
    const btnStyle = 'flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-xl border-2 border-primary/20 text-primary/70 transition-all hover:cursor-pointer hover:bg-primary hover:text-white hover:border-primary'
</script>

<template>
    <div class="w-full font-secondary flex flex-col gap-1">
        <section class="p-2">
            <Header class="hidden md:flex w-full flex-col"/>
        </section>
        <div class="flex-1 px-6 lg:px-12 py-10 flex flex-col items-center gap-10">
            <!-- Intro -->
            <section class="text-center flex flex-col gap-3 max-w-lg">
                <h1 class="font-primary text-3xl text-primary">Cuentas vinculadas</h1>
                <p class="text-sm text-primary/55 leading-relaxed">Vincula tus cuentas para obtener un control más claro de tus mensajes y clientela a través de tus plataformas.</p>
            </section>

            <!-- Cards de plataformas -->
            <div class="w-full max-w-3xl grid grid-cols-1 md:grid-cols-3 gap-5">
                <!-- Instagram -->
                <div class="glass-card p-6 flex flex-col items-center gap-4 hover:-translate-y-0.5 hover:shadow-md transition-all">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-bl from-purple-500 via-pink-600 to-yellow-300 flex items-center justify-center shadow-md">
                        <Instagram class="text-2xl text-white"/>
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-primary">Instagram</p>
                        <p class="text-[11px] text-primary/40 mt-0.5">No vinculado</p>
                    </div>
                    <button :class="btnStyle">
                        <Plus class="text-xs shrink-0"/>
                        Vincular cuenta
                    </button>
                </div>

                <!-- Facebook -->
                <div class="glass-card p-6 flex flex-col items-center gap-4 hover:-translate-y-0.5 hover:shadow-md transition-all">
                    <div class="w-16 h-16 rounded-2xl bg-blue-600 flex items-center justify-center shadow-md">
                        <Facebook class="text-2xl text-white"/>
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-primary">Facebook</p>
                        <p class="text-[11px] text-primary/40 mt-0.5">No vinculado</p>
                    </div>
                    <button :class="btnStyle">
                        <Plus class="text-xs shrink-0"/>
                        Vincular cuenta
                    </button>
                </div>

                <!-- WhatsApp -->
                <div class="glass-card p-6 flex flex-col items-center gap-4 hover:-translate-y-0.5 hover:shadow-md transition-all">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center shadow-md">
                        <Whatsapp class="text-2xl text-white"/>
                    </div>
                    <div class="text-center">
                        <p class="font-semibold text-primary">WhatsApp</p>
                        <p class="text-[11px] text-primary/40 mt-0.5">No vinculado</p>
                    </div>
                    <button :class="btnStyle">
                        <Plus class="text-xs shrink-0"/>
                        Vincular cuenta
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
