<script setup lang="ts">
/**
 * Bidang tanda tangan (jari, stylus, atau mouse). Nilainya gambar PNG `data:` berlatar putih,
 * atau string kosong selama belum ada coretan.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Eraser } from 'lucide-vue-next';

const props = withDefaults(defineProps<{ modelValue: string; disabled?: boolean; label?: string }>(), {
    disabled: false,
    label: 'Tanda tangan',
});
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

/** Ukuran gambar hasil tetap, apa pun lebar layar, supaya ukuran berkas kecil dan seragam. */
const WIDTH = 600;
const HEIGHT = 220;

const canvasRef = ref<HTMLCanvasElement | null>(null);
const hasInk = ref(false);
let ctx: CanvasRenderingContext2D | null = null;
let drawing = false;
let last: { x: number; y: number } | null = null;

function paintBackground() {
    if (!ctx) return;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, WIDTH, HEIGHT);
}

function pointOf(event: PointerEvent): { x: number; y: number } {
    const rect = canvasRef.value!.getBoundingClientRect();
    return {
        x: ((event.clientX - rect.left) / rect.width) * WIDTH,
        y: ((event.clientY - rect.top) / rect.height) * HEIGHT,
    };
}

function onPointerDown(event: PointerEvent) {
    if (props.disabled || !ctx) return;
    event.preventDefault();
    canvasRef.value?.setPointerCapture(event.pointerId);
    drawing = true;
    last = pointOf(event);
    // Titik tunggal (ketukan) tetap meninggalkan jejak.
    ctx.beginPath();
    ctx.arc(last.x, last.y, 1.4, 0, Math.PI * 2);
    ctx.fillStyle = '#0f172a';
    ctx.fill();
}

function onPointerMove(event: PointerEvent) {
    if (!drawing || !ctx || !last) return;
    event.preventDefault();
    const point = pointOf(event);
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.lineTo(point.x, point.y);
    ctx.stroke();
    last = point;
    hasInk.value = true;
}

function onPointerUp(event: PointerEvent) {
    if (!drawing) return;
    drawing = false;
    last = null;
    try {
        canvasRef.value?.releasePointerCapture(event.pointerId);
    } catch {
        /* pointer sudah dilepas */
    }
    if (hasInk.value && canvasRef.value) {
        emit('update:modelValue', canvasRef.value.toDataURL('image/png'));
    }
}

function clear() {
    paintBackground();
    hasInk.value = false;
    emit('update:modelValue', '');
}

onMounted(() => {
    const canvas = canvasRef.value;
    if (!canvas) return;
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';
    paintBackground();
});

onBeforeUnmount(() => {
    ctx = null;
});

defineExpose({ clear });
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-slate-800">{{ label }}</span>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                :disabled="disabled || !hasInk"
                @click="clear"
            >
                <Eraser class="h-3.5 w-3.5" />
                Hapus
            </button>
        </div>
        <div class="relative overflow-hidden rounded-xl border-2 border-dashed bg-white" :class="hasInk ? 'border-emerald-400' : 'border-slate-300'">
            <!-- touch-none: gerakan jari menggambar, bukan menggulir halaman -->
            <canvas
                ref="canvasRef"
                class="block w-full touch-none select-none"
                :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-crosshair'"
                :style="{ aspectRatio: `${WIDTH} / ${HEIGHT}` }"
                :aria-label="label"
                role="img"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @pointerleave="onPointerUp"
            />
            <span
                v-if="!hasInk"
                class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-slate-400"
            >
                Tanda tangan di sini
            </span>
        </div>
    </div>
</template>
