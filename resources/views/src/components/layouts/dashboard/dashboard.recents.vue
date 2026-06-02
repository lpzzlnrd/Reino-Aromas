<script setup lang="ts">
    import User from '../../icons/icon.user.vue'
</script>

<template>
    <div class="p-2 w-full lg:w-2/3">
        <div id="recents-div" class="border-2 border-primary bg-background rounded-md h-full p-2 shadow-lg">
            <header class="subtitle border-b-2 border-secondary flex flex-row justify-between p-1">
                <p>Actividad reciente</p>
                <nav class="hover:cursor-pointer hover:text-accent-hover">ver todo</nav>
            </header>
            <div id="recent-chats" class="my-2 gap-2 flex flex-col">
                <div id="chat1" class="flex flex-row justify-between">
                    <section class="flex flex-row gap-1">
                        <User class="title text-3xl"/>
                        <section class="flex flex-col gap-0.5">
                            <section class="text-sm flex flex-row gap-1">
                                <p id="nombre-chat1" class="subtitle">Nombre usuario</p>
                                <p id="action-chat1" class="txt">Compró</p>
                                <p id="bought-chat1" class="txt">"Nombre producto"</p>
                            </section>
                            <section class="txt text-sm flex flex-row gap-1">
                                <p id="last-message-chat1">Hace 0 minutos</p>
                                <p>-</p>
                                <p id="state-chat1">Caracas</p>
                            </section>
                        </section>
                    </section>
                    <p id="chat1-action" class="txt bg-background border-2 border-secondary rounded-xl py-1 px-4 text-sm flex items-center">Fragancia</p>
                </div>

                <div id="chat2" class="flex flex-row justify-between">
                    <section class="flex flex-row gap-1">
                        <User class="title text-3xl"/>
                        <section class="flex flex-col gap-0.5">
                            <section class="text-sm flex flex-row gap-1">
                                <p id="nombre-chat2" class="subtitle">Nombre usuario</p>
                                <p id="action-chat2" class="txt">Reservó</p>
                                <p id="bought-chat2" class="txt">"Nombre producto"</p>
                            </section>
                            <section class="txt text-sm flex flex-row gap-1">
                                <p id="last-message-chat2">Hace 0 minutos</p>
                                <p>-</p>
                                <p id="state-chat2">Valencia</p>
                            </section>
                        </section>
                    </section>
                    <p id="chat1-action" class="txt bg-background border-2 border-secondary rounded-xl py-1 px-4 text-sm flex items-center">Reserva</p>
                </div>

                <div id="chat3" class="flex flex-row justify-between">
                    <section class="flex flex-row gap-1">
                        <User class="title text-3xl"/>
                        <section class="flex flex-col gap-0.5">
                            <section class="text-sm flex flex-row gap-1">
                                <p id="nombre-chat3" class="subtitle">Nombre usuario</p>
                                <p id="action-chat3" class="txt">Completó</p>
                                <p id="bought-chat3" class="txt">el pago</p>
                            </section>
                            <section class="txt text-sm flex flex-row gap-1">
                                <p id="last-message-chat3">Hace 0 minutos</p>
                                <p>-</p>
                                <p id="state-chat3">Barquisimeto</p>
                            </section>
                        </section>
                    </section>
                    <p id="chat1-action" class="txt bg-background border-2 border-secondary rounded-xl py-1 px-4 text-sm flex items-center">Venta</p>
                </div>

                <div id="chat4" class="flex flex-row justify-between">
                    <section class="flex flex-row gap-1">
                        <User class="title text-3xl"/>
                        <section class="flex flex-col gap-0.5">
                            <section class="text-sm flex flex-row gap-1">
                                <p id="nombre-chat4" class="subtitle">Nombre usuario</p>
                                <p id="action-chat4" class="txt">inició</p>
                                <p id="bought-chat4" class="txt">un nuevo chat</p>
                            </section>
                            <section class="txt text-sm flex flex-row gap-1">
                                <p id="last-message-chat4">Hace 0 minutos</p>
                                <p>-</p>
                                <p id="state-chat4">Caracas</p>
                            </section>
                        </section>
                    </section>
                    <p id="chat1-action" class="txt bg-background border-2 border-secondary rounded-xl py-1 px-4 text-sm flex items-center">Atención</p>
                </div>
            </div>
        </div>
    </div>
</template>
