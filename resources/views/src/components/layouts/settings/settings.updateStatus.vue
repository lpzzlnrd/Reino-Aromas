<script setup lang="ts">
import { reactive } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import { CaseStatus, useCaseStatus } from '@/hooks/caseStatus'
import Header from '../header/header.vue'

type Client = {
  id: number
  name: string
  status: CaseStatus
}

const { statuses } = useCaseStatus()

const board = reactive<Record<CaseStatus, Client[]>>({
  [CaseStatus.New]: [],
  [CaseStatus.Interested]: [],
  [CaseStatus.HighPriority]: [],
  [CaseStatus.Following]: [],
  [CaseStatus.Reserved]: [],
  [CaseStatus.Closed]: [],
})
</script>

<template>
  <div class="w-full p-4 flex flex-col gap-4">
    <Header />

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <section
        v-for="status in statuses"
        :key="status"
        class="rounded-lg border bg-background p-4 shadow-sm"
      >
        <header class="mb-3 flex items-center justify-between">
          <h3 class="font-semibold">{{ status }}</h3>
          <span class="text-sm text-gray-500">
            {{ board[status].length }}
          </span>
        </header>

        <VueDraggable
          v-model="board[status]"
          :group="{ name: 'clients', pull: true, put: true }"
          item-key="id"
          class="space-y-3 min-h-20"
        >
          <template #item="{ element }">
            <article class="rounded-md border bg-background p-3">
              <p class="font-medium">{{ element.name }}</p>
            </article>
          </template>
        </VueDraggable>
      </section>
    </div>
  </div>
</template>
