<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

const brandLogos = [
    'brand-logo07.png',
    'brand-logo08.png',
    'brand-logo09.png',
    'brand-logo10.png',
    'brand-logo11.png',
    'brand-logo12.png',
    'brand-logo13.png',
];
</script>

<template>
    <section class="brand pt-130 pb-55">
        <div class="container mxw-1330">
            <div class="fs-brand-wrapper text-center">
                <p class="fs-brand-top">Dipercaya oleh <span>ribuan UMKM</span> di seluruh Indonesia</p>
                <div class="fs-brand-marquee-wrap o-hidden marquee-left">
                    <ul class="fs-brand-marquee">
                        <li class="fs-marquee-item" v-for="logo in brandLogos" :key="logo">
                            <img :src="`${base}/img/brand/${logo}`" alt="logo" loading="lazy" decoding="async">
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
