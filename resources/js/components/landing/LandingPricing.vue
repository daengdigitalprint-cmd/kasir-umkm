<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Bentuk data dari App\Support\LandingPricing::payload(). */
export interface LandingPricingData {
    free: {
        name: string;
        description: string;
        price_label: string;
        max_products: number | null;
        max_customers: number | null;
        max_stores: number | null;
    };
    paid: {
        name: string;
        description: string;
        monthly_label: string;
        yearly_label: string;
        max_stores: number | null;
        outlet_addon_label?: string;
    };
    yearly_saving_months: number | null;
}

const props = defineProps<{
    assetsBase?: string;
    pricing?: LandingPricingData;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

/**
 * Cadangan bila prop tidak dikirim (mis. komponen dipakai di luar halaman
 * landing). Angka sebenarnya selalu datang dari pengaturan Super Admin.
 */
const data = computed<LandingPricingData>(
    () =>
        props.pricing ?? {
            free: {
                name: 'Starter',
                description: 'Gratis untuk coba dan toko kecil.',
                price_label: 'Gratis',
                max_products: null,
                max_customers: null,
                max_stores: 1,
            },
            paid: {
                name: 'GrowUp',
                description: 'Untuk pertumbuhan outlet: operasional penuh, laporan mendalam, multi-outlet.',
                monthly_label: '',
                yearly_label: '',
                max_stores: 1,
                outlet_addon_label: 'Rp85 rb',
            },
            yearly_saving_months: null,
        },
);

/** Angka kuota mengikuti batas yang diatur admin, tidak ditulis tetap. */
const starterFeatures = computed<string[]>(() => {
    const free = data.value.free;
    const kuota: string[] = [];

    if (free.max_products) kuota.push(free.max_products + ' produk');
    if (free.max_customers) kuota.push(free.max_customers + ' pelanggan');

    return [
        kuota.length ? 'Hingga ' + kuota.join(' & ') : 'Produk & pelanggan tanpa batas',
        'Transaksi tanpa batas',
        'Kasir (POS) & cetak struk',
        'Stok dasar & laporan penjualan',
        (free.max_stores ?? 1) + ' outlet, tanpa batas masa aktif',
    ];
});

/** Paket Custom: harga disepakati bersama, jadi tidak ada angka dari database. */
const customFeatures: string[] = [
    'Jumlah outlet & pengguna sesuai kebutuhan',
    'Gudang pusat / central kitchen untuk banyak outlet',
    'Fitur tambahan digabung dalam satu harga',
    'Pendampingan awal untuk tim Anda',
];

const customWaUrl = `https://wa.me/6285242766676?text=${encodeURIComponent(
    'Halo, saya tertarik dengan Paket Custom Kasir UMKM by MaccaPOS. Usaha saya: (jenis usaha, jumlah outlet). Mohon info harganya, terima kasih.',
)}`;

const growUpFeatures = computed<string[]>(() => {
    const outlet = data.value.paid.max_stores;

    return [
        'Produk & pelanggan tanpa batas',
        outlet
            ? outlet + ' outlet, tambah outlet ' + (data.value.paid.outlet_addon_label || 'Rp85 rb') + '/bulan'
            : 'Outlet tanpa batas & transfer stok',
        'Shift kasir, pembelian & stok opname',
        'Diskon, loyalitas & piutang pelanggan',
        'Resep, akuntansi & asisten AI',
    ];
});
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
                                                <h3 class="xb-item--title lp-fs-h2">Paket {{ data.free.name }}</h3>
                                                <p class="xb-item--text">{{ data.free.description }}</p>
                                            </div>
                                            <div class="xb-item--price_box">
                                                <div class="xb-item--price lp-h3">{{ data.free.price_label }}</div>
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
                                                <h3 class="xb-item--title lp-fs-h2">Paket {{ data.paid.name }}</h3>
                                                <p class="xb-item--text">{{ data.paid.description }}</p>
                                            </div>
                                            <div class="xb-item--price_box pricing_amount_monthly">
                                                <div class="xb-item--price lp-h3">{{ data.paid.monthly_label }}</div>
                                                <span class="xb-item--text">Per bulan</span>
                                            </div>
                                            <div class="xb-item--price_box pricing_amount_yearly">
                                                <div class="xb-item--price lp-h3">{{ data.paid.yearly_label }}</div>
                                                <span class="xb-item--text">Per tahun</span>
                                            </div>
                                            <!-- Coretan hemat hanya tampil bila tahunan memang lebih murah. -->
                                            <div v-if="data.yearly_saving_months" class="xb-pricing-scribble">
                                                <p class="text text-center">
                                                    Hemat {{ data.yearly_saving_months }} Bulan <br />
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
                        <!-- Paket Custom: untuk jaringan outlet / kebutuhan khusus, harga dibicarakan langsung -->
                        <div class="row mt-30">
                            <div class="col-12">
                                <div class="fs-pricing-item lp-pricing-custom" id="paket-custom">
                                    <div class="xb-item--holder">
                                        <div class="xb-item--top ul_li_between">
                                            <div class="xb-item--content">
                                                <span class="lp-pricing-custom-tag">Untuk banyak outlet</span>
                                                <h3 class="xb-item--title lp-fs-h2">Paket Custom</h3>
                                                <p class="xb-item--text">
                                                    Untuk jaringan outlet, waralaba, atau kebutuhan yang tidak pas dengan paket di atas. Kami susun paketnya bersama Anda.
                                                </p>
                                            </div>
                                            <div class="xb-item--price_box">
                                                <div class="xb-item--price lp-h3 lp-pricing-custom-price">Sesuai kebutuhan</div>
                                                <span class="xb-item--text">Harga dibicarakan langsung</span>
                                            </div>
                                        </div>
                                        <div class="xb-item--btn">
                                            <a class="thm-btn fintech-btn" :href="customWaUrl" target="_blank" rel="noopener noreferrer">Konsultasi lewat WhatsApp</a>
                                        </div>
                                    </div>
                                    <div class="xb-item--list_box">
                                        <div class="xb-item--list_title lp-h3">Yang Bisa Disesuaikan</div>
                                        <ul class="xb-item--list list-unstyled">
                                            <li v-for="feature in customFeatures" :key="feature">
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

<style>
/* Paket Custom: kartu selebar dua paket di atasnya; info di kiri, daftar di kanan.
   Selector sengaja `.fs-pricing-item.lp-pricing-custom` (lebih spesifik): di halaman asli
   landing.min.css dimuat SETELAH CSS komponen ini, jadi aturan template `.fs-pricing-item .xb-item--*`
   yang sama spesifiknya akan menang bila hanya memakai `.lp-pricing-custom`. */
.fs-pricing-item.lp-pricing-custom {
    display: grid;
    gap: 10px 30px;
    align-items: center;
    grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
    padding-bottom: 10px;
}
.fs-pricing-item.lp-pricing-custom .xb-item--list_box {
    margin-top: 0;
}
.fs-pricing-item.lp-pricing-custom .xb-item--btn {
    margin-top: 30px;
}
/* Teks tombol bisa turun dua baris di layar sempit: tetap rata tengah. */
.fs-pricing-item.lp-pricing-custom .xb-item--btn a {
    justify-content: center;
    text-align: center;
}
/* Kotak harga berisi teks: jangan sampai menjepit kolom keterangan di sebelahnya. */
.fs-pricing-item.lp-pricing-custom .xb-item--top {
    gap: 20px;
    /* Kolom sempit (tablet/laptop kecil): kotak harga turun ke bawah keterangan. */
    flex-wrap: wrap;
}
.fs-pricing-item.lp-pricing-custom .xb-item--content {
    flex: 1 1 260px;
    min-width: 0;
}
.fs-pricing-item.lp-pricing-custom .xb-item--price_box {
    flex: 0 0 auto;
}
.lp-pricing-custom-tag {
    display: inline-block;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 700;
    color: #533afd;
    background: #efecff;
    border-radius: 999px;
}
/* Teks, bukan angka: lebih kecil dari harga paket lain agar muat satu baris. */
.fs-pricing-item.lp-pricing-custom .xb-item--price.lp-pricing-custom-price {
    font-size: 24px;
    letter-spacing: -0.01em;
    white-space: nowrap;
}

@media (max-width: 991px) {
    .fs-pricing-item.lp-pricing-custom {
        grid-template-columns: minmax(0, 1fr);
        padding-bottom: 25px;
    }
    .fs-pricing-item.lp-pricing-custom .xb-item--list_box {
        margin-top: 25px;
    }
}
@media (max-width: 767px) {
    .fs-pricing-item.lp-pricing-custom .xb-item--top {
        flex-wrap: wrap;
    }
}
</style>
