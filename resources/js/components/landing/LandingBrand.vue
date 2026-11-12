<script setup lang="ts">
/**
 * Strip berjalan berisi jenis usaha yang dilayani UMKM Kasir.
 * (Sebelumnya berisi logo brand bawaan template — logo perusahaan lain,
 * bukan pengguna UMKM Kasir — sehingga diganti teks.)
 */
const businessTypes = [
    'Warung Makan',
    'Kafe & Coffee Shop',
    'Toko Kelontong',
    'Minimarket',
    'Apotek',
    'Barbershop',
    'Laundry',
    'Toko Bangunan',
    'Bakery',
    'Toko Grosir',
];
</script>

<template>
    <section class="brand pt-130 pb-55">
        <div class="mxw-1330 container">
            <div class="fs-brand-wrapper text-center">
                <p class="fs-brand-top">Aplikasi kasir untuk <span>berbagai jenis UMKM</span> di Indonesia</p>
                <div class="fs-brand-marquee-wrap o-hidden marquee-left">
                    <ul class="fs-brand-marquee">
                        <li class="fs-marquee-item lp-biz-chip" v-for="name in businessTypes" :key="name">{{ name }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>

<style>
.fs-brand-marquee .lp-biz-chip {
    font-size: 18px;
    font-weight: 600;
    color: #0d1738;
    opacity: 0.6;
    white-space: nowrap;
}
</style>
