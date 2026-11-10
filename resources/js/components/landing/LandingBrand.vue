<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

const brandLogos = [
    { file: 'brand-logo07.png', width: 112, height: 29 },
    { file: 'brand-logo08.png', width: 116, height: 20 },
    { file: 'brand-logo09.png', width: 116, height: 20 },
    { file: 'brand-logo10.png', width: 97, height: 26 },
    { file: 'brand-logo11.png', width: 114, height: 19 },
    { file: 'brand-logo12.png', width: 104, height: 26 },
    { file: 'brand-logo13.png', width: 77, height: 24 },
];
</script>

<template>
    <section class="brand pt-130 pb-55">
        <div class="mxw-1330 container">
            <div class="fs-brand-wrapper text-center">
                <p class="fs-brand-top">Dipercaya oleh <span>ribuan UMKM</span> di seluruh Indonesia</p>
                <div class="fs-brand-marquee-wrap o-hidden marquee-left">
                    <ul class="fs-brand-marquee">
                        <li class="fs-marquee-item" v-for="logo in brandLogos" :key="logo.file">
                            <img
                                :src="`${base}/img/brand/${logo.file}`"
                                :width="logo.width"
                                :height="logo.height"
                                alt=""
                                loading="lazy"
                                decoding="async"
                            />
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
