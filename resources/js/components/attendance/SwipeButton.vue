<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { ChevronsRight, Loader2 } from 'lucide-vue-next';

/**
 * Tombol geser konfirmasi ("Swipe to Check In"), sama seperti aplikasi Android.
 * Aksi hanya terpicu bila tombol digeser hampir sampai ujung (85%).
 */
const props = withDefaults(
    defineProps<{
        label: string;
        icon: unknown;
        from: string;
        to: string;
        busy?: boolean;
        disabled?: boolean;
    }>(),
    { busy: false, disabled: false },
);

const emit = defineEmits<{ swiped: [] }>();

const HEIGHT = 64;
const PADDING = 5;
const KNOB = HEIGHT - PADDING * 2;

const track = ref<HTMLElement | null>(null);
const drag = ref(0);
const dragging = ref(false);
let startX = 0;
let startDrag = 0;

const active = computed(() => !props.disabled && !props.busy);
const max = () => Math.max(1, (track.value?.clientWidth ?? 0) - KNOB - PADDING * 2);
const progress = computed(() => Math.min(1, drag.value / max()));

function onDown(event: PointerEvent) {
    if (!active.value) return;
    dragging.value = true;
    startX = event.clientX;
    startDrag = drag.value;
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
}

function onMove(event: PointerEvent) {
    if (!dragging.value) return;
    drag.value = Math.min(max(), Math.max(0, startDrag + event.clientX - startX));
}

function onUp() {
    if (!dragging.value) return;
    dragging.value = false;
    if (drag.value >= max() * 0.85) {
        drag.value = max();
        emit('swiped');
        // Kembali ke awal setelah sesaat (halaman induk menampilkan proses/hasil).
        resetTimer = setTimeout(() => (drag.value = 0), 450);
    } else {
        drag.value = 0;
    }
}

let resetTimer: ReturnType<typeof setTimeout> | null = null;
onBeforeUnmount(() => resetTimer && clearTimeout(resetTimer));
</script>

<template>
    <div
        ref="track"
        class="relative select-none overflow-hidden rounded-full"
        :style="{
            height: `${HEIGHT}px`,
            background: active ? `linear-gradient(90deg, ${from}, ${to})` : '#D5D8E3',
            boxShadow: active ? `0 8px 18px ${to}59` : 'none',
        }"
    >
        <div
            class="pointer-events-none absolute inset-0 flex items-center justify-center gap-2 pl-8 text-base font-extrabold tracking-wide text-white"
            :style="{ opacity: Math.max(0, 1 - progress * 1.4) }"
        >
            {{ busy ? 'Memproses...' : label }}
            <ChevronsRight v-if="!busy" class="h-6 w-6 opacity-75" />
        </div>
        <button
            type="button"
            class="absolute top-[5px] flex touch-none items-center justify-center rounded-full bg-white shadow-sm"
            :class="[dragging ? '' : 'transition-[left] duration-300 ease-out', active ? 'cursor-grab active:cursor-grabbing' : 'cursor-not-allowed']"
            :style="{ left: `${PADDING + drag}px`, width: `${KNOB}px`, height: `${KNOB}px` }"
            :aria-label="label"
            :disabled="!active"
            @pointerdown="onDown"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="onUp"
            @keydown.enter.prevent="active && emit('swiped')"
        >
            <Loader2 v-if="busy" class="h-6 w-6 animate-spin" :style="{ color: to }" />
            <component :is="icon" v-else class="h-7 w-7" :style="{ color: active ? to : '#9AA0B4' }" />
        </button>
    </div>
</template>
