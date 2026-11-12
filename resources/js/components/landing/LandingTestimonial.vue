<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/buyer-assets');

type Testimonial = {
    name: string;
    role: string;
    body: string;
    avatar: string;
};

const column1: Testimonial[] = [
    {
        name: 'Ibu Rina',
        role: 'Pemilik Warung Kelontong',
        body: 'Dulu saya tulis transaksi di buku, sekarang tinggal klik. Stok langsung berkurang dan laporan mingguan tinggal unduh. Hemat waktu!',
        avatar: 'client-01.png',
    },
    {
        name: 'Pak Hendra',
        role: 'Pemilik Kopi Pagi, Bandung',
        body: 'Shift kasir jadi jelas, omzet harian kelihatan real-time. QRIS langsung connect tanpa setup ribet.',
        avatar: 'client-02.png',
    },
    {
        name: 'Ibu Sinta',
        role: 'Pemilik Apotek Sehat',
        body: 'Pencatatan batch number dan tanggal kedaluwarsa otomatis tampil di label. Tidak ada lagi obat kadaluwarsa yang kelupaan dijual.',
        avatar: 'client-03.png',
    },
    {
        name: 'Mas Oki',
        role: 'Pemilik Bengkel Cepat',
        body: 'Paket servis + sparepart bisa digabung dalam satu transaksi. Status pengerjaan dan invoice-nya rapi di dashboard.',
        avatar: 'client-01.png',
    },
];

const column2: Testimonial[] = [
    {
        name: 'Ibu Maya',
        role: 'Pemilik Salon Cantik',
        body: 'Booking anggota, paket bundling, dan poin loyalty jadi gampang. Tamu langganan tidak perlu lagi menulis di kartu manual.',
        avatar: 'client-03.png',
    },
    {
        name: 'Pak Andi',
        role: 'Pemilik Toko Bangunan',
        body: 'Stok puluhan ribu SKU dulu pusing. Sekarang lihat sisa barang dari HP, kalau mau transfer antar gudang tinggal klik.',
        avatar: 'client-02.png',
    },
    {
        name: 'Ibu Lestari',
        role: 'Pemilik Kafe Senja',
        body: 'Modifier pesanan kayak "less sugar" atau "tambah boba" tinggal dipilih di layar POS. Antrian dapur juga kelihatan jelas.',
        avatar: 'client-01.png',
    },
    {
        name: 'Mas Rian',
        role: 'Pemilik Butik Online',
        body: 'Pesanan pre-order dari WhatsApp bisa langsung saya catat sebagai transaksi. Pembayaran DP & pelunasan tinggal scan QRIS.',
        avatar: 'client-03.png',
    },
];

const column3: Testimonial[] = [
    {
        name: 'Ibu Dewi',
        role: 'Pemilik Warung Makan',
        body: 'Aplikasi kasir paling cocok untuk UMKM. Tidak perlu kursus, tinggal coba, dan tim support-nya fast respon lewat WhatsApp.',
        avatar: 'client-02.png',
    },
    {
        name: 'Pak Yusuf',
        role: 'Pemilik Minimarket',
        body: 'Laporan laba rugi otomatis dihitung, jadi saya tahu persis produk mana yang paling untung. Tidak perlu lagi hitung manual.',
        avatar: 'client-01.png',
    },
    {
        name: 'Ibu Wati',
        role: 'Pemilik Toko Kue',
        body: 'Pre-order kue ulang tahun dan pernikahan gampang dilacak. Stok bahan baku juga ikut terkontrol, jadi tidak kehabisan mendadak.',
        avatar: 'client-02.png',
    },
    {
        name: 'Mas Dimas',
        role: 'Pemilik Coffee Shop',
        body: 'Customer bisa pesan pakai QR di meja, langsung nyambung ke POS. Penjualan naik karena pesanannya lebih cepat diproses.',
        avatar: 'client-03.png',
    },
];
</script>

