<script setup lang="ts">
/**
 * Slider banner promo di halaman pelanggan (menu digital & pesan antar).
 * Geser dengan jari, berganti sendiri tiap beberapa detik, rasio 5:2 (1200×480).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ images: string[]; intervalMs?: number }>(), { intervalMs: 4500 });

const track = ref<HTMLElement | null>(null);
const current = ref(0);
const many = computed(() => props.images.length > 1);
let timer: ReturnType<typeof setInterval> | undefined;
/** Setelah pelanggan menggeser sendiri, pergantian otomatis jeda sebentar. */
let pausedUntil = 0;

const goTo = (index: number, smooth = true) => {
    const el = track.value;
    if (!el) return;
    el.scrollTo({ left: index * el.clientWidth, behavior: smooth ? 'smooth' : 'auto' });
};
const onScroll = () => {
    const el = track.value;
    if (!el || !el.clientWidth) return;
    current.value = Math.min(props.images.length - 1, Math.max(0, Math.round(el.scrollLeft / el.clientWidth)));
};
const pause = () => {
    pausedUntil = Date.now() + 8000;
};
const pick = (index: number) => {
    pause();
    goTo(index);
};

onMounted(() => {
    if (!many.value) return;
    // Pengguna yang meminta animasi dikurangi: tanpa pergantian otomatis, tetap bisa digeser.
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
    timer = setInterval(() => {
        if (document.hidden || Date.now() < pausedUntil) return;
        goTo((current.value + 1) % props.images.length);
    }, props.intervalMs);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section v-if="images.length" aria-label="Banner promo" aria-roledescription="carousel">
        <div
            ref="track"
            class="flex snap-x snap-mandatory overflow-x-auto scroll-smooth rounded-2xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            @scroll.passive="onScroll"
            @pointerdown.passive="pause"
            @touchstart.passive="pause"
        >
            <div
                v-for="(src, i) in images"
                :key="src"
                class="w-full shrink-0 snap-center"
                role="group"
                aria-roledescription="slide"
                :aria-label="`Banner ${i + 1} dari ${images.length}`"
            >
                <img
                    :src="src"
                    alt=""
                    width="1200"
                    height="480"
                    :loading="i === 0 ? 'eager' : 'lazy'"
                    decoding="async"
                    class="aspect-[5/2] w-full bg-slate-100 object-cover"
                />
            </div>
        </div>
        <div v-if="many" class="mt-2 flex justify-center gap-1.5">
            <button
                v-for="(_, i) in images"
                :key="i"
                type="button"
                class="h-2 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 focus-visible:ring-offset-2"
                :class="i === current ? 'w-5 bg-violet-600' : 'w-2 bg-slate-300 hover:bg-slate-400'"
                :aria-label="`Tampilkan banner ${i + 1}`"
                :aria-current="i === current ? 'true' : undefined"
                @click="pick(i)"
            />
        </div>
    </section>
</template>
