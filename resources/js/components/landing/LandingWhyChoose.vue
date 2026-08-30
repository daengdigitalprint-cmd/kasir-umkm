<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    assetsBase?: string;
}>();

const base = computed(() => props.assetsBase ?? '/buyer-assets');

type FaqItem = {
    id: string;
    heading: string;
    body: string;
};

const faqItems: FaqItem[] = [
    {
        id: 'flexibel',
        heading: 'Harga transparan dan fleksibel',
        body: 'Mulai gratis untuk toko kecil, dan naik kelas hanya saat Anda siap. Tidak ada biaya tersembunyi, tidak ada kontrak panjang yang mengikat.',
    },
    {
        id: 'kasir',
        heading: 'Dirancang untuk ritel & F&B Indonesia',
        body: 'Struk, QRIS, shift kasir, dapur, hingga produk kedaluwarsa — semua alur yang umum dipakai pelaku UMKM sudah tersedia bawaan.',
    },
    {
        id: 'umkm',
        heading: 'Cocok untuk UMKM dan usaha rintisan',
        body: 'Mudah dipakai tanpa pelatihan IT khusus. Tampilan sederhana, panduan Bahasa Indonesia, dan tim support yang siap membantu lewat WhatsApp.',
    },
];

const openId = ref<string>(faqItems[0].id);

function toggle(id: string): void {
    openId.value = openId.value === id ? '' : id;
}
</script>

<template>
    <section class="gt-why-choose-us-section-3">
        <div class="gt-why-choose-us-wrapper-3 section-padding">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-6">
                        <div class="gt-choose-us-image appear_left">
                            <img src="/user-pos-1024x781.png" alt="UMKM Kasir di perangkat Anda" loading="lazy" decoding="async" />
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="gt-choose-us-content">
                            <div class="gt-section-title style-3 mb-0">
                                <h6 class="text-white tt-capitalize wow fadeInUp">Mengapa UMKM Kasir</h6>
                                <h2 class="text-white char-animation">
                                    Berhenti pakai catatan manual.
                                    Beralih ke UMKM Kasir.
                                </h2>
                            </div>
                            <div class="faq-items mt-0 ms-0">
                                <div class="accordion" id="umkmKasirFaq">
                                    <div
                                        v-for="(item, idx) in faqItems"
                                        :key="item.id"
                                        class="accordion-item wow fadeInUp"
                                        :data-wow-delay="`.${3 + idx * 2}s`"
                                    >
                                        <h2 class="accordion-header" :id="`heading-${item.id}`">
                                            <button
                                                class="accordion-button"
                                                :class="{ collapsed: openId !== item.id }"
                                                type="button"
                                                :aria-expanded="openId === item.id"
                                                :aria-controls="`collapse-${item.id}`"
                                                @click="toggle(item.id)"
                                            >
                                                {{ item.heading }}
                                            </button>
                                        </h2>
                                        <div
                                            :id="`collapse-${item.id}`"
                                            class="accordion-collapse collapse"
                                            :class="{ show: openId === item.id }"
                                            :aria-labelledby="`heading-${item.id}`"
                                        >
                                            <div class="accordion-body">
                                                <p class="gt-text mb-0">{{ item.body }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <Link href="/register" class="gt-theme-btn style-3 wow fadeInUp" data-wow-delay=".9s">
                                Mulai sekarang
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.accordion-body {
    display: block;
    padding: 1rem 1.25rem;
}

.accordion-body p {
    color: #666;
    line-height: 1.6;
    margin: 0;
    display: block;
    opacity: 1;
    visibility: visible;
}

.accordion-collapse {
    transition: height 0.3s ease;
}

.accordion-collapse:not(.show) {
    display: none;
}

.accordion-collapse.show {
    display: block;
}
</style>