<template>
    <section
        id="testimoni"
        class="gt-testimonial-section-4 fix section-padding"
        :style="`background-image: url('${base}/img/testimonial/testimonial-bg.jpg');`"
    >
        <div class="container">
            <div class="gradient-box"></div>
            <div class="gradient-box2"></div>
            <div class="gt-section-title style-4 text-center">
                <h6 class="wow fadeInUp tt-capitalize">Cerita Pelanggan Kami</h6>
                <h2 class="char-animation">Apa kata mereka tentang Kasir UMKM by MaccaPOS</h2>
            </div>
            <div class="row height-fixed fix justify-content-between lp-testimonial-row">
                <div class="col-xl-4 col-md-6">
                    <div class="all-item d-grid">
                        <div v-for="(t, idx) in column1" :key="`c1-${idx}`" class="lp-testimonial-card">
                            <div class="lp-testimonial-quote" aria-hidden="true">
                                <i class="fa-solid fa-quote-left"></i>
                            </div>
                            <p class="lp-testimonial-body">{{ t.body }}</p>
                            <div class="lp-testimonial-stars" aria-label="Penilaian 5 dari 5">
                                <i v-for="n in 5" :key="`c1-${idx}-s${n}`" class="fa-solid fa-star"></i>
                            </div>
                            <div class="lp-testimonial-client">
                                <div class="lp-testimonial-avatar">
                                    <img :src="`${base}/img/new-icon/${t.avatar}`" :alt="`Foto ${t.name}`" loading="lazy" decoding="async" />
                                </div>
                                <div class="lp-testimonial-info">
                                    <h3>{{ t.name }}</h3>
                                    <span>{{ t.role }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-6">
                    <div class="all-item d-grid reverse">
                        <div v-for="(t, idx) in column2" :key="`c2-${idx}`" class="lp-testimonial-card">
                            <div class="lp-testimonial-quote" aria-hidden="true">
                                <i class="fa-solid fa-quote-left"></i>
                            </div>
                            <p class="lp-testimonial-body">{{ t.body }}</p>
                            <div class="lp-testimonial-stars" aria-label="Penilaian 5 dari 5">
                                <i v-for="n in 5" :key="`c2-${idx}-s${n}`" class="fa-solid fa-star"></i>
                            </div>
                            <div class="lp-testimonial-client">
                                <div class="lp-testimonial-avatar">
                                    <img :src="`${base}/img/new-icon/${t.avatar}`" :alt="`Foto ${t.name}`" loading="lazy" decoding="async" />
                                </div>
                                <div class="lp-testimonial-info">
                                    <h3>{{ t.name }}</h3>
                                    <span>{{ t.role }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-6">
                    <div class="all-item d-grid">
                        <div v-for="(t, idx) in column3" :key="`c3-${idx}`" class="lp-testimonial-card">
                            <div class="lp-testimonial-quote" aria-hidden="true">
                                <i class="fa-solid fa-quote-left"></i>
                            </div>
                            <p class="lp-testimonial-body">{{ t.body }}</p>
                            <div class="lp-testimonial-stars" aria-label="Penilaian 5 dari 5">
                                <i v-for="n in 5" :key="`c3-${idx}-s${n}`" class="fa-solid fa-star"></i>
                            </div>
                            <div class="lp-testimonial-client">
                                <div class="lp-testimonial-avatar">
                                    <img :src="`${base}/img/new-icon/${t.avatar}`" :alt="`Foto ${t.name}`" loading="lazy" decoding="async" />
                                </div>
                                <div class="lp-testimonial-info">
                                    <h3>{{ t.name }}</h3>
                                    <span>{{ t.role }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.gt-testimonial-section-4 {
    position: relative;
    background-color: #f8fafc;
    background-size: cover;
    background-position: center;
}

.gt-testimonial-section-4 .gradient-box,
.gt-testimonial-section-4 .gradient-box2 {
    position: absolute;
    border-radius: 999px;
    filter: blur(80px);
    pointer-events: none;
    z-index: 0;
}
.gt-testimonial-section-4 .gradient-box {
    width: 280px;
    height: 280px;
    top: 8%;
    left: -100px;
    background: rgba(18, 74, 255, 0.18);
}
.gt-testimonial-section-4 .gradient-box2 {
    width: 320px;
    height: 320px;
    bottom: 5%;
    right: -120px;
    background: rgba(249, 115, 22, 0.16);
}

.gt-testimonial-section-4 .container {
    position: relative;
    z-index: 1;
}

.gt-section-title.style-4 h6 {
    color: #ec4899 !important;
    font-family: 'Nunito', sans-serif;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    font-size: 0.8rem;
    margin: 0 0 8px;
}
.gt-section-title.style-4 h2 {
    color: #1e1b4b !important;
    font-size: clamp(1.75rem, 3.2vw, 2.5rem);
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    line-height: 1.2;
    margin: 0;
}

.lp-testimonial-row {
    margin-top: 3rem;
}

.all-item {
    display: grid;
    gap: 1.25rem;
}

.all-item.reverse {
    transform: translateY(40px);
}

@media (max-width: 767.98px) {
    .all-item.reverse {
        transform: none;
    }
}

.lp-testimonial-card {
    position: relative;
    background: #ffffff;
    border-radius: 1.25rem;
    padding: 1.75rem 1.5rem 1.5rem;
    box-shadow:
        0 14px 30px -18px rgba(15, 23, 42, 0.18),
        0 2px 6px -2px rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(18, 74, 255, 0.08);
    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        border-color 0.25s ease;
}

.lp-testimonial-card:hover {
    transform: translateY(-4px);
    border-color: rgba(18, 74, 255, 0.25);
    box-shadow:
        0 24px 44px -20px rgba(18, 74, 255, 0.28),
        0 4px 12px -2px rgba(15, 23, 42, 0.06);
}

.lp-testimonial-quote {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 999px;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #124aff;
    font-size: 1.05rem;
    margin-bottom: 1rem;
}

.lp-testimonial-body {
    margin: 0 0 1rem;
    font-size: 0.95rem;
    font-weight: 500;
    color: #1f2937;
    line-height: 1.7;
    font-family: 'Nunito', sans-serif;
}

.lp-testimonial-stars {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 0 0 1.25rem;
    color: #f59e0b;
    font-size: 0.85rem;
}

.lp-testimonial-client {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(18, 74, 255, 0.12);
}

.lp-testimonial-avatar {
    flex-shrink: 0;
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    overflow: hidden;
    background: #f1f5f9;
    box-shadow:
        0 0 0 3px #ffffff,
        0 0 0 5px rgba(18, 74, 255, 0.18);
}

.lp-testimonial-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.lp-testimonial-info h3 {
    margin: 0;
    font-family: 'Nunito', sans-serif;
    font-size: 0.95rem;
    font-weight: 800;
    color: #1e1b4b;
    line-height: 1.2;
}

.lp-testimonial-info span {
    display: block;
    margin-top: 2px;
    font-size: 0.78rem;
    font-weight: 600;
    color: #64748b;
    line-height: 1.3;
}
</style>
