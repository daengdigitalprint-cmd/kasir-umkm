<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/sastik-assets');
</script>

<template>
    <!-- story start -->
    <section class="story border-btm pos-rel pb-xs-130 pt-125 pb-160">
        <div class="mxw-1330 container">
            <div class="fs-story-wrpper o-hidden pos-rel">
                <div class="fs-story-top plr-20 ul_li_between mb-65">
                    <div class="sec-title">
                        <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Hasil Nyata dengan Kasir UMKM by MaccaPOS</h2>
                    </div>
                    <div class="fs-slider-btn">
                        <div class="swiper-button-prev">
                            <img :src="`${assetsBase}/img/icon/left-arrow.svg`" alt="arrow" loading="lazy" decoding="async" />
                        </div>
                        <div class="swiper-button-next">
                            <img :src="`${assetsBase}/img/icon/right-arrow03.svg`" alt="arrow" loading="lazy" decoding="async" />
                        </div>
                    </div>
                </div>
                <div class="fs-story-slider swiper-container">
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            <div class="fs-story-item">
                                <div class="xb-item--img">
                                    <Link class="img" href="/panduan"
                                        ><img :src="`${assetsBase}/img/story/img01.jpg`" alt="" loading="lazy" decoding="async"
                                    /></Link>
                                    <div class="logo">
                                        <img :src="`${assetsBase}/img/story/logo08.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="xb-item--holder">
                                    <h2 class="xb-item--title border-effect-2">
                                        <Link href="/panduan">Warung Berkah meningkatkan efisiensi dengan Kasir UMKM by MaccaPOS.</Link>
                                    </h2>
                                    <Link class="xb-item--btn" href="/panduan">
                                        Baca kisah Warung Berkah
                                        <div class="arrow">
                                            <img :src="`${assetsBase}/img/icon/right-arrow01.svg`" alt="arrow" loading="lazy" decoding="async" />
                                            <img :src="`${assetsBase}/img/icon/right-arrow02.svg`" alt="arrow" loading="lazy" decoding="async" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="fs-story-item">
                                <div class="xb-item--img">
                                    <Link class="img" href="/panduan"
                                        ><img :src="`${assetsBase}/img/story/img02.jpg`" alt="" loading="lazy" decoding="async"
                                    /></Link>
                                    <div class="logo">
                                        <img :src="`${assetsBase}/img/story/logo09.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="xb-item--holder">
                                    <h2 class="xb-item--title border-effect-2">
                                        <Link href="/panduan">Toko Sembako Pak Budi beralih ke Kasir UMKM by MaccaPOS untuk otomatisasi stok.</Link>
                                    </h2>
                                    <Link class="xb-item--btn" href="/panduan">
                                        Baca kisah Pak Budi
                                        <div class="arrow">
                                            <img :src="`${assetsBase}/img/icon/right-arrow01.svg`" alt="arrow" loading="lazy" decoding="async" />
                                            <img :src="`${assetsBase}/img/icon/right-arrow02.svg`" alt="arrow" loading="lazy" decoding="async" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="fs-story-item">
                                <div class="xb-item--img">
                                    <Link class="img" href="/panduan"
                                        ><img :src="`${assetsBase}/img/story/img03.jpg`" alt="" loading="lazy" decoding="async"
                                    /></Link>
                                    <div class="logo">
                                        <img :src="`${assetsBase}/img/story/logo10.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="xb-item--holder">
                                    <h2 class="xb-item--title border-effect-2">
                                        <Link href="/panduan">Kafe Makmur mengurangi tugas manual 60% dengan Kasir UMKM by MaccaPOS.</Link>
                                    </h2>
                                    <Link class="xb-item--btn" href="/panduan">
                                        Baca kisah Kafe Makmur
                                        <div class="arrow">
                                            <img :src="`${assetsBase}/img/icon/right-arrow01.svg`" alt="arrow" loading="lazy" decoding="async" />
                                            <img :src="`${assetsBase}/img/icon/right-arrow02.svg`" alt="arrow" loading="lazy" decoding="async" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="fs-story-item">
                                <div class="xb-item--img">
                                    <Link class="img" href="/panduan"
                                        ><img :src="`${assetsBase}/img/story/img04.jpg`" alt="" loading="lazy" decoding="async"
                                    /></Link>
                                    <div class="logo">
                                        <img :src="`${assetsBase}/img/story/logo11.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="xb-item--holder">
                                    <h2 class="xb-item--title border-effect-2">
                                        <Link href="/panduan">Apotek Sehat meningkatkan akurasi laporan dengan Kasir UMKM by MaccaPOS.</Link>
                                    </h2>
                                    <Link class="xb-item--btn" href="/panduan">
                                        Baca kisah Apotek Sehat
                                        <div class="arrow">
                                            <img :src="`${assetsBase}/img/icon/right-arrow01.svg`" alt="arrow" loading="lazy" decoding="async" />
                                            <img :src="`${assetsBase}/img/icon/right-arrow02.svg`" alt="arrow" loading="lazy" decoding="async" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="fs-story-item">
                                <div class="xb-item--img">
                                    <Link class="img" href="/panduan"
                                        ><img :src="`${assetsBase}/img/story/img05.jpg`" alt="" loading="lazy" decoding="async"
                                    /></Link>
                                    <div class="logo">
                                        <img :src="`${assetsBase}/img/story/logo12.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="xb-item--holder">
                                    <h2 class="xb-item--title border-effect-2">
                                        <Link href="/panduan">Bisnis berkembang memusatkan penjualan dan stok dengan Kasir UMKM by MaccaPOS</Link>
                                    </h2>
                                    <Link class="xb-item--btn" href="/panduan">
                                        Baca kisah mereka
                                        <div class="arrow">
                                            <img :src="`${assetsBase}/img/icon/right-arrow01.svg`" alt="arrow" loading="lazy" decoding="async" />
                                            <img :src="`${assetsBase}/img/icon/right-arrow02.svg`" alt="arrow" loading="lazy" decoding="async" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="xb-dot-box">
            <span></span>
            <span></span>
        </div>
    </section>
    <!-- story end -->
</template>
