<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

type DashboardTab = {
    id: string;
    label: string;
    icon: string;
    src: string;
    active: boolean;
};

const tabs = computed<DashboardTab[]>(() => [
    {
        id: 'dashboard',
        label: 'Kontrol Produk',
        icon: 'das_icon01.svg',
        src: '/kontrol-produk.webp',
        active: true,
    },
    {
        id: 'document',
        label: 'Manajemen Stok',
        icon: 'das_icon02.svg',
        src: '/produk.webp',
        active: false,
    },
    {
        id: 'sheet',
        label: 'Laporan Penjualan',
        icon: 'das_icon03.svg',
        src: '/laporan-keuangan.webp',
        active: false,
    },
    {
        id: 'whiteboard',
        label: 'Multi-Cabang',
        icon: 'das_icon04.svg',
        src: '/multi%20cabang.webp',
        active: false,
    },
]);

/**
 * Gambar tab aktif (default) adalah kandidat LCP — tidak perlu
 * loading="lazy"; atribut width/height (rasio 16:10) di bawah
 * mencegah layout shift saat gambar dimuat.
 */
</script>

<template>
    <!-- dashboard start -->
    <section class="dashboard" id="fitur">
        <div class="container mxw-1330">
            <div class="fs-dashboard-wrapper xbFadeUpItem" data-animation-delay="300ms">
                <ul class="fs-dashboard-nav xb-corner-shape ul_li nav nav-pills" id="pills-tab" role="tablist">
                    <li class="nav-item" role="presentation" v-for="tab in tabs" :key="tab.id">
                        <button
                            class="nav-link"
                            :class="{ active: tab.active }"
                            :id="`pills-${tab.id}-tab`"
                            data-bs-toggle="pill"
                            :data-bs-target="`#pills-${tab.id}`"
                            type="button"
                            role="tab"
                            :aria-controls="`pills-${tab.id}`"
                            :aria-selected="tab.active ? 'true' : 'false'"
                        >
                            <img :src="`${base}/img/icon/${tab.icon}`" alt="icon" loading="lazy" decoding="async"> {{ tab.label }}
                        </button>
                    </li>
                </ul>
                <div class="tab-content fs-dashboard-img-wrap" id="pills-tabContent">
                    <div
                        v-for="tab in tabs"
                        :key="tab.id"
                        class="tab-pane fade"
                        :class="{ 'show active': tab.active }"
                        :id="`pills-${tab.id}`"
                        role="tabpanel"
                        :aria-labelledby="`pills-${tab.id}-tab`"
                    >
                        <div>
                            <img
                                :src="tab.src"
                                alt="image"
                                :fetchpriority="tab.active ? 'high' : undefined"
                                :loading="tab.active ? undefined : 'lazy'"
                                decoding="async"
                                width="1280"
                                height="800"
                                style="max-width: 100%; height: auto;"
                            >
                        </div>
                    </div>
                </div>
                <div class="xb-section-shape dashboard-shape">
                    <div class="shape shape--1"><img :src="`${base}/img/shape/das_shape03.png`" alt="" loading="lazy" decoding="async"></div>
                    <div class="shape shape--2"><img :src="`${base}/img/shape/das_shape04.png`" alt="" loading="lazy" decoding="async"></div>
                    <div class="shape shape--3"><img :src="`${base}/img/shape/das_shape01.png`" alt="" loading="lazy" decoding="async"></div>
                    <div class="shape shape--4"><img :src="`${base}/img/shape/das_shape02.png`" alt="" loading="lazy" decoding="async"></div>
                    <div class="shape shape--5"><img :src="`${base}/img/shape/circle-blur.png`" alt="" loading="lazy" decoding="async"></div>
                </div>
            </div>
        </div>
    </section>
    <!-- dashboard end -->

    <!-- solution start -->
    <section class="solution" id="manfaat">
        <div class="container mxw-1330">
            <div class="fs-solution-wrapper plr-20">
                <div class="sec-title text-center mb-65" style="padding-top: 30px;">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms"
                        data-wow-duration="600ms">Masalah Anda, Solusi Kami</h2>
                </div>
                <div class="row mt-none-30">
                    <div class="col-lg-6 mt-30">
                        <div class="fs-solution-item wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                            <h2 class="xb-title">Masalahnya</h2>
                            <p class="xb-content">Mengelola penjualan, stok, dan laporan secara manual menyebabkan kesalahan hitung, keterlambatan, dan biaya tersembunyi yang membuat operasional lebih lambat dan sulit dikendalikan.</p>
                            <div class="fs-solution-chart_box">
                                <div class="xb-item--holder">
                                    <div class="xb-item--chart_img">
                                        <img :src="`${base}/img/shape/chart-circle.png`" alt="" loading="lazy" decoding="async">
                                    </div>
                                    <p class="xb-item--content">Total Transaksi</p>
                                    <h2 class="xb-item--number xb-odm">
                                        <span class="suffix">Rp</span><span class="xbo" data-count="12,450">00</span>
                                    </h2>
                                    <ul class="xb-item--meta ul_li">
                                        <li>Rata-rata waktu: <span> <i class="fa-solid fa-angle-left"></i>3m </span> </li>
                                        <li>Tingkat sukses: <span>49.9%</span></li>
                                    </ul>
                                </div>
                                <div class="fs-tag-list">
                                    <div class="xb-item--warning_img">
                                        <img :src="`${base}/img/shape/warning.png`" alt="" loading="lazy" decoding="async">
                                    </div>
                                    <div class="xb-item--list_box">
                                        <ul class="xb-item--list ul_li_between">
                                            <li><span><i class="fa-solid fa-circle-exclamation"></i></span>Kontrol Terbatas</li>
                                            <li><span><i class="fa-solid fa-triangle-exclamation"></i></span>Biaya Tersembunyi</li>
                                        </ul>
                                        <ul class="xb-item--list ul_li_between justify-content-center">
                                            <li><span><i class="fa-solid fa-triangle-exclamation"></i></span>Integrasi Lambat</li>
                                        </ul>
                                        <ul class="xb-item--list ul_li_between">
                                            <li><span><i class="fa-solid fa-circle-exclamation"></i></span>Sistem Terpisah</li>
                                            <li><span><i class="fa-solid fa-circle-exclamation"></i></span>Tidak Standar</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="xb-item--shape">
                                    <div class="shape shape--1">
                                        <img :src="`${base}/img/shape/solution_shape01.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--2">
                                        <img :src="`${base}/img/shape/solution_shape02.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--3">
                                        <img :src="`${base}/img/shape/solution_shape03.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--4">
                                        <img :src="`${base}/img/shape/solution_shape04.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--5">
                                        <img :src="`${base}/img/shape/circle-blur02.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--6">
                                        <img :src="`${base}/img/shape/circle-blur02.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mt-30">
                        <div class="fs-solution-item wow fadeInUp" data-wow-delay="150ms" data-wow-duration="600ms">
                            <h2 class="xb-title">Solusinya</h2>
                            <p class="xb-content">UMKM Kasir memberi Anda satu platform untuk mengelola produk, stok, transaksi, dan laporan — semua dalam satu tempat.</p>
                            <div class="fs-solution-chart_box fa-chart-two">
                                <div class="xb-item--holder">
                                    <div class="xb-item--chart_img">
                                        <img :src="`${base}/img/shape/chart-circle02.png`" alt="" loading="lazy" decoding="async">
                                    </div>
                                    <p class="xb-item--content">Total Transaksi</p>
                                    <h2 class="xb-item--number xb-odm">
                                        <span class="suffix">Rp</span><span class="xbo" data-count="12,550">00</span>
                                    </h2>
                                    <ul class="xb-item--meta ul_li">
                                        <li>Rata-rata waktu: <span> <i class="fa-solid fa-angle-left"></i>3d </span> </li>
                                        <li>Tingkat sukses: <span>99.9%</span></li>
                                    </ul>
                                </div>
                                <div class="fs-tag-list">
                                    <div class="xb-item--list_box ul_li">
                                        <ul class="xb-item--list ul_li">
                                            <li><span><img :src="`${base}/img/icon/sol-icon01.svg`" alt="icon" loading="lazy" decoding="async"></span>Platform All-in-One</li>
                                            <li><span><img :src="`${base}/img/icon/sol-icon02.svg`" alt="icon" loading="lazy" decoding="async"></span>Transparansi Biaya</li>
                                            <li><span><img :src="`${base}/img/icon/sol-icon03.svg`" alt="icon" loading="lazy" decoding="async"></span>Integrasi Cepat</li>
                                            <li><span><img :src="`${base}/img/icon/sol-icon04.svg`" alt="icon" loading="lazy" decoding="async"></span>Sistem Sederhana</li>
                                        </ul>
                                        <ul class="xb-item--list ul_li">
                                            <li><span><img :src="`${base}/img/icon/sol-icon05.svg`" alt="icon" loading="lazy" decoding="async"></span>Dasbor Kontrol Penuh</li>
                                            <li class="logo-img"><img src="/cecklist.webp" alt="" loading="lazy" decoding="async"></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="xb-item--shape">
                                    <div class="shape shape--1">
                                        <img :src="`${base}/img/shape/solution_shape05.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--2">
                                        <img :src="`${base}/img/shape/solution_shape06.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--3">
                                        <img :src="`${base}/img/shape/solution_shape07.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--4">
                                        <img :src="`${base}/img/shape/solution_shape08.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--5">
                                        <img :src="`${base}/img/shape/circle-blur03.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                    <div class="shape shape--6">
                                        <img :src="`${base}/img/shape/circle-blur03.png`" alt="shape" loading="lazy" decoding="async">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- solution end -->
