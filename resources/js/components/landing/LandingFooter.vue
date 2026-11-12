<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/sastik-assets');

const navLinks: { label: string; href: string }[] = [
    { label: 'Fitur', href: '#fitur' },
    { label: 'Harga', href: '#harga' },
    { label: 'FAQ', href: '#faq' },
    { label: 'Kebijakan Refund', href: '/refund' },
    { label: 'Syarat & Ketentuan', href: '/terms' },
    { label: 'Kontak', href: '#kontak' },
];
</script>

<template>
    <!-- footer strt -->
    <footer class="footer">
        <div class="mxw-1330 container">
            <div class="fs-footer-wrapper pos-rel z-1" data-bg-color="#d4ceff">
                <div class="fs-footer-nav-wrap">
                    <ul class="xb-footer-nav xb-corner-shape ul_li">
                        <li v-for="link in navLinks" :key="link.label">
                            <a :href="link.href">{{ link.label }}</a>
                        </li>
                    </ul>
                </div>
                <div class="fs-footer-inner">
                    <div class="row mt-none-30">
                        <div class="col-lg-4 col-md-8 mt-30">
                            <div class="fs-footer-widget fs-footer-newsletter">
                                <p class="fs-widget-title">Mulai Sekarang</p>
                                <h2 class="xb-item--title">Siap Kelola Usaha Lebih Rapi?</h2>
                                <p class="xb-item--content">Daftar gratis dalam 1 menit dan mulai catat penjualan hari ini juga.</p>
                                <div class="xb-item--btn ul_li mt-45 gap-10">
                                    <Link class="thm-btn fintech-btn" href="/register">Mulai gratis</Link>
                                    <Link class="thm-btn fintech-btn fintech-btn--white" href="/panduan">Lihat panduan</Link>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-4 mt-30">
                            <div class="fs-footer-widget fs-footer-scanner">
                                <div class="xb-item--inner">
                                    <p class="xb-item--text">Pindai kode QR untuk unduh aplikasi Android</p>
                                    <div class="xb-item--img">
                                        <img
                                            width="1345"
                                            height="1443"
                                            src="/googleplay-qr-sm.webp"
                                            alt="QR Code Google Play"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <ul class="xb-item--social list-unstyled ul_li">
                                        <li>
                                            <a
                                                href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea&pli=1"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label="Unduh Kasir UMKM by MaccaPOS di Google Play"
                                                ><i class="fa-brands fa-google-play" aria-hidden="true"></i
                                            ></a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 mt-30">
                            <div class="fs-footer-widget">
                                <p class="fs-widget-title">Hubungi Kami</p>
                                <ul class="xb-item--contact list-unstyled">
                                    <li>
                                        <span><i class="fa-solid fa-phone"></i></span><a href="tel:+6285242766676">+62 852-4276-6676</a>
                                    </li>
                                    <li>
                                        <span><i class="fa-solid fa-envelope"></i></span><a href="mailto:info@umkmkasir.id">info@umkmkasir.id</a>
                                    </li>
                                    <li>
                                        <span><i class="fa-solid fa-location-dot"></i></span
                                        ><span>Jl. Benteng Somba Opu, Tanjung Permai No 30 Gowa, Sulawesi Selatan</span>
                                    </li>
                                    <li>
                                        <span><i class="fa-solid fa-clock"></i></span><span>Senin – Jumat, 08.00 – 17.00 WITA</span>
                                    </li>
                                </ul>
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
                        <img width="255" height="176" :src="`${base}/img/shape/footer_shape01.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                    <div class="shape shape--4">
                        <img width="255" height="176" :src="`${base}/img/shape/footer_shape02.png`" alt="" loading="lazy" decoding="async" />
                    </div>
                </div>
            </div>
            <div class="dm-social-media-wrap fs-social-midea-warap">
                <div class="xb-social-media-item fs-social-media-item ul_li_between">
                    <div class="xb-item--holder ul_li">
                        <div class="xb-item--icon">
                            <i class="fa-brands fa-whatsapp"></i>
                        </div>
                        <span class="xb-item--name">WhatsApp</span>
                    </div>
                    <span class="xb-item--arrow">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M20.5195 6.83892C20.7786 7.09808 20.9242 7.44953 20.9242 7.81607C20.9243 8.18257 20.7786 8.53406 20.5195 8.79321C20.2603 9.05237 20.1147 9.40386 20.1147 9.77036C20.1147 10.1369 20.2603 10.4884 20.5195 10.7475C20.648 10.8757 20.7499 11.028 20.8193 11.1957C20.8888 11.3634 20.9245 11.5431 20.9242 11.7247L20.9243 15.6333L20.9242 19.5419C20.9242 19.9084 20.7786 20.2599 20.5195 20.5191C20.2603 20.7782 19.9088 20.9238 19.5423 20.9238C19.1758 20.9238 18.8243 20.7782 18.5652 20.519C18.306 20.2599 18.1604 19.9084 18.1604 19.5419L18.1604 15.6333L18.1604 11.7247C18.1601 11.5432 18.1958 11.3634 18.2653 11.1957C18.3347 11.028 18.4367 10.8757 18.5652 10.7475C18.8244 10.4884 18.9699 10.1368 18.9699 9.77036C18.9699 9.40382 18.8244 9.05237 18.5652 8.79317C18.306 8.53402 17.9546 8.38845 17.588 8.38845C17.2215 8.38841 16.87 8.53402 16.6109 8.79317C16.4827 8.92172 16.3304 9.02364 16.1627 9.09311C15.995 9.16253 15.8152 9.19819 15.6337 9.19798L11.7251 9.19794L7.81659 9.19793C7.6349 9.19827 7.45492 9.16279 7.28696 9.0935C7.11901 9.02421 6.96638 8.92246 6.83779 8.79412C6.70919 8.66578 6.60718 8.51332 6.53758 8.34549C6.46799 8.17765 6.43216 7.99775 6.43212 7.8161C6.43212 7.63441 6.46794 7.45452 6.53754 7.28668C6.60715 7.11885 6.70915 6.96641 6.83773 6.83806C6.9663 6.7097 7.11897 6.60792 7.28688 6.53865C7.45484 6.46934 7.6348 6.43384 7.81648 6.43417L11.7251 6.43414L15.6337 6.43415C15.8152 6.43391 15.995 6.46956 16.1627 6.53903C16.3304 6.6085 16.4827 6.71037 16.6109 6.83888C16.87 7.09803 17.2215 7.24364 17.588 7.24368C17.9545 7.24364 18.306 7.09803 18.5652 6.83888C18.6935 6.71054 18.8459 6.6088 19.0135 6.53933C19.1811 6.4699 19.3609 6.43416 19.5423 6.43416C19.7238 6.43416 19.9035 6.4699 20.0711 6.53933C20.2388 6.6088 20.3912 6.71059 20.5195 6.83892ZM14.6565 10.7475L6.83933 18.5647C6.58017 18.8239 6.43462 19.1754 6.43459 19.5419C6.43461 19.9084 6.58017 20.2599 6.83936 20.5191C7.09852 20.7782 7.44998 20.9238 7.8165 20.9238C8.18302 20.9238 8.53451 20.7782 8.79366 20.519L16.6109 12.7018C16.87 12.4427 17.0156 12.0912 17.0156 11.7247C17.0156 11.3582 16.87 11.0067 16.6109 10.7475C16.3517 10.4884 16.0002 10.3428 15.6337 10.3427C15.2672 10.3428 14.9157 10.4884 14.6565 10.7475Z"
                                fill="#0D1738"
                            />
                        </svg>
                    </span>
                    <a class="xb-overlay" href="https://wa.me/6285242766676" target="_blank" rel="noopener noreferrer" aria-label="Chat Kasir UMKM by MaccaPOS lewat WhatsApp"></a>
                </div>
                <div class="xb-social-media-item fs-social-media-item ul_li_between">
                    <div class="xb-item--holder ul_li">
                        <div class="xb-item--icon">
                            <i class="fa-brands fa-google-play"></i>
                        </div>
                        <span class="xb-item--name">Google Play</span>
                    </div>
                    <span class="xb-item--arrow">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M20.5195 6.83892C20.7786 7.09808 20.9242 7.44953 20.9242 7.81607C20.9243 8.18257 20.7786 8.53406 20.5195 8.79321C20.2603 9.05237 20.1147 9.40386 20.1147 9.77036C20.1147 10.1369 20.2603 10.4884 20.5195 10.7475C20.648 10.8757 20.7499 11.028 20.8193 11.1957C20.8888 11.3634 20.9245 11.5431 20.9242 11.7247L20.9243 15.6333L20.9242 19.5419C20.9242 19.9084 20.7786 20.2599 20.5195 20.5191C20.2603 20.7782 19.9088 20.9238 19.5423 20.9238C19.1758 20.9238 18.8243 20.7782 18.5652 20.519C18.306 20.2599 18.1604 19.9084 18.1604 19.5419L18.1604 15.6333L18.1604 11.7247C18.1601 11.5432 18.1958 11.3634 18.2653 11.1957C18.3347 11.028 18.4367 10.8757 18.5652 10.7475C18.8244 10.4884 18.9699 10.1368 18.9699 9.77036C18.9699 9.40382 18.8244 9.05237 18.5652 8.79317C18.306 8.53402 17.9546 8.38845 17.588 8.38845C17.2215 8.38841 16.87 8.53402 16.6109 8.79317C16.4827 8.92172 16.3304 9.02364 16.1627 9.09311C15.995 9.16253 15.8152 9.19819 15.6337 9.19798L11.7251 9.19794L7.81659 9.19793C7.6349 9.19827 7.45492 9.16279 7.28696 9.0935C7.11901 9.02421 6.96638 8.92246 6.83779 8.79412C6.70919 8.66578 6.60718 8.51332 6.53758 8.34549C6.46799 8.17765 6.43216 7.99775 6.43212 7.8161C6.43212 7.63441 6.46794 7.45452 6.53754 7.28668C6.60715 7.11885 6.70915 6.96641 6.83773 6.83806C6.9663 6.7097 7.11897 6.60792 7.28688 6.53865C7.45484 6.46934 7.6348 6.43384 7.81648 6.43417L11.7251 6.43414L15.6337 6.43415C15.8152 6.43391 15.995 6.46956 16.1627 6.53903C16.3304 6.6085 16.4827 6.71037 16.6109 6.83888C16.87 7.09803 17.2215 7.24364 17.588 7.24368C17.9545 7.24364 18.306 7.09803 18.5652 6.83888C18.6935 6.71054 18.8459 6.6088 19.0135 6.53933C19.1811 6.4699 19.3609 6.43416 19.5423 6.43416C19.7238 6.43416 19.9035 6.4699 20.0711 6.53933C20.2388 6.6088 20.3912 6.71059 20.5195 6.83892ZM14.6565 10.7475L6.83933 18.5647C6.58017 18.8239 6.43462 19.1754 6.43459 19.5419C6.43461 19.9084 6.58017 20.2599 6.83936 20.5191C7.09852 20.7782 7.44998 20.9238 7.8165 20.9238C8.18302 20.9238 8.53451 20.7782 8.79366 20.519L16.6109 12.7018C16.87 12.4427 17.0156 12.0912 17.0156 11.7247C17.0156 11.3582 16.87 11.0067 16.6109 10.7475C16.3517 10.4884 16.0002 10.3428 15.6337 10.3427C15.2672 10.3428 14.9157 10.4884 14.6565 10.7475Z"
                                fill="#0D1738"
                            />
                        </svg>
                    </span>
                    <a class="xb-overlay" href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea" target="_blank" rel="noopener noreferrer" aria-label="Unduh Kasir UMKM by MaccaPOS di Google Play"></a>
                </div>
                <div class="xb-social-media-item fs-social-media-item ul_li_between">
                    <div class="xb-item--holder ul_li">
                        <div class="xb-item--icon">
                            <i class="fa-solid fa-envelope"></i>
                        </div>
                        <span class="xb-item--name">Email</span>
                    </div>
                    <span class="xb-item--arrow">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M20.5195 6.83892C20.7786 7.09808 20.9242 7.44953 20.9242 7.81607C20.9243 8.18257 20.7786 8.53406 20.5195 8.79321C20.2603 9.05237 20.1147 9.40386 20.1147 9.77036C20.1147 10.1369 20.2603 10.4884 20.5195 10.7475C20.648 10.8757 20.7499 11.028 20.8193 11.1957C20.8888 11.3634 20.9245 11.5431 20.9242 11.7247L20.9243 15.6333L20.9242 19.5419C20.9242 19.9084 20.7786 20.2599 20.5195 20.5191C20.2603 20.7782 19.9088 20.9238 19.5423 20.9238C19.1758 20.9238 18.8243 20.7782 18.5652 20.519C18.306 20.2599 18.1604 19.9084 18.1604 19.5419L18.1604 15.6333L18.1604 11.7247C18.1601 11.5432 18.1958 11.3634 18.2653 11.1957C18.3347 11.028 18.4367 10.8757 18.5652 10.7475C18.8244 10.4884 18.9699 10.1368 18.9699 9.77036C18.9699 9.40382 18.8244 9.05237 18.5652 8.79317C18.306 8.53402 17.9546 8.38845 17.588 8.38845C17.2215 8.38841 16.87 8.53402 16.6109 8.79317C16.4827 8.92172 16.3304 9.02364 16.1627 9.09311C15.995 9.16253 15.8152 9.19819 15.6337 9.19798L11.7251 9.19794L7.81659 9.19793C7.6349 9.19827 7.45492 9.16279 7.28696 9.0935C7.11901 9.02421 6.96638 8.92246 6.83779 8.79412C6.70919 8.66578 6.60718 8.51332 6.53758 8.34549C6.46799 8.17765 6.43216 7.99775 6.43212 7.8161C6.43212 7.63441 6.46794 7.45452 6.53754 7.28668C6.60715 7.11885 6.70915 6.96641 6.83773 6.83806C6.9663 6.7097 7.11897 6.60792 7.28688 6.53865C7.45484 6.46934 7.6348 6.43384 7.81648 6.43417L11.7251 6.43414L15.6337 6.43415C15.8152 6.43391 15.995 6.46956 16.1627 6.53903C16.3304 6.6085 16.4827 6.71037 16.6109 6.83888C16.87 7.09803 17.2215 7.24364 17.588 7.24368C17.9545 7.24364 18.306 7.09803 18.5652 6.83888C18.6935 6.71054 18.8459 6.6088 19.0135 6.53933C19.1811 6.4699 19.3609 6.43416 19.5423 6.43416C19.7238 6.43416 19.9035 6.4699 20.0711 6.53933C20.2388 6.6088 20.3912 6.71059 20.5195 6.83892ZM14.6565 10.7475L6.83933 18.5647C6.58017 18.8239 6.43462 19.1754 6.43459 19.5419C6.43461 19.9084 6.58017 20.2599 6.83936 20.5191C7.09852 20.7782 7.44998 20.9238 7.8165 20.9238C8.18302 20.9238 8.53451 20.7782 8.79366 20.519L16.6109 12.7018C16.87 12.4427 17.0156 12.0912 17.0156 11.7247C17.0156 11.3582 16.87 11.0067 16.6109 10.7475C16.3517 10.4884 16.0002 10.3428 15.6337 10.3427C15.2672 10.3428 14.9157 10.4884 14.6565 10.7475Z"
                                fill="#0D1738"
                            />
                        </svg>
                    </span>
                    <a class="xb-overlay" href="mailto:info@umkmkasir.id" aria-label="Kirim email ke Kasir UMKM by MaccaPOS"></a>
                </div>
                <div class="xb-social-media-item fs-social-media-item ul_li_between">
                    <div class="xb-item--holder ul_li">
                        <div class="xb-item--icon">
                            <i class="fa-solid fa-book-open"></i>
                        </div>
                        <span class="xb-item--name">Panduan</span>
                    </div>
                    <span class="xb-item--arrow">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M20.5195 6.83892C20.7786 7.09808 20.9242 7.44953 20.9242 7.81607C20.9243 8.18257 20.7786 8.53406 20.5195 8.79321C20.2603 9.05237 20.1147 9.40386 20.1147 9.77036C20.1147 10.1369 20.2603 10.4884 20.5195 10.7475C20.648 10.8757 20.7499 11.028 20.8193 11.1957C20.8888 11.3634 20.9245 11.5431 20.9242 11.7247L20.9243 15.6333L20.9242 19.5419C20.9242 19.9084 20.7786 20.2599 20.5195 20.5191C20.2603 20.7782 19.9088 20.9238 19.5423 20.9238C19.1758 20.9238 18.8243 20.7782 18.5652 20.519C18.306 20.2599 18.1604 19.9084 18.1604 19.5419L18.1604 15.6333L18.1604 11.7247C18.1601 11.5432 18.1958 11.3634 18.2653 11.1957C18.3347 11.028 18.4367 10.8757 18.5652 10.7475C18.8244 10.4884 18.9699 10.1368 18.9699 9.77036C18.9699 9.40382 18.8244 9.05237 18.5652 8.79317C18.306 8.53402 17.9546 8.38845 17.588 8.38845C17.2215 8.38841 16.87 8.53402 16.6109 8.79317C16.4827 8.92172 16.3304 9.02364 16.1627 9.09311C15.995 9.16253 15.8152 9.19819 15.6337 9.19798L11.7251 9.19794L7.81659 9.19793C7.6349 9.19827 7.45492 9.16279 7.28696 9.0935C7.11901 9.02421 6.96638 8.92246 6.83779 8.79412C6.70919 8.66578 6.60718 8.51332 6.53758 8.34549C6.46799 8.17765 6.43216 7.99775 6.43212 7.8161C6.43212 7.63441 6.46794 7.45452 6.53754 7.28668C6.60715 7.11885 6.70915 6.96641 6.83773 6.83806C6.9663 6.7097 7.11897 6.60792 7.28688 6.53865C7.45484 6.46934 7.6348 6.43384 7.81648 6.43417L11.7251 6.43414L15.6337 6.43415C15.8152 6.43391 15.995 6.46956 16.1627 6.53903C16.3304 6.6085 16.4827 6.71037 16.6109 6.83888C16.87 7.09803 17.2215 7.24364 17.588 7.24368C17.9545 7.24364 18.306 7.09803 18.5652 6.83888C18.6935 6.71054 18.8459 6.6088 19.0135 6.53933C19.1811 6.4699 19.3609 6.43416 19.5423 6.43416C19.7238 6.43416 19.9035 6.4699 20.0711 6.53933C20.2388 6.6088 20.3912 6.71059 20.5195 6.83892ZM14.6565 10.7475L6.83933 18.5647C6.58017 18.8239 6.43462 19.1754 6.43459 19.5419C6.43461 19.9084 6.58017 20.2599 6.83936 20.5191C7.09852 20.7782 7.44998 20.9238 7.8165 20.9238C8.18302 20.9238 8.53451 20.7782 8.79366 20.519L16.6109 12.7018C16.87 12.4427 17.0156 12.0912 17.0156 11.7247C17.0156 11.3582 16.87 11.0067 16.6109 10.7475C16.3517 10.4884 16.0002 10.3428 15.6337 10.3427C15.2672 10.3428 14.9157 10.4884 14.6565 10.7475Z"
                                fill="#0D1738"
                            />
                        </svg>
                    </span>
                    <a class="xb-overlay" href="/panduan" aria-label="Baca panduan penggunaan Kasir UMKM by MaccaPOS"></a>
                </div>
            </div>
            <div class="xb-footer_bottom fs-footer_bottom ul_li_between">
                <p>Hak cipta &copy; 2026 <Link href="/">Kasir UMKM by MaccaPOS</Link>. Semua hak dilindungi.</p>
                <p>
                    <Link href="/terms">Syarat &amp; Ketentuan</Link> &middot; <Link href="/privacy">Kebijakan Privasi</Link> &middot;
                    <Link href="/refund">Kebijakan Refund</Link> &middot;
                    <Link href="/panduan">Panduan</Link>
                </p>
            </div>
        </div>
    </footer>
    <!-- footer end -->
</template>
