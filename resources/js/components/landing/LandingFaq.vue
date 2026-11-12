<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

/** Sinkron dengan App\Support\LandingSeo::faqItems() (structured data FAQPage). */
const faqItems: { id: string; question: string; answer: string; active?: boolean }[] = [
    {
        id: 'gratis',
        question: 'Apakah Kasir UMKM by MaccaPOS benar-benar gratis?',
        answer: 'Ya. Paket Starter gratis selamanya tanpa batas masa aktif, cocok untuk toko kecil dengan hingga 30 produk. Saat usaha berkembang, Anda bisa naik ke paket GrowUp seharga Rp99 ribu per bulan.',
        active: true,
    },
    {
        id: 'perangkat',
        question: 'Bisa dipakai di HP Android, tablet, atau laptop?',
        answer: 'Bisa. Kasir UMKM by MaccaPOS berjalan di browser HP, tablet, maupun laptop, dan tersedia juga aplikasi Android di Google Play. Data tersinkron otomatis di semua perangkat.',
    },
    {
        id: 'pembayaran',
        question: 'Metode pembayaran apa saja yang bisa dicatat?',
        answer: 'Transaksi bisa dibayar tunai, QRIS, transfer bank, atau kombinasi beberapa metode sekaligus. Struk bisa dicetak ke printer thermal atau printer Bluetooth.',
    },
    {
        id: 'multi-kasir',
        question: 'Bisa dipakai beberapa kasir sekaligus?',
        answer: 'Ya. Beberapa pengguna dapat login pada waktu bersamaan di perangkat berbeda. Setiap transaksi tercatat dengan waktu dan identitas kasir, dan hak akses kasir, manajer, serta owner bisa dibedakan.',
    },
    {
        id: 'stok-varian',
        question: 'Apakah mendukung varian produk dan stok?',
        answer: 'Ya. Varian produk (ukuran, warna, rasa) dan stok per SKU tersedia. Stok berkurang otomatis setiap ada penjualan, dan stok minus dapat diizinkan atau diblokir.',
    },
    {
        id: 'aman',
        question: 'Apakah data saya aman?',
        answer: 'Data Anda tersimpan di server dengan komunikasi terenkripsi (HTTPS), pembatasan akses per akun, dan backup rutin setiap hari.',
    },
    {
        id: 'migrasi',
        question: 'Bagaimana pindah dari pencatatan manual atau aplikasi lama?',
        answer: 'Produk dan stok bisa diimpor sekaligus dari file Excel. Tim kami juga siap membantu memindahkan data awal dari spreadsheet atau aplikasi lama.',
    },
];

/** Dimensi asli avatar (mencegah layout shift). */
const avatarSize: Record<string, [number, number]> = {
    'transaction_avatar01.png': [42, 42],
    'transaction_avatar02.png': [34, 35],
    'transaction_avatar03.png': [32, 39],
    'transaction_avatar04.png': [33, 36],
    'transaction_avatar05.png': [35, 38],
    'transaction_avatar06.png': [31, 40],
    'transaction_avatar07.png': [31, 36],
};

const leftMarquee: { name: string; desig: string; avatar: string; avatarClass: string; price: string }[] = [
    { name: 'Budi Santoso', desig: 'QRIS', avatar: 'transaction_avatar01.png', avatarClass: 'bg-Pale2', price: 'Rp90.000' },
    { name: 'Siti Aminah', desig: 'Tunai', avatar: 'transaction_avatar02.png', avatarClass: 'bg-sky', price: 'Rp50.000' },
    { name: 'Andi Pratama', desig: 'QRIS', avatar: 'transaction_avatar03.png', avatarClass: 'bg-brown', price: 'Rp220.000' },
    { name: 'Dewi Lestari', desig: 'Transfer', avatar: 'transaction_avatar04.png', avatarClass: 'bg-Pale', price: 'Rp580.000' },
];

