<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    maskotUrl?: string;
}>();

const maskotUrl = computed(() => props.maskotUrl ?? '/logo-macca.png');

const waPhoneDigits = '6285242766676';
const waPrefillMessage =
    'Halo, saya baru mengunjungi situs UMKM Kasir dan ingin bertanya tentang paket atau demo singkat. Mohon arahannya, terima kasih.';

const waChatUrl = computed(
    () => `https://wa.me/${waPhoneDigits}?text=${encodeURIComponent(waPrefillMessage)}`,
);

const waPanelOpen = ref(false);
</script>

<template>
    <!-- header start -->
    <header id="xb-header-area" class="header-area header-area--four">
        <div class="xb-header">
            <div class="container mxw-1330">
                <div class="header-wrapper">
                    <div class="header__wrap border-btm ul_li_between pos-rel">
                        <div class="xb-header-logo">
                            <Link href="/" class="logo d-flex align-items-center">
                                <img :src="maskotUrl" alt="logo UMKM Kasir" width="3194" height="1037" style="height:48px;width:auto" />
                            </Link>
                        </div>
                        <div class="main-menu__wrap navbar navbar-expand-lg p-0">
                            <nav class="main-menu collapse navbar-collapse">
                                <ul>
                                    <li class="active">
                                        <a href="#top"><span>Beranda</span></a>
                                    </li>
                                    <li>
                                        <a href="#fitur"><span>Fitur</span></a>
                                    </li>
                                    <li>
                                        <a href="#manfaat"><span>Manfaat</span></a>
                                    </li>
                                    <li>
                                        <a href="#harga"><span>Harga</span></a>
                                    </li>
                                    <li>
                                        <a href="#faq"><span>FAQ</span></a>
                                    </li>
                                    <li>
                                        <Link href="/panduan"><span>Panduan</span></Link>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <div class="xb-header-btn d-lg-block d-none">
                            <Link class="thm-btn fintech-btn" href="/login">Masuk</Link>
                            <Link class="thm-btn fintech-btn" href="/register">Coba gratis</Link>
                        </div>
                        <div class="header-bar-mobile side-menu d-lg-none">
                            <a class="xb-nav-mobile" href="javascript:void(0);">
                                <span></span>
                                <span></span>
                                <span></span>
                            </a>
                        </div>
                        <div class="xb-dot-box">
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
                <div class="xb-header-wrap">
                    <div class="xb-header-menu">
                        <div class="xb-header-menu-scroll">
                            <div class="xb-menu-close xb-hide-xl xb-close"></div>
                            <div class="xb-logo-mobile xb-hide-xl">
                                <Link href="/" class="logo d-flex align-items-center">
                                    <img :src="maskotUrl" alt="logo UMKM Kasir" width="3194" height="1037" style="height:48px;width:auto" />
                                </Link>
                            </div>
                            <nav class="xb-header-nav">
                                <ul class="xb-menu-primary clearfix">
                                    <li class="active">
                                        <a href="/#top"><span>Beranda</span></a>
                                    </li>
                                    <li>
                                        <a href="#fitur"><span>Fitur</span></a>
                                    </li>
                                    <li>
                                        <a href="#manfaat"><span>Manfaat</span></a>
                                    </li>
                                    <li>
                                        <a href="#harga"><span>Harga</span></a>
                                    </li>
                                    <li>
                                        <a href="#faq"><span>FAQ</span></a>
                                    </li>
                                    <li>
                                        <Link href="/panduan"><span>Panduan</span></Link>
                                    </li>
                                    <li>
                                        <Link href="/privacy"><span>Kebijakan Privasi</span></Link>
                                    </li>
                                    <li>
                                        <Link href="/login"><span>Masuk</span></Link>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                    <div class="xb-header-menu-backdrop"></div>
                </div>
            </div>
        </div>
    </header>
    <!-- header end -->

    <!-- Widget WhatsApp (toggle) -->
    <div class="wa-landing-wrapper">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 scale-95 opacity-0"
            enter-to-class="translate-y-0 scale-100 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 scale-100 opacity-100"
            leave-to-class="translate-y-2 scale-95 opacity-0"
        >
            <div
                v-show="waPanelOpen"
                id="wa-landing-panel"
                class="wa-landing-panel"
                role="dialog"
                aria-label="Chat WhatsApp"
            >
                <p class="wa-landing-panel-title">Chat dengan kami</p>
                <p class="wa-landing-panel-body">
                    Tanya paket, demo, atau migrasi data — tim kami siap bantu lewat WhatsApp.
                </p>
                <a
                    :href="waChatUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="wa-landing-panel-btn"
                >
                    <svg class="wa-landing-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path
                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
                        />
                    </svg>
                    Buka WhatsApp
                </a>
                <p class="wa-landing-panel-hint">Pratinjau pesan sudah disiapkan; Anda bisa mengedit sebelum kirim.</p>
            </div>
        </Transition>
        <button
            type="button"
            class="wa-landing-toggle"
            :aria-expanded="waPanelOpen"
            aria-controls="wa-landing-panel"
            @click="waPanelOpen = !waPanelOpen"
        >
            <span class="sr-only">{{ waPanelOpen ? 'Tutup panel WhatsApp' : 'Buka panel WhatsApp' }}</span>
            <svg
                v-if="!waPanelOpen"
                class="wa-landing-toggle-svg"
                viewBox="0 0 24 24"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
                />
            </svg>
            <i v-else class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
</template>

<style scoped>
/* ===== WhatsApp Widget ===== */
.wa-landing-wrapper {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 60;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.75rem;
    pointer-events: none;
}
@media (min-width: 768px) {
    .wa-landing-wrapper {
        bottom: 2rem;
        right: 2rem;
    }
}
.wa-landing-panel {
    pointer-events: auto;
    width: min(calc(100vw - 3rem), 18rem);
    border: 2px solid rgba(62, 31, 0, 0.1);
    background: #f8fafc;
    padding: 1rem;
    border-radius: 1rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
}
.wa-landing-panel-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #3e1f00;
    margin: 0;
}
.wa-landing-panel-body {
    margin: 0.5rem 0 0;
    font-size: 0.75rem;
    line-height: 1.5;
    color: #64748b;
}
.wa-landing-panel-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
    background: #25d366;
    padding: 0.625rem 1rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 700;
    color: #ffffff;
    text-decoration: none;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}
.wa-landing-panel-btn:hover {
    background: #20bd5a;
}
.wa-landing-panel-hint {
    margin: 0.5rem 0 0;
    text-align: center;
    font-size: 10px;
    color: rgba(100, 116, 139, 0.8);
}
.wa-landing-icon {
    height: 1.25rem;
    width: 1.25rem;
    flex-shrink: 0;
}
.wa-landing-toggle {
    pointer-events: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 9999px;
    background: #25d366;
    color: #ffffff;
    border: 0;
    cursor: pointer;
    box-shadow: 0 10px 25px -5px rgba(37, 211, 102, 0.4);
    transition: background-color 0.15s ease;
}
.wa-landing-toggle:hover {
    background: #20bd5a;
}
.wa-landing-toggle-svg {
    height: 2rem;
    width: 2rem;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Header tombol - beri jarak antar tombol */
:deep(.xb-header-btn) {
    display: flex;
    gap: 10px;
}
</style>
