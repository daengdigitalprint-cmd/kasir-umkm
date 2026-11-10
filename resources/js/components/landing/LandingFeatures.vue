<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/buyer-assets');
</script>

<template>
    <section id="fitur" class="gt-about-section fix section-padding pt-0">
        <div class="container">
            <div class="gt-about-wrapper-3 section-padding pb-0">
                <div class="clicp-shape">
                    <img :src="`${base}/img/new-add/clip-path.png`" alt="img" loading="lazy" decoding="async" />
                </div>
                <div class="row g-4 align-items-center">
                    <div class="col-xl-6">
                        <div class="gt-about-content">
                            <div class="gt-section-title style-3 mb-0">
                                <h6 class="tt-capitalize wow fadeInUp">Mengapa UMKM Kasir</h6>
                                <h2 class="char-animation">Pengalaman kasir yang bikin pelanggan UMKM betah kembali</h2>
                            </div>
                            <p class="gt-text wow fadeInUp" data-wow-delay=".3s">
                                UMKM Kasir membantu Anda mengelola produk, transaksi, dan laporan dalam satu aplikasi web. Tidak perlu lagi banyak
                                buku catatan atau spreadsheet yang rentan hilang — semua data tersimpan rapi, mudah dicari, dan siap dipakai kapan
                                pun.
                            </p>
                            <ul class="gt-list-items wow fadeInUp" data-wow-delay=".5s">
                                <li>
                                    <span class="gt-circle-box"></span>
                                    <div class="gt-content">
                                        <h4>Semua dalam satu aplikasi</h4>
                                        <p>
                                            Otomatiskan penjualan, stok, dan laporan di satu tempat. Hindari data yang tercecer dan pastikan setiap
                                            tim bekerja dengan informasi yang sama.
                                        </p>
                                    </div>
                                </li>
                                <li>
                                    <span class="gt-circle-box"></span>
                                    <div class="gt-content">
                                        <h4>Harga terjangkau</h4>
                                        <p>
                                            Dapatkan fitur lengkap implementasi mudah, dan dukungan ramah dengan harga yang sesuai kantong UMKM
                                            Indonesia.
                                        </p>
                                    </div>
                                </li>
                                <li>
                                    <span class="gt-circle-box"></span>
                                    <div class="gt-content">
                                        <h4>Cocok untuk usaha masa depan</h4>
                                        <p>
                                            Skalabel dari satu outlet hingga banyak cabang. Pertumbuhan bisnis Anda tidak akan tersendat oleh
                                            keterbatasan aplikasi.
                                        </p>
                                    </div>
                                </li>
                            </ul>
                            <div class="mt-4">
                                <Link href="/register" class="gt-theme-btn style-3 bg-theme"> Mulai sekarang </Link>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="gt-about-image agn-choose-5-img">
                            <div class="crm-imagewow wow fadeInRight" data-wow-delay=".3s">
                                <img :src="`${base}/img/new-add/crm-img.png`" alt="UMKM Kasir dashboard" loading="lazy" decoding="async" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
