<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

const starterFeatures: string[] = [
    'Hingga 30 produk',
    'POS dasar & cetak struk',
    'Transaksi & laporan singkat',
    'Pakai tanpa batas masa aktif',
    'Manajemen stok dasar',
];

const growUpFeatures: string[] = [
    'Produk (SKU) tanpa batas',
    'Transaksi tanpa batas per hari',
    'Hingga 3 outlet',
    'Laporan lengkap (rentang 1 tahun)',
    'Shift kasir & transfer stok antar outlet',
];
</script>

<template>
    <!-- pricing start -->
    <section class="pricing pos-rel pt-125" id="harga">
        <div class="mxw-1330 container">
            <div class="fs-pricing-wrapper">
                <div class="sec-title plr-20 mb-65 text-center">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Paket Sederhana untuk Setiap Tahap</h2>
                </div>
                <div class="fs-pricing-inner pos-rel plr-20 z-1 pb-130" data-bg-color="#d4ceff">
                    <div class="btns_group fs-btns_group xb-corner-shape mb-70 text-center">
                        <button class="pricing_toggle_btn" type="button">
                            <span>Bulanan</span>
                            <span class="switch_btn"></span>
                            <span>Tahunan</span>
                        </button>
                    </div>
                    <div class="fs-pricing-wrap">
                        <div class="row mt-none-30">
                            <div class="col-lg-6 mt-30">
                                <div class="fs-pricing-item">
                                    <div class="xb-item--holder">
                                        <div class="xb-item--top ul_li_between">
                                            <div class="xb-item--content">
                                                <div class="xb-iem--icon">
                                                    <img
                                                        width="73"
                                                        height="67"
                                                        :src="`${base}/img/pricing/icon01.png`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                    />
                                                </div>
                                                <h3 class="xb-item--title lp-fs-h2">Paket Starter</h3>
                                                <p class="xb-item--text">Gratis untuk coba dan toko kecil.</p>
                                            </div>
                                            <div class="xb-item--price_box">
                                                <div class="xb-item--price lp-h3">Gratis</div>
                                                <span class="xb-item--text">Selamanya</span>
                                            </div>
                                        </div>
                                        <div class="xb-item--btn">
                                            <Link class="thm-btn fintech-btn" href="/register">Mulai gratis</Link>
                                        </div>
                                    </div>
                                    <div class="xb-item--list_box">
                                        <div class="xb-item--list_title lp-h3">Fitur yang Disertakan</div>
                                        <ul class="xb-item--list list-unstyled">
                                            <li v-for="feature in starterFeatures" :key="feature">
                                                <img
                                                    width="16"
                                                    height="16"
                                                    :src="`${base}/img/icon/check-primary04.svg`"
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                />
                                                {{ feature }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6 mt-30">
                                <div class="fs-pricing-item pricing_block">
                                    <div class="xb-item--holder">
                                        <div class="xb-item--top pos-rel ul_li_between">
                                            <div class="xb-item--content">
                                                <div class="xb-iem--icon">
                                                    <img
                                                        width="86"
                                                        height="67"
                                                        :src="`${base}/img/pricing/icon02.png`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                    />
                                                </div>
                                                <h3 class="xb-item--title lp-fs-h2">Paket GrowUp</h3>
                                                <p class="xb-item--text">
                                                    Untuk pertumbuhan outlet: operasional penuh, laporan mendalam, multi-outlet.
                                                </p>
                                            </div>
                                            <div class="xb-item--price_box pricing_amount_monthly">
                                                <div class="xb-item--price lp-h3">Rp99 rb</div>
                                                <span class="xb-item--text">Per bulan</span>
                                            </div>
                                            <div class="xb-item--price_box pricing_amount_yearly">
                                                <div class="xb-item--price lp-h3">Rp990 rb</div>
                                                <span class="xb-item--text">Per tahun</span>
                                            </div>
                                            <div class="xb-pricing-scribble">
                                                <p class="text text-center">
                                                    Hemat 10% <br />
                                                    Tahunan
                                                </p>
                                                <svg
                                                    class="line-svg"
                                                    width="111"
                                                    height="64"
                                                    viewBox="0 0 111 64"
                                                    fill="none"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                >
                                                    <path
                                                        d="M8.8039 19.7373L3.32089 37.2569C7.43141 36.5551 15.8304 36.9538 16.5423 44.1631"
                                                        stroke="#533AFD"
                                                        stroke-width="1.5"
                                                    />
                                                    <path
                                                        d="M102.755 43.5608C111.755 28.0607 111.058 39.5834 102.754 33.4017C90.3729 24.1847 76.562 19.0258 74.102 26.7036C71.6421 34.3814 52.724 60.5095 31.1272 33.4018C19.2395 23.1459 13.4 26.9406 4.68537 36.4566"
                                                        stroke="#533AFD"
                                                        stroke-dasharray="4 4"
                                                    />
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="xb-item--btn">
                                            <Link class="thm-btn fintech-btn" href="/subscription">Pilih paket ini</Link>
                                        </div>
                                    </div>
                                    <div class="xb-item--list_box">
                                        <div class="xb-item--list_title lp-h3">Fitur yang Disertakan</div>
                                        <ul class="xb-item--list list-unstyled">
                                            <li v-for="feature in growUpFeatures" :key="feature">
                                                <img
                                                    width="16"
                                                    height="16"
                                                    :src="`${base}/img/icon/check-primary04.svg`"
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                />
                                                {{ feature }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="fs-pricing-bottom text-center">
                            <p class="text">Dapatkan paket khusus untuk volume tinggi atau kebutuhan bisnis unik.</p>
                            <div class="fs-pricing-btn wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                                <Link class="thm-btn fintech-btn" href="/subscription">Hubungi tim sales</Link>
                            </div>
                        </div>
                    </div>
                    <div class="xb-section-shape">
                        <div class="shape shape--1">
                            <img width="176" height="337" :src="`${base}/img/shape/fea-shape01.png`" alt="" loading="lazy" decoding="async" />
                        </div>
                        <div class="shape shape--2">
                            <img width="176" height="337" :src="`${base}/img/shape/fea-shape02.png`" alt="" loading="lazy" decoding="async" />
                        </div>
                        <div class="shape shape--3">
                            <img width="236" height="264" :src="`${base}/img/shape/fea-shape03.png`" alt="" loading="lazy" decoding="async" />
                        </div>
                        <div class="shape shape--4">
                            <img width="236" height="264" :src="`${base}/img/shape/fea-shape04.png`" alt="" loading="lazy" decoding="async" />
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
    <!-- pricing end -->
</template>
