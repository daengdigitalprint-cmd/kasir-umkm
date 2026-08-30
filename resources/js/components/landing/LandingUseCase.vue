<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const assetsBase = computed(() => props.assetsBase ?? '/sastik-assets');

type UseCase = {
    icon: string;
    title: string;
    desc: string;
};

const useCases: UseCase[] = [
    {
        icon: 'fa-solid fa-utensils',
        title: 'F&B',
        desc: 'Restoran, warung makan, dan jajanan. Antrian dapur, split bill, dan diskon otomatis.',
    },
    {
        icon: 'fa-solid fa-store',
        title: 'Retail / Grosir',
        desc: 'Toko kelontong, minimarket, dan grosir. Barcode, satuan, dan kemasan multi-varian.',
    },
    {
        icon: 'fa-solid fa-prescription-bottle-medical',
        title: 'Apotek / Kesehatan',
        desc: 'Apotek dan klinik kecil. Batch number, kedaluwarsa, dan label peringatan.',
    },
    {
        icon: 'fa-solid fa-screwdriver-wrench',
        title: 'Jasa & Bengkel',
        desc: 'Bengkel motor, jasa servis, dan cuci. Paket jasa + sparepart, status pengerjaan.',
    },
    {
        icon: 'fa-solid fa-scissors',
        title: 'Salon & Kecantikan',
        desc: 'Salon, barbershop, dan klinik kecantikan. Booking anggota, paket bundling, dan member.',
    },
    {
        icon: 'fa-solid fa-mug-saucer',
        title: 'Kafe & Coffee Shop',
        desc: 'Kafe, coffee shop, dan bakery. Modifier pesanan, takeaway, dan loyalty poin.',
    },
    {
        icon: 'fa-solid fa-basket-shopping',
        title: 'Toko Kelontong',
        desc: 'Warung, toko kelontong, dan minimarket. Catatan hutang pelanggan dan setoran harian.',
    },
    {
        icon: 'fa-solid fa-layer-group',
        title: 'Dan lainnya',
        desc: 'Pengaturan modular — pasang menu dan fitur sesuai kebutuhan unik usaha Anda.',
    },
];
</script>

<template>
    <!-- usecase start -->
    <section class="usecase pt-125 pb-130">
        <div class="container mxw-1330">
            <div class="fs-usecase-wrapper plr-20">
                <div class="sec-title text-center mb-65">
                    <h2 class="title wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                        Untuk Berbagai Jenis Usaha
                    </h2>
                    <p class="xb-item--content mt-15 wow fadeInUp" data-wow-delay="100ms" data-wow-duration="600ms"
                        style="max-width: 600px; margin-left: auto; margin-right: auto;">
                        Solusi Kasir yang Fleksibel untuk Semua Lini UMKM
                    </p>
                </div>
                <div class="row mt-none-30">
                    <div class="col-xl-3 col-md-6 mt-30" v-for="item in useCases" :key="item.title">
                        <div class="fs-usecase-item wow fadeInUp" data-wow-delay="0ms" data-wow-duration="600ms">
                            <div class="xb-item--icon">
                                <i :class="item.icon"></i>
                            </div>
                            <h3 class="xb-item--title">{{ item.title }}</h3>
                            <p class="xb-item--content">{{ item.desc }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- usecase end -->
</template>

<style>
/* Use case section — konsisten dengan style Sastik */
.usecase {
    background: #f8f9ff;
}

.fs-usecase-item {
    z-index: 1;
    height: 100%;
    overflow: hidden;
    position: relative;
    background: #ffffff;
    padding: 30px 25px;
    border: 1px solid #edf0f7;
    border-radius: 15px;
    transition: all 0.4s ease-in-out;
}
.fs-usecase-item:hover {
    border-color: #bdeaff;
    box-shadow: 0 20px 40px -10px rgba(13, 23, 56, 0.08);
    transform: translateY(-5px);
}

.fs-usecase-item .xb-item--icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #0d1738;
    border-radius: 15px;
    background: #e8f4ff;
    margin-bottom: 20px;
    transition: all 0.4s ease-in-out;
}
.fs-usecase-item:hover .xb-item--icon {
    background: #0d1738;
    color: #ffffff;
}

.fs-usecase-item .xb-item--title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 10px;
    color: #0d1738;
}

.fs-usecase-item .xb-item--content {
    font-size: 14px;
    line-height: 1.6;
    color: #5a6478;
    margin: 0;
}

@media (max-width: 767px) {
    .fs-usecase-item .xb-item--content {
        font-size: 15px;
    }
}
</style>
