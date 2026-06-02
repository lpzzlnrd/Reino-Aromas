import { computed, ref } from 'vue'
import { CaseStatus } from './caseStatus'

export type MetaChat = {
  id: string
  name: string
  avatar?: string
  lastMessage: string
  time: string
  location?: string
  status: CaseStatus
}

export type MetaApiChat = {
  id: string
  contact_name: string
  contact_avatar?: string
  last_message: string
  message_time: string
  location?: string
  case_status: CaseStatus
}

const metaChats = ref<MetaChat[]>([])
const selectedChat = ref<MetaChat | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

export function useMetaData() {
  const chatsCount = computed(() => metaChats.value.length)

  const selectedStatus = computed(() => selectedChat.value?.status ?? null)

  const setMetaChats = (items: MetaApiChat[]) => {
    metaChats.value = items.map((item) => ({
      id: item.id,
      name: item.contact_name,
      avatar: item.contact_avatar,
      lastMessage: item.last_message,
      time: item.message_time,
      location: item.location,
      status: item.case_status,
    }))
  }

  const selectChat = (chat: MetaChat | null) => {
    selectedChat.value = chat
  }

  const clearSelection = () => {
    selectedChat.value = null
  }

  const loadMetaChats = async () => {
    loading.value = true
    error.value = null

    try {
      const response = await fetch('/api/meta/chats')

      if (!response.ok) {
        throw new Error('No se pudieron cargar los chats')
      }

      const data: MetaApiChat[] = await response.json()
      setMetaChats(data)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Error desconocido'
    } finally {
      loading.value = false
    }
  }

  return {
    metaChats,
    selectedChat,
    loading,
    error,
    chatsCount,
    selectedStatus,
    setMetaChats,
    selectChat,
    clearSelection,
    loadMetaChats,
  }
}
