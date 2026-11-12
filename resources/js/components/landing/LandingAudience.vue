<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/sastik-assets');
</script>

<template>
    <!-- feature start -->
    <section class="feature border-btm pos-rel pt-55 pb-130" id="fitur">
        <div class="mxw-1330 container">
            <div class="fs-feature-wrapper plr-20">
                <div class="sec-title mb-65 text-center">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Fitur Aplikasi Kasir untuk UMKM</h2>
                </div>
                <div class="row mt-none-30">
                    <div class="col-xl-8 col-lg-12 mt-30">
                        <div class="fs-feature-item large-item">
                            <div class="xb-item--top mb-35">
                                <h3 class="xb-item--title lp-fs-h2">Pencatatan Otomatis & Akurat</h3>
                                <p class="xb-item--content">
                                    Setiap transaksi tercatat otomatis tanpa input manual berulang. Kurangi kesalahan hitung dan hemat waktu
                                    berjam-jam setiap minggunya.
                                </p>
                            </div>
                            <div class="xb-item--img">
                                <div class="img-1">
                                    <img
                                        width="1682"
                                        height="858"
                                        src="/Pencatatan.webp"
                                        srcset="/Pencatatan-800.webp 800w, /Pencatatan.webp 1682w"
                                        sizes="(max-width: 767px) 100vw, 791px"
                                        alt="Tampilan pencatatan transaksi otomatis di Kasir UMKM by MaccaPOS"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                            <div class="xb-item--shape_box">
                                <div class="shape shape--1">
                                    <img
                                        width="307"
                                        height="409"
                                        :src="`${assetsBase}/img/shape/dot-shape.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--3"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_green">
                            <div class="xb-item--top mb-35">
                                <h3 class="xb-item--title lp-fs-h2">Manajemen Stok yang Mudah</h3>
                                <p class="xb-item--content">
                                    Pantau stok produk secara real-time dan dapatkan notifikasi saat barang hampir habis. Hindari kehabisan stok di
                                    jam sibuk.
                                </p>
                            </div>
                            <div class="xb-item--marquee_wrap">
                                <div class="xb-item--marquee_inner ul_li_between">
                                    <div class="xb-item--marquee marquee-first">
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo01.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo02.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo03.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo04.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo01.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo02.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo03.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo04.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                    </div>
                                    <div class="xb-item--card">
                                        <img
                                            width="1617"
                                            height="973"
                                            src="/card-produk-sm.webp"
                                            alt="Kartu ringkasan manajemen stok produk"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="xb-item--marquee marquee-2">
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo05.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo06.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo07.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo08.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo05.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo06.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo07.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                        <div class="xb-logo">
                                            <img
                                                width="90"
                                                height="90"
                                                :src="`${assetsBase}/img/feature/logo08.jpg`"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="xb-item--logo">
                                <div class="xb-circle">
                                    <div class="xb-logo">
                                        <img
                                            width="38"
                                            height="35"
                                            :src="`${assetsBase}/img/icon/logo-icon.svg`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="round-shape">
                                        <img
                                            width="90"
                                            height="90"
                                            :src="`${assetsBase}/img/shape/round-shape.png`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                </div>
                                <div class="shape">
                                    <img
                                        width="317"
                                        height="92"
                                        :src="`${assetsBase}/img/shape/round-shape02.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img
                                        width="410"
                                        height="262"
                                        :src="`${assetsBase}/img/shape/blur-shape.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_cream">
                            <div class="xb-item--top mb-45">
                                <h3 class="xb-item--title lp-fs-h2">Cetak Struk Otomatis</h3>
                                <p class="xb-item--content">Struk tercetak otomatis ke printer thermal atau Bluetooth setiap transaksi selesai, lengkap dengan nama toko Anda.</p>
                            </div>
                            <div class="xb-item--dashboard_img">
                                <div class="img img--one">
                                    <img
                                        width="354"
                                        height="560"
                                        src="/struk-id.webp"
                                        alt="Contoh struk transaksi yang dicetak dari Kasir UMKM by MaccaPOS"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img
                                        width="410"
                                        height="364"
                                        :src="`${assetsBase}/img/shape/fea_shape01.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--2 updown">
                                    <img
                                        width="45"
                                        height="84"
                                        :src="`${assetsBase}/img/shape/arrow_shape.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item light_pink">
                            <div class="xb-item--top mb-45">
                                <h3 class="xb-item--title lp-fs-h2">Laporan Pendapatan</h3>
                                <p class="xb-item--content">
                                    Pantau pendapatan bulanan dengan grafik yang mudah dipahami untuk keputusan lebih tepat.
                                </p>
                            </div>
                            <div class="xb-item--review_container wow">
                                <div class="xb-item--review_box">
                                    <div class="xb-title lp-h2">Ringkasan Pendapatan</div>
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
                                            <p>Mei</p>
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
                                            <p>Agu</p>
                                        </div>
                                    </div>
                                    <div class="xb-item--price_box ul_li">
                                        <div class="xb-price lp-h3"><sup>Rp</sup>32,8 jt</div>
                                        <span class="xb-discount"><i class="fa-regular fa-arrow-up"></i> 12,5%</span>
                                    </div>
                                    <p class="xb-item--text">Contoh: pendapatan <span>naik Rp3,6 jt</span> dibanding bulan lalu.</p>
                                </div>
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img
                                        width="410"
                                        height="242"
                                        :src="`${assetsBase}/img/shape/fea_shape02.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 mt-30">
                        <div class="fs-feature-item Pale_green">
                            <div class="xb-item--top mb-45">
                                <h3 class="xb-item--title lp-fs-h2">Multi-Cabang & Multi-Pengguna</h3>
                                <p class="xb-item--content">
                                    Kelola beberapa cabang dari satu dasbor terpadu. Atur peran kasir, manajer, dan owner dengan hak akses berbeda.
                                </p>
                            </div>
                            <div class="xb-item--img">
                                <img
                                    width="671"
                                    height="1000"
                                    src="/Multi-Cabang-sm.webp"
                                    alt="Kelola banyak cabang dan pengguna dalam satu akun Kasir UMKM by MaccaPOS"
                                    loading="lazy"
                                    decoding="async"
                                    style="max-width: 80%; height: auto; margin: 0 auto; display: block"
                                />
                            </div>
                            <div class="xb-item--shape">
                                <div class="shape shape--1">
                                    <img
                                        width="410"
                                        height="182"
                                        :src="`${assetsBase}/img/shape/fea_shape03.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-12 mt-30">
                        <div class="fs-feature-item large-bottom-item" style="overflow: visible !important; min-height: 370px">
                            <div class="xb-item--top mb-35">
                                <h3 class="xb-item--title lp-fs-h2">Akses dari Mana Saja</h3>
                                <p class="xb-item--content">
                                    Pantau usaha dari HP, tablet, atau laptop lewat browser, atau pakai aplikasi Android-nya. Cukup login, data
                                    penjualan dan stok langsung tersinkron di semua perangkat.
                                </p>
                            </div>

                            <div class="xb-item--btn_box">
                                <img
                                    width="307"
                                    height="474"
                                    src="/mockup-akses.webp"
                                    alt="Kasir UMKM by MaccaPOS dapat diakses dari ponsel, tablet, dan komputer"
                                    loading="lazy"
                                    decoding="async"
                                    style="max-width: 70%; height: auto; display: block; margin-left: auto"
                                />
                            </div>
                            <div class="xb-section-shape">
                                <div class="shape shape--1">
                                    <img
                                        width="176"
                                        height="337"
                                        :src="`${assetsBase}/img/shape/fea-shape01.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--2">
                                    <img
                                        width="176"
                                        height="337"
                                        :src="`${assetsBase}/img/shape/fea-shape02.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--3">
                                    <img
                                        width="236"
                                        height="264"
                                        :src="`${assetsBase}/img/shape/fea-shape03.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--4">
                                    <img
                                        width="236"
                                        height="264"
                                        :src="`${assetsBase}/img/shape/fea-shape04.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div class="shape shape--5"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fs-feature-btn ul_li justify-content-center wow fadeInUp mt-60 gap-10" data-wow-delay="0ms" data-wow-duration="600ms">
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
        <div class="mxw-1330 container">
            <div class="fs-process-wrapper plr-20">
                <div class="sec-title mb-65 text-center">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Cara Kerja Kasir UMKM by MaccaPOS</h2>
                </div>
                <div class="fs-process-wrap">
                    <div class="row mt-none-30">
                        <div class="col-lg-6 mt-30">
                            <div class="fs-process-container">
                                <div class="fs-process-item active">
                                    <div class="xb-item--icon">
                                        <img
                                            width="24"
                                            height="24"
                                            :src="`${assetsBase}/img/icon/process-icon01.svg`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <h3 class="xb-item--title lp-fs-h2">01. Atur Produk & Stok</h3>
                                    <p class="xb-item--content">Masukkan produk, harga, dan stok awal sekali saja, atau impor dari Excel.</p>
                                </div>
                                <div class="fs-process-item">
                                    <div class="xb-item--icon">
                                        <img
                                            width="24"
                                            height="24"
                                            :src="`${assetsBase}/img/icon/process-icon02.svg`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <h3 class="xb-item--title lp-fs-h2">02. Layani Pelanggan di Kasir</h3>
                                    <p class="xb-item--content">Transaksi cepat dengan tunai, QRIS, atau transfer, lalu cetak struk langsung.</p>
                                </div>
                                <div class="fs-process-item">
                                    <div class="xb-item--icon">
                                        <img
                                            width="24"
                                            height="24"
                                            :src="`${assetsBase}/img/icon/process-icon03.svg`"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <h3 class="xb-item--title lp-fs-h2">03. Pantau Laporan Otomatis</h3>
                                    <p class="xb-item--content">Stok berkurang otomatis dan laporan penjualan siap dibaca kapan saja.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mt-30">
                            <div class="fs-process-image-wrap">
                                <div class="fs-process-image-inner bg_img" :data-background="`${assetsBase}/img/process/bg-img.png`">
                                    <div class="img active">
                                        <img
                                            width="406"
                                            height="470"
                                            :src="`${assetsBase}/img/process/langkah-01.webp`"
                                            alt="Ilustrasi langkah 1: mengatur produk dan stok"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="img">
                                        <img
                                            width="387"
                                            height="469"
                                            :src="`${assetsBase}/img/process/langkah-02.webp`"
                                            alt="Ilustrasi langkah 2: melayani pembayaran di kasir"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="img">
                                        <img
                                            width="484"
                                            height="453"
                                            :src="`${assetsBase}/img/process/langkah-03.webp`"
                                            alt="Ilustrasi langkah 3: memantau laporan penjualan otomatis"
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
