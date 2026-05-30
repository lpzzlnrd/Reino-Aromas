<script setup lang="ts">
    import { ref } from 'vue'
    import { useRoute, useRouter } from 'vue-router'

    const router = useRouter()
    const route = useRoute()

    const baseBtn = 'text-left border-primary rounded-md my-0 px-4 py-2 w-40 max-w-40 transition'
    const inactiveBtn = 'text-txt hover:cursor-pointer hover:bg-primary hover:text-secondary'
    const activeBtn = 'text-primary border-r-4 bg-accent'

    const isActive = (name: string | string[]) => {
        const current = route.name as string | undefined
        if (!current) return false
        if (Array.isArray(name)) return name.includes(current)
        return current === name
    }

    const buttonClass = (name: string | string[]) => `${baseBtn} ${isActive(name) ? activeBtn : inactiveBtn}`

    const goTo = (name: string) => {
        router.push({ name })
    }

    const open = ref(false)
    const close = () => (open.value = false)
    const toggle = () => (open.value = !open.value)
</script>

<template>
    <!-- Desktop Menu -->
    <div id="settings-desktop-menu" class="hidden md:flex md:w-1/8 md:flex-none md:min-w-0">
        <transition name="slide">
            <div class="bg-background w-full h-full border-r-4 border-primary overflow-hidden">
                <div class='font-secondary gap-2 p-4 flex flex-col items-center justify-center text-center'>
                    <button :class="buttonClass('Accounts')" type:toggle @click="goTo('Accounts')">
                        Accounts
                    </button>
                    <button :class="buttonClass('Users status')" type:toggle @click="goTo('Users status')">
                        Status
                    </button>
                </div>
            </div>
        </transition>
    </div>
    <router-view />
</template>
