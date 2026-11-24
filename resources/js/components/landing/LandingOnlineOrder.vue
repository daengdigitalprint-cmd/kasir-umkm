<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// Dipertahankan agar caller (Landing/Index.vue) tetap bisa mengirim
// `assets-base` tanpa error — tidak dipakai di template.
defineProps<{
    assetsBase?: string;
}>();

type Point = {
    /** Kunci ikon SVG inline di template (tanpa font ikon, supaya tidak perlu subset baru). */
    icon: 'link' | 'qr' | 'apps';
    title: string;
    desc: string;
};

const points: Point[] = [
    {
        icon: 'link',
        title: 'Pesan antar lewat tautan toko',
        desc: 'Pelanggan pilih menu dan isi alamat dari tautan toko Anda. Diantar kurir sendiri, tanpa potongan komisi aplikasi.',
    },
    {
        icon: 'qr',
        title: 'Pesan dari meja dengan QR',
        desc: 'Tamu scan QR di meja, pilih menu, dan pesanan langsung masuk ke kasir serta dapur tanpa menunggu pelayan.',
    },
    {
        icon: 'apps',
        title: 'Harga khusus GoFood, GrabFood, ShopeeFood',
        desc: 'Atur harga berbeda untuk tiap aplikasi. Kasir otomatis memakai harga itu dan komisinya tercatat di laporan.',
    },
];

const highlights = ['Terima atau tolak dari kasir', 'Pelanggan memantau status pesanan', 'Bayar di tempat, QRIS, atau transfer'];

const steps = ['Dikirim', 'Disiapkan', 'Diantar', 'Selesai'];

const waUrl = `https://wa.me/6285242766676?text=${encodeURIComponent(
    'Halo, saya ingin bertanya tentang fitur pesanan online di Kasir UMKM by MaccaPOS (pesan antar lewat tautan dan pesan dari meja).',
)}`;
</script>

