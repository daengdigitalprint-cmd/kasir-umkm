<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    maskotUrl?: string;
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/');
</script>

<template>
    <!-- hero start -->
    <section class="hero-area hero-area--four pos-rel">
        <div class="container mxw-1330">
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-12">
                    <div class="xb-hero-content text-center">
                        <div class="sec-title">
                            <h2 class="title xb-blur-title heroTitleUp" data-animation-delay="0ms">Kelola usaha Anda dengan UMKM Kasir</h2>
                        </div>
                        <p class="xb-item--content xb-blur-content xbFadeUpContent" data-animation-delay="100ms">Satu sistem sederhana untuk menangani pembayaran dan mengembangkan bisnis Anda.</p>
                        <div class="xb-hero-btn xb-item--btn ul_li justify-content-center xbFadeUpItem" data-animation-delay="200ms">
                            <Link href="/register" class="thm-btn fintech-btn">Mulai gratis</Link>
                            <Link href="/panduan" class="thm-btn fintech-btn fintech-btn--white">Lihat panduan</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="xb-hero-shape">
            <div class="shape shape--1 xb-blur-title xbFadeUpItem" data-animation-delay="250ms">
                <img class="updown" :src="`${assetsBase}/img/hero/icon-img01.png`" alt="icon" loading="lazy" decoding="async" width="80" height="80">
            </div>
            <div class="shape shape--2 xb-blur-title xbFadeUpItem" data-animation-delay="350ms">
                <img class="updown-2" :src="`${assetsBase}/img/hero/icon-img02.png`" alt="icon" loading="lazy" decoding="async" width="80" height="80">
            </div>
        </div>
    </section>
    <!-- hero end -->
</template>