</template>

<style>
/* Fix konflik Tailwind v4 preflight dengan template Sastik pada solution section */

/* Gambar chart-circle di bawah teks */
.fs-solution-chart_box .xb-item--chart_img {
    z-index: 0;
    pointer-events: none;
}
.fs-solution-chart_box .xb-item--chart_img img {
    max-width: none;
    width: 100%;
    height: auto;
    display: block;
}

/* Teks di atas gambar */
.fs-solution-chart_box .xb-item--holder {
    position: relative;
    z-index: 2;
    text-align: center;
    padding: 72px 0 20px;
    margin-bottom: 42px;
}
.fs-solution-chart_box .xb-item--holder .xb-item--content,
.fs-solution-chart_box .xb-item--holder .xb-item--number,
.fs-solution-chart_box .xb-item--holder .xb-item--meta {
    position: relative;
    z-index: 2;
}

/* Tag list di atas shape */
.fs-tag-list {
    text-align: center;
    position: relative;
    z-index: 2;
}
.fs-tag-list .xb-item--list_box {
    position: relative;
    z-index: 2;
}

/* Heading & ukuran font Sastik */
.fs-solution-item .xb-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 5px;
}
.fs-solution-chart_box .xb-item--number {
    font-size: 35px;
    font-weight: 700;
    margin-bottom: 35px;
    letter-spacing: -0.04em;
}
.fs-solution-chart_box .xb-item--content {
    font-size: 13px;
}

/* Flex layout ul_li Sastik */
.ul_li,
.ul_li_right,
.ul_li_center,
.ul_li_between {
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    margin: 0;
    padding: 0;
}
.ul_li > li,
.ul_li_right > li,
.ul_li_center > li,
.ul_li_between > li {
    list-style: none;
    float: left;
    display: inline-block;
}
.ul_li {
    justify-content: flex-start;
}
.ul_li_center {
    justify-content: center;
}
.ul_li_right {
    justify-content: flex-end;
}
.ul_li_between {
    justify-content: space-between;
}

/* Card sejajar — pastikan kedua col-lg-6 punya height sama */
.solution .row.mt-none-30 {
    align-items: stretch;
}
.solution .col-lg-6.mt-30 {
    display: flex;
}
.solution .fs-solution-item {
    height: 100%;
    display: flex;
    flex-direction: column;
}
.solution .fs-solution-chart_box {
    flex: 1;
}


</style>
