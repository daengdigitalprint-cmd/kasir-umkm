<script setup lang="ts">
import '@/types/pwa-window';
import { onMounted, ref } from 'vue';
import { X } from 'lucide-vue-next';
import Swal from 'sweetalert2';

const showBanner = ref(false);
const isIOS = ref(false);

onMounted(() => {
    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    if (!isMobile) {
        return;
    }

    isIOS.value = /iPad|iPhone|iPod/.test(navigator.userAgent) && !(window as Window & { MSStream?: unknown }).MSStream;

    const isStandalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
    if (isStandalone) {
        return;
    }

    setTimeout(() => {
        const dismissed = sessionStorage.getItem('pwa-dismissed-session');
        const hasPrompt = window.deferredPwaPrompt != null;
        if (!dismissed && (isIOS.value || hasPrompt)) {
            showBanner.value = true;
        }
    }, 3000);

    window.addEventListener('appinstalled', () => {
        showBanner.value = false;
        window.deferredPwaPrompt = null;
    });
});

async function installPwa() {
    if (isIOS.value) {
        await Swal.fire({
            icon: 'info',
            title: 'Pasang di iPhone / iPad',
            html: '<p class="text-left text-sm">1. Ketuk tombol <strong>Bagikan</strong> (kotak dengan panah ke atas).<br/>2. Pilih <strong>Tambahkan ke Layar Utama</strong>.</p>',
            confirmButtonText: 'Mengerti',
            confirmButtonColor: '#7c3aed',
        });
        showBanner.value = false;
        return;
    }

    const prompt = window.deferredPwaPrompt;
    if (!prompt) {
        await Swal.fire({
            icon: 'info',
            title: 'Install aplikasi',
            text: 'Gunakan menu browser (titik tiga) lalu pilih "Install app" atau "Install aplikasi".',
            confirmButtonText: 'OK',
            confirmButtonColor: '#7c3aed',
        });
        return;
    }

    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    if (outcome === 'accepted') {
        showBanner.value = false;
    }
    window.deferredPwaPrompt = null;
}

function dismissBanner() {
    showBanner.value = false;
    sessionStorage.setItem('pwa-dismissed-session', 'true');
}
</script>

<template>
    <div
        v-if="showBanner"
        class="animate-in fade-in slide-in-from-top-4 fixed top-20 left-1/2 z-[60] w-full max-w-[440px] -translate-x-1/2 px-4 duration-700"
    >
        <div
            class="flex items-center justify-between gap-3 rounded-[1.25rem] border-[3px] border-[#3E1F00] bg-[#fffef7] p-3.5 shadow-[8px_8px_0_0_#ddd6fe] sm:gap-4 sm:p-4"
        >
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <div
                    class="flex h-11 w-11 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl border-[3px] border-[#3E1F00] bg-[#fffef7] shadow-[4px_4px_0_0_#ddd6fe]"
                >
                    <img src="/logo.png" alt="" class="h-7 w-7 object-contain" width="28" height="28" decoding="async" />
                </div>
                <div class="min-w-0">
                    <h4
                        class="mb-1 font-display text-[11px] leading-none font-extrabold tracking-[0.2em] text-[#7C3AED] uppercase"
                    >
                        Kasir UMKM by MaccaPOS
                    </h4>
                    <p class="text-[10px] leading-tight font-medium text-lp-muted">
                        Pasang di layar utama Anda.
                    </p>
                </div>
            </div>

            <div class="flex flex-shrink-0 items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-full border-[3px] border-[#3E1F00] bg-[#7c3aed] px-3.5 py-2 font-display text-[10px] font-extrabold tracking-widest text-white uppercase shadow-[0_6px_0_0_#ddd6fe] transition-[transform,box-shadow,background-color] duration-100 hover:bg-[#6d28d9] active:translate-y-1 active:shadow-[0_2px_0_0_#ddd6fe] sm:px-4 sm:py-2.5"
                    @click="installPwa"
                >
                    Pasang
                </button>
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-xl border-[3px] border-[#3E1F00]/25 bg-white text-[#3E1F00]/50 transition-[transform,background-color] hover:border-[#3E1F00]/40 hover:bg-[#fffbeb] hover:text-[#3E1F00]/70 active:scale-95"
                    aria-label="Tutup"
                    @click="dismissBanner"
                >
                    <X class="size-4" stroke-width="2.5" />
                </button>
            </div>
        </div>
    </div>
</template>