const rightMarquee: { name: string; desig: string; avatar: string; avatarClass: string; price: string }[] = [
    { name: 'Rudi Hartono', desig: 'QRIS', avatar: 'transaction_avatar05.png', avatarClass: 'bg-Pale2', price: 'Rp90.000' },
    { name: 'Maya Sari', desig: 'Tunai', avatar: 'transaction_avatar06.png', avatarClass: 'bg-sky', price: 'Rp770.000' },
    { name: 'Wahyu Saputra', desig: 'Transfer', avatar: 'transaction_avatar07.png', avatarClass: 'bg-brown', price: 'Rp760.000' },
    { name: 'Rina Wati', desig: 'QRIS', avatar: 'transaction_avatar01.png', avatarClass: 'bg-Pale', price: 'Rp880.000' },
];
</script>

<template>
    <!-- faq start -->
    <section class="faq pt-130" id="faq">
        <div class="mxw-1330 container">
            <div class="fs-faq-wrapper pos-rel plr-20">
                <div class="row mt-none-50">
                    <div class="col-lg-5 mt-50">
                        <div class="xb-faq-left">
                            <div class="sec-title">
                                <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">Pertanyaan yang Sering Diajukan</h2>
                            </div>
                            <div class="fs-faq-image">
                                <img width="573" height="299" :src="`${base}/img/pricing/img01.webp`" alt="" loading="lazy" decoding="async" />
                                <div class="img">
                                    <img
                                        width="378"
                                        height="195"
                                        class="updown-2"
                                        :src="`${base}/img/pricing/img02.png`"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7 mt-50">
                        <div class="xb-faq xb-desk-faq fs-faq">
                            <ul class="accordion_box clearfix list-unstyled">
                                <li v-for="item in faqItems" :key="item.id" class="accordion block" :class="{ 'active-block': item.active }">
                                    <div class="acc-btn" :class="{ active: item.active }">
                                        {{ item.question }}
                                        <span class="arrow"><span></span></span>
                                    </div>
                                    <div class="acc_body" :class="{ current: item.active }">
                                        <div class="content">
                                            <p>{{ item.answer }}</p>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- faq end -->

    <!-- transaction-feed start -->
    <section class="transaction-feed pos-rel">
        <div class="mxw-1330 container">
            <div class="fs-transaction-wrapper">
                <div class="fs-transaction-inner">
                    <div class="fs-transaction-marquee marquee-left">
                        <div class="fs-transaction-row">
                            <div v-for="(item, idx) in leftMarquee" :key="`left-${idx}`" class="fs-transaction-item ul_li_between">
                                <div class="xb-item--author ul_li">
                                    <div class="xb-item--avatar" :class="item.avatarClass">
                                        <img
                                            :src="`${base}/img/avatar/${item.avatar}`"
                                            :width="avatarSize[item.avatar]?.[0]"
                                            :height="avatarSize[item.avatar]?.[1]"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="xb-item--holder">
                                        <div class="xb-item--name lp-h3">{{ item.name }}</div>
                                        <p class="xb-item--desig">{{ item.desig }}</p>
                                    </div>
                                </div>
                                <div class="xb-item--price lp-h2">{{ item.price }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fs-transaction-inner">
                    <div class="fs-transaction-marquee marquee-right">
                        <div class="fs-transaction-row">
                            <div v-for="(item, idx) in rightMarquee" :key="`right-${idx}`" class="fs-transaction-item ul_li_between">
                                <div class="xb-item--author ul_li">
                                    <div class="xb-item--avatar" :class="item.avatarClass">
                                        <img
                                            :src="`${base}/img/avatar/${item.avatar}`"
                                            :width="avatarSize[item.avatar]?.[0]"
                                            :height="avatarSize[item.avatar]?.[1]"
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div class="xb-item--holder">
                                        <div class="xb-item--name lp-h3">{{ item.name }}</div>
                                        <p class="xb-item--desig">{{ item.desig }}</p>
                                    </div>
                                </div>
                                <div class="xb-item--price lp-h2">{{ item.price }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- transaction-feed end -->
</template>
