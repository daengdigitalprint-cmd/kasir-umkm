<script setup lang="ts">
import { cn } from '@/lib/utils'
import { useEventListener, useMediaQuery, useVModel } from '@vueuse/core'
import { TooltipProvider } from 'reka-ui'
import { computed, type HTMLAttributes, onMounted, type Ref, ref } from 'vue'
import { provideSidebarContext, SIDEBAR_COOKIE_NAME, SIDEBAR_KEYBOARD_SHORTCUT, SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON } from './utils'

const props = withDefaults(defineProps<{
  defaultOpen?: boolean
  open?: boolean
  class?: HTMLAttributes['class']
}>(), {
  defaultOpen: true,
  open: undefined,
})

const emits = defineEmits<{
  'update:open': [open: boolean]
}>()

const isMobile = useMediaQuery('(max-width: 768px)')
const openMobile = ref(false)

// Guard: sidebar wrapper tetap invisible sampai client selesai mount
// agar isMobile sudah punya nilai benar dan tidak terjadi flash/terhambur.
const isMounted = ref(false)
onMounted(() => {
  isMounted.value = true
})

// Baca state awal dari localStorage, fallback ke defaultOpen
const storedState = typeof localStorage !== 'undefined' ? localStorage.getItem(SIDEBAR_COOKIE_NAME) : null
const resolvedDefault = storedState !== null ? storedState === 'true' : (props.defaultOpen ?? true)

const open = useVModel(props, 'open', emits, {
  defaultValue: resolvedDefault,
  passive: (props.open === undefined) as false,
}) as Ref<boolean>

function setOpen(value: boolean) {
  open.value = value
  // Simpan ke localStorage agar persisten lintas sesi
  if (typeof localStorage !== 'undefined') {
    localStorage.setItem(SIDEBAR_COOKIE_NAME, String(value))
  }
}

function setOpenMobile(value: boolean) {
  openMobile.value = value
}

function toggleSidebar() {
  return isMobile.value ? setOpenMobile(!openMobile.value) : setOpen(!open.value)
}

useEventListener('keydown', (event: KeyboardEvent) => {
  if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()
    toggleSidebar()
  }
})

const state = computed(() => open.value ? 'expanded' : 'collapsed')

provideSidebarContext({
  state,
  open,
  setOpen,
  isMobile,
  openMobile,
  setOpenMobile,
  toggleSidebar,
})
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <div
      data-slot="sidebar-wrapper"
      :style="{
        '--sidebar-width': SIDEBAR_WIDTH,
        '--sidebar-width-icon': SIDEBAR_WIDTH_ICON,
      }"
      :class="cn(
        'group/sidebar-wrapper has-data-[variant=inset]:bg-sidebar flex min-h-svh w-full transition-opacity duration-150',
        isMounted ? 'opacity-100' : 'opacity-0',
        props.class,
      )"
      v-bind="$attrs"
    >
      <slot />
    </div>
  </TooltipProvider>
</template>

