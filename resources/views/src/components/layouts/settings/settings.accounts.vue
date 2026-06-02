<script setup lang="ts">
    import Header from '../header/header.vue'
    import Instagram from '../../icons/social/icon.instagram.vue'
    import Facebook from '../../icons/social/icon.facebook.vue'
    import Whatsapp from '../../icons/social/icon.whatsapp.vue'
    import Plus from '../../icons/icon.plus.vue'

    const socialStyle = 'w-1/3 text-center items-center align-center flex flex-col gap-4'
    const btnStyle = 'text-left border-2 border-primary rounded-md my-0 px-4 py-2 transition hover:cursor-pointer hover:bg-primary hover:text-secondary'
</script>

<template>
    <div class="w-full font-secondary flex flex-col gap-1">
        <section class="p-2">
            <Header class="hidden md:flex w-full flex-col"/>
        </section>
        <div class="txt h-full w-full px-12 bg-background flex flex-col items-center justify-center rounded-sm gap-20">
            <section class="text-center flex flex-col gap-6">
                <p class="title text-4xl">Cuentas vinculadas</p>
                <p class="txt">Vincule sus cuentas con el fin de obtener un control mas claro y sencillo de sus mensajes y clientela a traves de sus plataformas</p>
            </section>
            <div class="w-full p-40 border-2 border-primary flex flex-row items-center rounded-md justify-between">
                <div :class="socialStyle">
                    <div class="p-6 rounded-full bg-accent">
                        <section class="p-4 bg-linear-to-bl from-purple-500 via-pink-600 to-yellow-300 rounded-full">
                            <Instagram class="text-4xl text-white"/>
                        </section>
                    </div>
                    <p>Instagram</p>
                    <button :class="btnStyle">
                        <Plus />
                        Vincular cuenta
                    </button>
                </div>
                <div :class="socialStyle">
                    <div class="p-6 rounded-full bg-accent">
                        <section class="p-4 bg-blue-500 rounded-full">
                            <Facebook class="text-4xl text-white"/>
                        </section>
                    </div>
                    <p>Facebook</p>
                    <button :class="btnStyle">
                        <Plus />
                        Vincular cuenta
                    </button>
                </div>
                <div :class="socialStyle">
                    <div class="p-6 rounded-full bg-accent">
                        <section class="p-4 bg-linear-to-br from-green-400 via-green-500 to-green-600 rounded-full">
                            <Whatsapp class="text-4xl text-white"/>
                        </section>
                    </div>
                    <p>Whatsapp</p>
                    <button :class="btnStyle">
                        <Plus />
                        Vincular cuenta
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
