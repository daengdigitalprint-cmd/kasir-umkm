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
    /** Lebar ikon SVG (persegi). */
    iconSize: number;
    src: string;
    /** Versi 800px untuk layar kecil (scripts/optimize-landing-images.py). */
    src800: string;
    /** Lebar asli `src` (untuk srcset). */
    srcWidth: number;
    active: boolean;
};

const tabs = computed<DashboardTab[]>(() => [
    {
        id: 'dashboard',
        label: 'Kontrol Produk',
        icon: 'das_icon01.svg',
        iconSize: 18,
        src: '/kontrol-produk.webp',
        src800: '/kontrol-produk-800.webp',
        srcWidth: 1550,
        active: true,
    },
    {
        id: 'document',
        label: 'Manajemen Stok',
        icon: 'das_icon02.svg',
        iconSize: 20,
        src: '/produk.webp',
        src800: '/produk-800.webp',
        srcWidth: 1660,
        active: false,
    },
    {
        id: 'sheet',
        label: 'Laporan Penjualan',
        icon: 'das_icon03.svg',
        iconSize: 18,
        src: '/laporan-keuangan.webp',
        src800: '/laporan-keuangan-800.webp',
        srcWidth: 1872,
        active: false,
    },
    {
        id: 'whiteboard',
        label: 'Multi-Cabang',
        icon: 'das_icon04.svg',
        iconSize: 20,
        src: '/multi%20cabang.webp',
        src800: '/multi-cabang-800.webp',
        srcWidth: 1616,
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
        <div class="mxw-1330 container">
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
                            <img
                                :src="`${base}/img/icon/${tab.icon}`"
                                :width="tab.iconSize"
                                :height="tab.iconSize"
                                alt=""
                                loading="lazy"
                                decoding="async"
                            />
                            {{ tab.label }}
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
                                :srcset="`${tab.src800} 800w, ${tab.src} ${tab.srcWidth}w`"
                                sizes="(max-width: 991px) 100vw, 851px"
                                :alt="`Tampilan fitur ${tab.label} di aplikasi kasir UMKM Kasir`"
                                :fetchpriority="tab.active ? 'high' : undefined"
                                :loading="tab.active ? undefined : 'lazy'"
                                decoding="async"
                                width="1280"
                                height="800"
                                style="max-width: 100%; height: auto"
                            />
                        </div>
                    </div>
                </div>
                <div class="xb-section-shape dashboard-shape">
                    <div class="shape shape--1">
                        <img width="195" height="337" :src="`${base}/img/shape/das_shape03.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                    <div class="shape shape--2">
                        <img width="195" height="337" :src="`${base}/img/shape/das_shape04.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                    <div class="shape shape--3">
                        <img width="255" height="264" :src="`${base}/img/shape/das_shape01.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                    <div class="shape shape--4">
                        <img width="255" height="264" :src="`${base}/img/shape/das_shape02.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                    <div class="shape shape--5">
                        <img width="1328" height="388" :src="`${base}/img/shape/circle-blur.webp`" alt="" loading="lazy" decoding="async" />
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- dashboard end -->

    <!-- solution start -->
    <section class="solution" id="manfaat">
        <div class="mxw-1330 container">
            <div class="fs-solution-wrapper plr-20">
                <div class="sec-title mb-65 text-center" style="padding-top: 30px">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Kasir Manual vs Aplikasi Kasir Online</h2>
                </div>
                <div class="row mt-none-30">
                    <div class="col-lg-6 mt-30">
                        <div class="fs-solution-item wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                            <h3 class="xb-title lp-fs-h2">Masalah Kasir Manual</h3>
                            <p class="xb-content">
                                Mencatat penjualan di buku dan menghitung stok dengan tangan rawan salah hitung, selisih kas, dan laporan yang
                                terlambat, sehingga Anda sulit tahu untung-rugi usaha yang sebenarnya.
                            </p>
                            <div class="fs-solution-chart_box">
                                <div class="xb-item--holder">
                                    <div class="xb-item--chart_img">
                                        <img
                                            width="332"
                                            height="169"
                                            :src="`${base}/img/shape/chart-circle.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <p class="xb-item--content">Waktu rekap harian</p>
                                    <div class="xb-item--number lp-h2">± 2 jam</div>
                                    <ul class="xb-item--meta ul_li">
                                        <li>Hitung ulang: <span>manual</span></li>
                                        <li>Risiko selisih: <span>tinggi</span></li>
                                    </ul>
                                </div>
                                <div class="fs-tag-list">
                                    <div class="xb-item--warning_img">
                                        <img width="144" height="160" :src="`${base}/img/shape/warning.png`" alt="" loading="lazy" decoding="async" />
                                    </div>
                                    <div class="xb-item--list_box">
                                        <ul class="xb-item--list ul_li_between">
                                            <li>
                                                <span><i class="fa-solid fa-circle-exclamation"></i></span>Salah Hitung
                                            </li>
                                            <li>
                                                <span><i class="fa-solid fa-triangle-exclamation"></i></span>Stok Tidak Sinkron
                                            </li>
                                        </ul>
                                        <ul class="xb-item--list ul_li_between justify-content-center">
                                            <li>
                                                <span><i class="fa-solid fa-triangle-exclamation"></i></span>Laporan Terlambat
                                            </li>
                                        </ul>
                                        <ul class="xb-item--list ul_li_between">
                                            <li>
                                                <span><i class="fa-solid fa-circle-exclamation"></i></span>Catatan Tercecer
                                            </li>
                                            <li>
                                                <span><i class="fa-solid fa-circle-exclamation"></i></span>Selisih Kas
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="xb-item--shape">
                                    <div class="shape shape--1">
                                        <img
                                            width="195"
                                            height="337"
                                            :src="`${base}/img/shape/solution_shape01.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--2">
                                        <img
                                            width="195"
                                            height="337"
                                            :src="`${base}/img/shape/solution_shape02.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--3">
                                        <img
                                            width="264"
                                            height="299"
                                            :src="`${base}/img/shape/solution_shape03.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--4">
                                        <img
                                            width="264"
                                            height="299"
                                            :src="`${base}/img/shape/solution_shape04.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--5">
                                        <img
                                            width="204"
                                            height="370"
                                            :src="`${base}/img/shape/circle-blur02.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--6">
                                        <img
                                            width="204"
                                            height="370"
                                            :src="`${base}/img/shape/circle-blur02.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mt-30">
                        <div class="fs-solution-item wow fadeInUp" data-wow-delay="150ms" data-wow-duration="600ms">
                            <h3 class="xb-title lp-fs-h2">Solusi dengan UMKM Kasir</h3>
                            <p class="xb-content">
                                UMKM Kasir mencatat setiap transaksi, mengurangi stok otomatis, dan menyusun laporan penjualan untuk Anda, semua dalam satu aplikasi.
                            </p>
                            <div class="fs-solution-chart_box fa-chart-two">
                                <div class="xb-item--holder">
                                    <div class="xb-item--chart_img">
                                        <img
                                            width="332"
                                            height="169"
                                            :src="`${base}/img/shape/chart-circle02.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <p class="xb-item--content">Waktu rekap harian</p>
                                    <div class="xb-item--number lp-h2">Otomatis</div>
                                    <ul class="xb-item--meta ul_li">
                                        <li>Laporan: <span>real-time</span></li>
                                        <li>Data: <span>tersimpan di cloud</span></li>
                                    </ul>
                                </div>
                                <div class="fs-tag-list">
                                    <div class="xb-item--list_box ul_li">
                                        <ul class="xb-item--list ul_li">
                                            <li>
                                                <span
                                                    ><img
                                                        width="24"
                                                        height="24"
                                                        :src="`${base}/img/icon/sol-icon01.svg`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async" /></span
                                                >Satu Aplikasi
                                            </li>
                                            <li>
                                                <span
                                                    ><img
                                                        width="24"
                                                        height="24"
                                                        :src="`${base}/img/icon/sol-icon02.svg`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async" /></span
                                                >Harga Transparan
                                            </li>
                                            <li>
                                                <span
                                                    ><img
                                                        width="24"
                                                        height="24"
                                                        :src="`${base}/img/icon/sol-icon03.svg`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async" /></span
                                                >Stok Real-time
                                            </li>
                                            <li>
                                                <span
                                                    ><img
                                                        width="24"
                                                        height="24"
                                                        :src="`${base}/img/icon/sol-icon04.svg`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async" /></span
                                                >Mudah Dipakai
                                            </li>
                                        </ul>
                                        <ul class="xb-item--list ul_li">
                                            <li>
                                                <span
                                                    ><img
                                                        width="24"
                                                        height="24"
                                                        :src="`${base}/img/icon/sol-icon05.svg`"
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async" /></span
                                                >Laporan Otomatis
                                            </li>
                                            <li class="logo-img">
                                                <img width="1024" height="1024" src="/cecklist-sm.webp" alt="" loading="lazy" decoding="async" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="xb-item--shape">
                                    <div class="shape shape--1">
                                        <img
                                            width="195"
                                            height="337"
                                            :src="`${base}/img/shape/solution_shape05.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--2">
                                        <img
                                            width="195"
                                            height="337"
                                            :src="`${base}/img/shape/solution_shape06.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--3">
                                        <img
                                            width="264"
                                            height="299"
                                            :src="`${base}/img/shape/solution_shape07.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--4">
                                        <img
                                            width="264"
                                            height="300"
                                            :src="`${base}/img/shape/solution_shape08.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--5">
                                        <img
                                            width="204"
                                            height="370"
                                            :src="`${base}/img/shape/circle-blur03.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="shape shape--6">
                                        <img
                                            width="204"
                                            height="370"
                                            :src="`${base}/img/shape/circle-blur03.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
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
