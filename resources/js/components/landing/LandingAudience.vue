<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/sastik-assets');
</script>

<template>
    <!-- feature start -->
    <section class="feature border-btm pos-rel pt-55 pb-130" id="fitur">
        <div class="container mxw-1330">
            <div class="fs-feature-wrapper plr-20">
                <div class="sec-title text-center mb-65">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms"
                        data-wow-duration="600ms">Fitur Pintar untuk UMKM Anda</h2>
                </div>
                <div class="row mt-none-30">
                    <div class="col-xl-8 col-lg-12 mt-30">
                        <div class="fs-feature-item large-item">
                            <div class="xb-item--top mb-35">
                                <h2 class="xb-item--title">Pencatatan Otomatis & Akurat</h2>
                                <p class="xb-item--content">Setiap transaksi tercatat otomatis tanpa input manual berulang. Kurangi kesalahan hitung dan hemat waktu berjam-jam setiap minggunya.</p>
                            </div>
                            <div class="xb-item--img">
                                <div class="img-1">
                                    <img src="/Pencatatan.webp" alt="Pencatatan Otomatis" loading="lazy" decoding="async">
                                </div>
                            </div>
                            <div class="xb-item--shape_box">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/dot-shape.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--3"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_green">
                            <div class="xb-item--top  mb-35">
                                <h2 class="xb-item--title">Manajemen Stok yang Mudah</h2>
                                <p class="xb-item--content">Pantau stok produk secara real-time dan dapatkan notifikasi saat barang hampir habis. Hindari kehabisan stok di jam sibuk.</p>
                            </div>
                            <div class="xb-item--marquee_wrap">
                                <div class="xb-item--marquee_inner ul_li_between">
                                    <div class="xb-item--marquee marquee-first">
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo01.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo02.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo03.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo04.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo01.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo02.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo03.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo04.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                    </div>
                                    <div class="xb-item--card"><img src="/card%20produk.webp" alt="Card Manajemen Stok" loading="lazy" decoding="async"></div>
                                    <div class="xb-item--marquee marquee-2">
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo05.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo06.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo07.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo08.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo05.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo06.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo07.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                        <div class="xb-logo"><img :src="`${assetsBase}/img/feature/logo08.jpg`" alt="logo" loading="lazy" decoding="async"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="xb-item--logo">
                                <div class="xb-circle">
                                    <div class="xb-logo">
                                        <img :src="`${assetsBase}/img/icon/logo-icon.svg`" alt="logo" loading="lazy" decoding="async">
                                    </div>
                                    <div class="round-shape"><img :src="`${assetsBase}/img/shape/round-shape.png`" alt="" loading="lazy" decoding="async"></div>
                                </div>
                                <div class="shape"><img :src="`${assetsBase}/img/shape/round-shape02.png`" alt="" loading="lazy" decoding="async"></div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/blur-shape.png`" alt="" loading="lazy" decoding="async">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_cream">
                            <div class="xb-item--top mb-45">
                                <h2 class="xb-item--title">Cetak Struk Otomatis</h2>
                                <p class="xb-item--content">Struk otomatis tercetak atau terkirim ke pelanggan tanpa proses tambahan.</p>
                            </div>
                            <div class="xb-item--dashboard_img">
                                <div class="img img--one"><img src="/struk.png" alt="struk" loading="lazy" decoding="async"></div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/fea_shape01.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--2 updown">
                                    <img :src="`${assetsBase}/img/shape/arrow_shape.png`" alt="" loading="lazy" decoding="async">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_pink">
                            <div class="xb-item--top mb-45">
                                <h2 class="xb-item--title">Laporan Pendapatan</h2>
                                <p class="xb-item--content">Pantau pendapatan bulanan dengan grafik yang mudah dipahami untuk keputusan lebih tepat.</p>
                            </div>
                            <div class="xb-item--review_container wow">
                                <div class="xb-item--review_box">
                                    <h2 class="xb-title">Revenue Overview</h2>
                                    <div class="xb-chart">
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Jan</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Feb</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Mar</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Apr</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>May</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Jun</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Jul</p>
                                        </div>
                                        <div class="chart-item">
                                            <span></span>
                                            <p>Aug</p>
                                        </div>
                                    </div>
                                    <div class="xb-item--price_box ul_li">
                                        <h3 class="xb-price"><sup>Rp</sup>3.286</h3>
                                        <span class="xb-discount"><i class="fa-regular fa-arrow-up"></i> 125%</span>
                                    </div>
                                    <p class="xb-item--text">Pendapatan <span>naik +Rp427</span> dibanding bulan lalu.</p>
                                </div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/fea_shape02.png`" alt="" loading="lazy" decoding="async">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item Pale_green">
                            <div class="xb-item--top mb-45">
                                <h2 class="xb-item--title">Multi-Cabang & Multi-Pengguna</h2>
                                <p class="xb-item--content">Kelola beberapa cabang dari satu dasbor terpadu. Atur peran kasir, manajer, dan owner dengan hak akses berbeda.</p>
                            </div>
                            <div class="xb-item--img">
                                <img src="/Multi-Cabang.png" alt="Multi-Cabang & Multi-Pengguna" loading="lazy" decoding="async" style="max-width: 80%; height: auto; margin: 0 auto; display: block;">
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/fea_shape03.png`" alt="" loading="lazy" decoding="async">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-12  mt-30">
                        <div class="fs-feature-item large-bottom-item" style="overflow: visible !important; min-height: 370px;">
                            <div class="xb-item--top mb-35">
                                <h2 class="xb-item--title">Akses dari Mana Saja</h2>
                                <p class="xb-item--content">Pantau bisnis dari HP, tablet, atau laptop melalui browser. Tidak perlu install aplikasi tambahan — cukup login dan langsung kelola usaha di mana pun berada.</p>
                            </div>

                            <div class="xb-item--btn_box">
                                <img src="/mockup-akses.png" alt="Mockup Akses" loading="lazy" decoding="async" style="max-width: 70%; height: auto; display: block; margin-left: auto;">
                            </div>
                            <div class="xb-section-shape">
                                <div class="shape shape--1">
                                    <img :src="`${assetsBase}/img/shape/fea-shape01.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--2">
                                    <img :src="`${assetsBase}/img/shape/fea-shape02.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--3">
                                    <img :src="`${assetsBase}/img/shape/fea-shape03.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--4">
                                    <img :src="`${assetsBase}/img/shape/fea-shape04.png`" alt="" loading="lazy" decoding="async">
                                </div>
                                <div class="shape shape--5"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fs-feature-btn gap-10 mt-60 ul_li justify-content-center wow fadeInUp" data-wow-delay="0ms"
                        data-wow-duration="600ms">
                    <Link class="thm-btn fintech-btn" href="/register">Mulai gratis</Link>
                    <Link class="thm-btn fintech-btn fintech-btn--white" href="/panduan">Lihat panduan</Link>
                </div>
            </div>
        </div>
        <div class="xb-dot-box">
            <span></span>
            <span></span>
        </div>
    </section>
    <!-- feature end -->

    <!-- security end -->

    <!-- process start -->
    <section class="process pt-125">
        <div class="container mxw-1330">
            <div class="fs-process-wrapper plr-20">
                <div class="sec-title text-center mb-65">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms"
                        data-wow-duration="600ms">Alur Operasional yang Efisien</h2>
                </div>
                <div class="fs-process-wrap">
                    <div class="row mt-none-30">
                        <div class="col-lg-6 mt-30">
                            <div class="fs-process-container">
                                <div class="fs-process-item active">
                                    <div class="xb-item--icon">
                                        <img :src="`${assetsBase}/img/icon/process-icon01.svg`" alt="icon" loading="lazy" decoding="async">
                                    </div>
                                    <h2 class="xb-item--title">01. Catat & Kelola Transaksi.</h2>
                                    <p class="xb-item--content">Catat setiap transaksi penjualan dan kelola semuanya dalam satu tempat.</p>
                                </div>
                                <div class="fs-process-item">
                                    <div class="xb-item--icon">
                                        <img :src="`${assetsBase}/img/icon/process-icon02.svg`" alt="icon" loading="lazy" decoding="async">
                                    </div>
                                    <h2 class="xb-item--title">02. Pengalaman Kasir yang Mudah.</h2>
                                    <p class="xb-item--content">Proses checkout cepat dan aman yang membangun kepercayaan pelanggan.</p>
                                </div>
                                <div class="fs-process-item">
                                    <div class="xb-item--icon">
                                        <img :src="`${assetsBase}/img/icon/process-icon03.svg`" alt="icon" loading="lazy" decoding="async">
                                    </div>
                                    <h2 class="xb-item--title">03. Otomatisasi Operasional & Skala.</h2>
                                    <p class="xb-item--content">Otomatisasi alur kerja untuk mengembangkan bisnis Anda lebih cepat.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mt-30">
                            <div class="fs-process-image-wrap">
                                <div class="fs-process-image-inner bg_img"
                                :data-background="`${assetsBase}/img/process/bg-img.png`">
                                    <div class="img active"><img :src="`${assetsBase}/img/process/img01.png`" alt="process image" loading="lazy" decoding="async"></div>
                                    <div class="img"><img :src="`${assetsBase}/img/process/img02.png`" alt="process image" loading="lazy" decoding="async"></div>
                                    <div class="img"><img :src="`${assetsBase}/img/process/img03.png`" alt="process image" loading="lazy" decoding="async"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- process end -->
</template>

<style scoped>
@media (max-width: 767px) {
    .process {
        padding-top: 40px !important;
    }
    .large-bottom-item {
        min-height: auto !important;
        overflow: visible;
    }
    .xb-item--img .img-1 {
        display: block !important;
        max-width: 100% !important;
        width: 100%;
        text-align: center;
    }
    .xb-item--img .img-1 img {
        width: 100%;
        height: auto;
        max-width: none;
        display: block;
        margin: 0 auto;
    }
    .xb-item--btn_box {
        text-align: center;
        margin-top: 10px;
    }
    .xb-item--btn_box img {
        max-width: 85%;
        margin: 0 auto;
        display: none !important;
    }
}
</style>
