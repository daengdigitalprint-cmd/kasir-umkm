<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/sastik-assets');

/**
 * Metode pembayaran yang bisa dicatat di kasir (TransactionPayment::METHODS, DP pre-order/laundry).
 * Sengaja berupa ikon + teks, bukan logo merek: aplikasi mencatat metodenya, tidak terintegrasi
 * langsung dengan GoPay/OVO/bank tertentu. (Sebelumnya logo template luar negeri: iDEAL, Wero, UPI.)
 */
type PayMethod = { icon: string; label: string };
/** Isi <svg viewBox="0 0 24 24"> bergaris. Bukan Font Awesome: landing.min.css hanya memuat sebagian ikon. */
const ICONS = {
    qris: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M14 21h.01M17 21h4v-4"/>',
    cash: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    bank: '<path d="M3 21h18M5 21v-9M9.5 21v-9M14.5 21v-9M19 21v-9M2 9l10-6 10 6z"/>',
    wallet: '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v4h-4a2 2 0 0 0 0 4h4v3a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V5"/>',
    card: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    later: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    split: '<path d="M16 3h5v5M8 3H3v5M12 22v-8.3a4 4 0 0 0-1.2-2.8L3 3M21 3l-7.8 7.8"/>',
    dp: '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
};
const payFirst: PayMethod[] = [
    { icon: ICONS.qris, label: 'QRIS' },
    { icon: ICONS.cash, label: 'Tunai' },
    { icon: ICONS.bank, label: 'Transfer' },
    { icon: ICONS.wallet, label: 'E-Wallet' },
];
const paySecond: PayMethod[] = [
    { icon: ICONS.card, label: 'Kartu' },
    { icon: ICONS.later, label: 'Bayar Nanti' },
    { icon: ICONS.split, label: 'Split Bayar' },
    { icon: ICONS.dp, label: 'DP' },
];
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
                                        width="1678"
                                        height="1210"
                                        src="/Pencatatan.webp"
                                        srcset="/Pencatatan-800.webp 800w, /Pencatatan.webp 1678w"
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
                                        <!-- Diulang dua kali agar marquee tersambung tanpa celah. -->
                                        <div v-for="(pay, i) in [...payFirst, ...payFirst]" :key="i" class="xb-logo lp-pay" :aria-hidden="i >= payFirst.length ? 'true' : undefined">
                                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="pay.icon"></svg>
                                            <span>{{ pay.label }}</span>
                                        </div>
                                    </div>
                                    <div class="xb-item--marquee marquee-2">
                                        <!-- Diulang dua kali agar marquee tersambung tanpa celah. -->
                                        <div v-for="(pay, i) in [...paySecond, ...paySecond]" :key="i" class="xb-logo lp-pay" :aria-hidden="i >= paySecond.length ? 'true' : undefined">
                                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="pay.icon"></svg>
                                            <span>{{ pay.label }}</span>
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
/* Ubin metode pembayaran di marquee (ukuran sama dengan logo template 90x90). */
.lp-pay {
    width: 90px;
    height: 90px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: #fff;
    border: 1px solid #e6e3f2;
    color: #0d1738;
    text-align: center;
}
.lp-pay svg {
    color: #533afd;
    flex: none;
}
.lp-pay span {
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
}
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