<template>
    <!-- pesanan online start -->
    <section class="lp-online pt-125 pb-130" id="pesanan-online">
        <div class="mxw-1330 container">
            <div class="lp-online-wrap plr-20">
                <div class="lp-online-text">
                    <span class="lp-online-eyebrow wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Pesanan Online</span>
                    <h2 class="lp-online-title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                        Pelanggan Pesan dari HP, Pesanan Langsung Masuk ke Kasir
                    </h2>
                    <p class="lp-online-lead wow fadeInUp" data-wow-delay="100ms" data-wow-duration="600ms">
                        Tidak perlu lagi menyalin pesanan dari chat. Bagikan tautan toko atau pasang QR di meja, dan setiap pesanan muncul di kasir untuk
                        Anda terima.
                    </p>

                    <ul class="lp-online-points list-unstyled">
                        <li v-for="point in points" :key="point.title" class="wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                            <span class="lp-online-icon" aria-hidden="true">
                                <svg v-if="point.icon === 'link'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                </svg>
                                <svg v-else-if="point.icon === 'qr'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7" rx="1" />
                                    <rect x="14" y="3" width="7" height="7" rx="1" />
                                    <rect x="3" y="14" width="7" height="7" rx="1" />
                                    <path d="M14 14h3v3h-3zM20 14v.01M14 20v.01M17 20h4v-3" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="5" y="2" width="14" height="20" rx="2" />
                                    <path d="M9 7h6M9 11h6M11 18h2" />
                                </svg>
                            </span>
                            <div>
                                <h3 class="lp-online-point-title">{{ point.title }}</h3>
                                <p class="lp-online-point-desc">{{ point.desc }}</p>
                            </div>
                        </li>
                    </ul>

                    <div class="lp-online-actions">
                        <Link class="thm-btn fintech-btn" href="/register">Coba gratis</Link>
                        <a class="lp-online-wa" :href="waUrl" target="_blank" rel="noopener noreferrer">Tanya lewat WhatsApp &rarr;</a>
                    </div>
                    <p class="lp-online-note">Pesan antar dan pesan dari meja adalah fitur tambahan yang bisa diaktifkan sesuai paket.</p>
                </div>

                <!-- Ilustrasi alur pesanan (dekoratif) -->
                <div class="lp-online-visual" aria-hidden="true">
                    <div class="lp-online-card lp-online-card--order wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                        <div class="lp-online-card-head">
                            <span class="lp-online-badge">Pesanan masuk</span>
                            <span class="lp-online-time">Baru saja</span>
                        </div>
                        <p class="lp-online-customer">Pak Budi <span>Pesan antar</span></p>
                        <ul class="lp-online-items list-unstyled">
                            <li><b>2×</b> Nasi Ayam Geprek <span>Rp50.000</span></li>
                            <li><b>2×</b> Es Teh Manis <span>Rp12.000</span></li>
                            <li class="lp-online-fee">Ongkir <span>Rp8.000</span></li>
                        </ul>
                        <div class="lp-online-total">Total <span>Rp70.000</span></div>
                        <div class="lp-online-buttons">
                            <span class="lp-online-btn lp-online-btn--ghost">Tolak</span>
                            <span class="lp-online-btn">Terima</span>
                        </div>
                    </div>

                    <div class="lp-online-card lp-online-card--status wow fadeInUp" data-wow-delay="150ms" data-wow-duration="600ms">
                        <p class="lp-online-status-title">Status pesanan pelanggan</p>
                        <ol class="lp-online-steps list-unstyled">
                            <li v-for="(step, index) in steps" :key="step" :class="{ 'is-done': index < 2, 'is-current': index === 2 }">
                                <span class="lp-online-dot"></span>
                                {{ step }}
                            </li>
                        </ol>
                    </div>

                    <ul class="lp-online-chips list-unstyled">
                        <li v-for="item in highlights" :key="item">{{ item }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
    <!-- pesanan online end -->
</template>

<style>
/* Section Pesanan Online — warna & radius mengikuti gaya Sastik di landing */
.lp-online {
    background: linear-gradient(180deg, #ffffff 0%, #f4f2ff 100%);
}

.lp-online-wrap {
    display: grid;
    gap: 50px;
    align-items: center;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
}

.lp-online-eyebrow {
    display: inline-block;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #533afd;
    background: #efecff;
    border-radius: 999px;
}

.lp-online-title {
    margin: 16px 0 14px;
    font-size: 40px;
    line-height: 1.15;
    letter-spacing: -0.02em;
    color: #0d1738;
}

.lp-online-lead {
    max-width: 560px;
    margin: 0;
    font-size: 17px;
    line-height: 1.65;
    color: #5a6478;
}

.lp-online-points {
    margin: 30px 0 0;
    padding: 0;
    display: grid;
    gap: 18px;
}
.lp-online-points li {
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.lp-online-icon {
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #533afd;
    background: #efecff;
    border-radius: 14px;
}
.lp-online-icon svg {
    width: 22px;
    height: 22px;
}

.lp-online-point-title {
    margin: 0 0 4px;
    font-size: 18px;
    font-weight: 700;
    color: #0d1738;
}
.lp-online-point-desc {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    color: #5a6478;
}

.lp-online-actions {
    margin-top: 32px;
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    align-items: center;
}
.lp-online-wa {
    font-size: 15px;
    font-weight: 600;
    color: #533afd;
    text-decoration: none;
}
.lp-online-wa:hover {
    text-decoration: underline;
}

.lp-online-note {
    margin: 16px 0 0;
    font-size: 13px;
    color: #8a93a6;
}

/* Ilustrasi */
.lp-online-visual {
    position: relative;
    display: grid;
    gap: 16px;
}

.lp-online-card {
    padding: 22px;
    background: #ffffff;
    border: 1px solid #edf0f7;
    border-radius: 20px;
    box-shadow: 0 24px 48px -20px rgba(13, 23, 56, 0.18);
}
.lp-online-card--status {
    margin-left: 12%;
}

.lp-online-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.lp-online-badge {
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 700;
    color: #9a3412;
    background: #ffedd5;
    border-radius: 999px;
}
.lp-online-time {
    font-size: 12px;
    color: #8a93a6;
}

.lp-online-customer {
    margin: 14px 0 10px;
    font-size: 17px;
    font-weight: 700;
    color: #0d1738;
}
.lp-online-customer span {
    margin-left: 6px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    color: #533afd;
    background: #efecff;
    border-radius: 6px;
}

.lp-online-items {
    margin: 0;
    padding: 0;
}
.lp-online-items li {
    display: flex;
    gap: 8px;
    padding: 7px 0;
    font-size: 14px;
    color: #3b4458;
    border-bottom: 1px solid #f1f3f9;
}
.lp-online-items li span {
    margin-left: auto;
    color: #5a6478;
}
.lp-online-items .lp-online-fee {
    color: #8a93a6;
}

.lp-online-total {
    display: flex;
    justify-content: space-between;
    margin-top: 12px;
    font-size: 15px;
    font-weight: 700;
    color: #0d1738;
}
.lp-online-total span {
    color: #533afd;
}

.lp-online-buttons {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}
.lp-online-btn {
    flex: 1;
    padding: 10px 12px;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    color: #ffffff;
    background: #533afd;
    border: 1px solid #533afd;
    border-radius: 12px;
}
.lp-online-btn--ghost {
    color: #dc2626;
    background: #ffffff;
    border-color: #fecaca;
}

.lp-online-status-title {
    margin: 0 0 14px;
    font-size: 13px;
    font-weight: 700;
    color: #5a6478;
}
.lp-online-steps {
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
}
.lp-online-steps li {
    position: relative;
    padding-top: 18px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    color: #a3abbb;
}
.lp-online-steps li::before {
    content: '';
    position: absolute;
    top: 5px;
    left: -50%;
    width: 100%;
    height: 2px;
    background: #e5e8f1;
}
.lp-online-steps li:first-child::before {
    display: none;
}
.lp-online-dot {
    position: absolute;
    top: 0;
    left: 50%;
    width: 12px;
    height: 12px;
    background: #ffffff;
    border: 2px solid #d5d9e6;
    border-radius: 50%;
    transform: translateX(-50%);
    z-index: 1;
}
.lp-online-steps .is-done,
.lp-online-steps .is-current {
    color: #0d1738;
}
.lp-online-steps .is-done::before,
.lp-online-steps .is-current::before {
    background: #533afd;
}
.lp-online-steps .is-done .lp-online-dot {
    background: #533afd;
    border-color: #533afd;
}
.lp-online-steps .is-current .lp-online-dot {
    border-color: #533afd;
    box-shadow: 0 0 0 4px rgba(83, 58, 253, 0.15);
}

.lp-online-chips {
    margin: 4px 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.lp-online-chips li {
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #3b4458;
    background: #ffffff;
    border: 1px solid #e3e6f0;
    border-radius: 999px;
}

@media (max-width: 1199px) {
    .lp-online-title {
        font-size: 34px;
    }
}

@media (max-width: 991px) {
    .lp-online-wrap {
        gap: 40px;
        grid-template-columns: minmax(0, 1fr);
    }
    .lp-online-card--status {
        margin-left: 0;
    }
}

@media (max-width: 575px) {
    .lp-online-title {
        font-size: 28px;
    }
    .lp-online-lead {
        font-size: 16px;
    }
    .lp-online-card {
        padding: 18px;
    }
}
</style>
