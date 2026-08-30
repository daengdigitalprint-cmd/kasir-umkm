<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

const faqItems: { id: string; question: string; answer: string; active?: boolean }[] = [
    {
        id: 'aman',
        question: 'Apakah data saya aman?',
        answer:
            'Data Anda dikelola di infrastruktur dengan praktik keamanan standar aplikasi web: komunikasi terenkripsi (HTTPS), pembatasan akses per akun, dan cadangan rutin sesuai kebijakan server.',
        active: true,
    },
    {
        id: 'multi-kasir',
        question: 'Bisa dipakai beberapa kasir sekaligus?',
        answer:
            'Ya. Beberapa pengguna dapat login pada waktu bersamaan di perangkat berbeda. Setiap transaksi tercatat dengan timestamp dan identitas kasir.',
    },
    {
        id: 'stok-varian',
        question: 'Apakah mendukung varian produk dan stok?',
        answer:
            'Varian produk (ukuran, warna) dan stok per SKU tersedia. Stok minus dapat diizinkan atau diblokir. Paket bundel bisa diatur.',
    },
    {
        id: 'migrasi',
        question: 'Bagaimana migrasi dari sistem lama?',
        answer:
            'Tim kami membantu merencanakan impor data awal (produk, stok, pelanggan) dari spreadsheet atau export aplikasi lama.',
    },
    {
        id: 'biaya',
        question: 'Apakah ada biaya tersembunyi?',
        answer:
            'Tidak. Paket Starter gratis selamanya. Paket berbayar transparan dengan harga jelas, tanpa biaya tersembunyi.',
    },
];

const leftMarquee: { name: string; desig: string; avatar: string; avatarClass: string; price: string }[] = [
    { name: 'Budi Santoso', desig: 'QRIS', avatar: 'transaction_avatar01.png', avatarClass: 'bg-Pale2', price: 'Rp90.000' },
    { name: 'Siti Aminah', desig: 'Tunai', avatar: 'transaction_avatar02.png', avatarClass: 'bg-sky', price: 'Rp50.000' },
    { name: 'Andi Pratama', desig: 'QRIS', avatar: 'transaction_avatar03.png', avatarClass: 'bg-brown', price: 'Rp220.000' },
    { name: 'Dewi Lestari', desig: 'Transfer', avatar: 'transaction_avatar04.png', avatarClass: 'bg-Pale', price: 'Rp580.000' },
];

const rightMarquee: { name: string; desig: string; avatar: string; avatarClass: string; price: string }[] = [
    { name: 'Rudi Hartono', desig: 'QRIS', avatar: 'transaction_avatar05.png', avatarClass: 'bg-Pale2', price: 'Rp90.000' },
    { name: 'Maya Sari', desig: 'Tunai', avatar: 'transaction_avatar06.png', avatarClass: 'bg-sky', price: 'Rp770.000' },
    { name: 'Joko Widodo', desig: 'Transfer', avatar: 'transaction_avatar07.png', avatarClass: 'bg-brown', price: 'Rp760.000' },
    { name: 'Rina Wati', desig: 'QRIS', avatar: 'transaction_avatar01.png', avatarClass: 'bg-Pale', price: 'Rp880.000' },
];
</script>

<template>
    <!-- faq start -->
    <section class="faq pt-130" id="faq">
        <div class="container mxw-1330">
            <div class="fs-faq-wrapper pos-rel plr-20">
                <div class="row mt-none-50">
                    <div class="col-lg-5 mt-50">
                        <div class="xb-faq-left">
                            <div class="sec-title">
                                <h2 class="title wow fadeInUp" data-wow-delay="0ms"
                            data-wow-duration="600ms">Pertanyaan yang Sering Diajukan</h2>
                            </div>
                            <div class="fs-faq-image">
                                <img :src="`${base}/img/pricing/img01.png`" alt="image shape" loading="lazy" decoding="async">
                                <div class="img">
                                    <img class="updown-2" :src="`${base}/img/pricing/img02.png`" alt="" loading="lazy" decoding="async">
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
        <div class="container mxw-1330">
            <div class="fs-transaction-wrapper">
                <div class="fs-transaction-inner">
                    <div class="fs-transaction-marquee marquee-left">
                        <div class="fs-transaction-row">
                            <div v-for="(item, idx) in leftMarquee" :key="`left-${idx}`" class="fs-transaction-item ul_li_between">
                                <div class="xb-item--author ul_li">
                                    <div class="xb-item--avatar" :class="item.avatarClass">
                                        <img :src="`${base}/img/avatar/${item.avatar}`" alt="avatar" loading="lazy" decoding="async">
                                    </div>
                                    <div class="xb-item--holder">
                                        <h3 class="xb-item--name">{{ item.name }}</h3>
                                        <p class="xb-item--desig">{{ item.desig }}</p>
                                    </div>
                                </div>
                                <h2 class="xb-item--price">{{ item.price }}</h2>
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
                                        <img :src="`${base}/img/avatar/${item.avatar}`" alt="avatar" loading="lazy" decoding="async">
                                    </div>
                                    <div class="xb-item--holder">
                                        <h3 class="xb-item--name">{{ item.name }}</h3>
                                        <p class="xb-item--desig">{{ item.desig }}</p>
                                    </div>
                                </div>
                                <h2 class="xb-item--price">{{ item.price }}</h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- transaction-feed end -->
</template>
