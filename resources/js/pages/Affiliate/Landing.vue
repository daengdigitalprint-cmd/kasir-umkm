<script setup lang="ts">
import AffiliateLayout from '@/layouts/AffiliateLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Banknote, Link2, Minus, Plus, Share2, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Rules {
    percent: number;
    months: number;
    cookie_days: number;
    hold_days: number;
    min_payout: number;
}

interface Example {
    plan: string;
    price: number;
    commission: number;
    cycle: string;
}

const props = defineProps<{
    rules: Rules;
    examples: Example[];
    viewer: { name: string } | null;
}>();

const rupiah = (n: number) => 'Rp' + Math.round(n).toLocaleString('id-ID');
const percentLabel = computed(() => props.rules.percent.toLocaleString('id-ID'));

/* Simulasi sederhana: berapa toko yang diajak × komisi paket pertama. */
const stores = ref(5);
const mainExample = computed(() => props.examples[0] ?? null);
const simulated = computed(() => (mainExample.value ? mainExample.value.commission * stores.value : 0));
const step = (delta: number) => {
    stores.value = Math.min(100, Math.max(1, stores.value + delta));
};

/* Pengguna yang sudah masuk (mis. pemilik toko) bergabung dengan akun yang sama. */
const joinForm = useForm({ agree: false });
const join = () => joinForm.post('/afiliasi/gabung');

const steps = [
    { icon: UserPlus, title: 'Daftar gratis', text: 'Isi nama, email, dan nomor WhatsApp. Tidak perlu punya toko.' },
    { icon: Share2, title: 'Bagikan link Anda', text: 'Kirim link ke teman, grup WhatsApp, atau media sosial.' },
    { icon: Banknote, title: 'Terima komisi', text: 'Setiap toko yang berlangganan lewat link Anda menghasilkan komisi.' },
];
</script>

<template>
    <AffiliateLayout title="Program Afiliasi">
        <div class="space-y-6">
            <!-- Hero -->
            <section class="relative overflow-hidden rounded-2xl bg-[#d4ceff] p-6 text-slate-900 sm:p-10">
                <!-- Latar sama dengan kartu fitur di beranda: sorot cahaya di atas, kotak-kotak di kiri-kanan bawah, pendar dari bawah -->
                <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                    <div class="shape shape--1 absolute top-0 left-0 h-full max-h-[337px]">
                        <img width="195" height="337" src="/sastik-assets/img/shape/das_shape03.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                    </div>
                    <div class="shape shape--2 absolute top-0 right-0 h-full max-h-[337px]">
                        <img width="195" height="337" src="/sastik-assets/img/shape/das_shape04.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                    </div>
                    <div class="shape shape--3 absolute bottom-0 left-0 w-28 sm:w-44">
                        <img width="255" height="264" src="/sastik-assets/img/shape/das_shape01.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                    <div class="shape shape--4 absolute right-0 bottom-0 w-28 sm:w-44">
                        <img width="255" height="264" src="/sastik-assets/img/shape/das_shape02.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                    <div class="shape shape--5 absolute bottom-0 left-1/2 w-full -translate-x-1/2">
                        <img width="1328" height="388" src="/sastik-assets/img/shape/circle-blur.webp" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                    </div>
                </div>

                <div class="relative z-10 max-w-2xl">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-1 text-xs font-bold tracking-wide text-primary">
                        <Link2 class="h-3.5 w-3.5" /> Program Afiliasi Kasir UMKM
                    </span>
                    <h1 class="mt-4 text-3xl leading-tight font-black tracking-tight text-slate-900 sm:text-4xl">
                        Ajak teman pakai Kasir UMKM, dapat komisi {{ percentLabel }}%
                    </h1>
                    <p class="mt-3 text-base leading-relaxed text-slate-700">
                        Bagikan link Anda. Setiap kali toko yang Anda ajak membayar langganan, Anda mendapat {{ percentLabel }}% dari pembayarannya, selama
                        {{ rules.months }} bulan.
                    </p>

                    <!-- Belum masuk: daftar akun afiliator -->
                    <div v-if="!viewer" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Link
                            href="/afiliasi/daftar"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-bold text-white shadow-lg shadow-primary/25 transition-transform hover:scale-[1.02] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2"
                        >
                            Daftar jadi afiliator <ArrowRight class="h-5 w-5" />
                        </Link>
                        <Link href="/login" class="text-center text-sm font-semibold text-slate-700 underline underline-offset-4 hover:text-slate-900">
                            Sudah punya akun Kasir UMKM? Masuk dulu
                        </Link>
                    </div>

                    <!-- Sudah masuk: gabung dengan akun yang sama -->
                    <form v-else class="mt-6 rounded-2xl bg-white/70 p-4 backdrop-blur-sm sm:p-5" @submit.prevent="join">
                        <p class="text-sm font-semibold">Halo {{ viewer.name }}, Anda bisa bergabung dengan akun ini.</p>
                        <label class="mt-3 flex cursor-pointer items-start gap-3 text-sm text-slate-700">
                            <input v-model="joinForm.agree" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-primary" />
                            <span>Saya sudah membaca dan setuju dengan ketentuan program di bawah.</span>
                        </label>
                        <p v-if="joinForm.errors.agree" class="mt-2 text-sm font-semibold text-red-600" role="alert">{{ joinForm.errors.agree }}</p>
                        <button
                            type="submit"
                            :disabled="joinForm.processing"
                            class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-bold text-white shadow-lg shadow-primary/25 transition-transform hover:scale-[1.02] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:opacity-60 sm:w-auto"
                        >
                            {{ joinForm.processing ? 'Memproses…' : 'Gabung sekarang' }} <ArrowRight v-if="!joinForm.processing" class="h-5 w-5" />
                        </button>
                    </form>
                </div>
            </section>

            <!-- Cara kerja -->
            <section aria-labelledby="cara-kerja">
                <h2 id="cara-kerja" class="mb-3 text-lg font-bold text-slate-900">Cara kerjanya</h2>
                <ol class="grid gap-3 sm:grid-cols-3">
                    <li v-for="(s, i) in steps" :key="s.title" class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <component :is="s.icon" class="h-5 w-5" />
                            </span>
                            <span class="text-xs font-black tracking-widest text-slate-400 uppercase">Langkah {{ i + 1 }}</span>
                        </div>
                        <h3 class="mt-3 text-base font-bold text-slate-900">{{ s.title }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ s.text }}</p>
                    </li>
                </ol>
            </section>

            <!-- Simulasi -->
            <section v-if="mainExample" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="simulasi">
                <h2 id="simulasi" class="text-lg font-bold text-slate-900">Berapa yang bisa Anda dapat?</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Paket {{ mainExample.plan }} {{ rupiah(mainExample.price) }}/{{ mainExample.cycle }} menghasilkan komisi
                    <strong class="text-slate-900">{{ rupiah(mainExample.commission) }}</strong> per toko setiap {{ mainExample.cycle }}.
                </p>

                <div class="mt-5 grid items-center gap-5 sm:grid-cols-2">
                    <div>
                        <p id="label-toko" class="text-sm font-semibold text-slate-700">Jumlah toko yang Anda ajak</p>
                        <div class="mt-2 flex items-center gap-3">
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                aria-label="Kurangi jumlah toko"
                                @click="step(-1)"
                            >
                                <Minus class="h-5 w-5" />
                            </button>
                            <output class="min-w-16 text-center text-3xl font-black text-slate-900 tabular-nums" aria-labelledby="label-toko">{{ stores }}</output>
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                aria-label="Tambah jumlah toko"
                                @click="step(1)"
                            >
                                <Plus class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-5 ring-1 ring-emerald-200 ring-inset">
                        <p class="text-sm font-semibold text-emerald-800">Perkiraan komisi Anda</p>
                        <p class="mt-1 text-3xl font-black text-emerald-700 tabular-nums">{{ rupiah(simulated) }}</p>
                        <p class="text-sm text-emerald-800">per {{ mainExample.cycle }}, selama toko-toko itu terus berlangganan</p>
                    </div>
                </div>

                <p v-if="examples[1]" class="mt-4 text-sm text-slate-600">
                    Paket {{ examples[1].plan }} {{ rupiah(examples[1].price) }}/{{ examples[1].cycle }} menghasilkan komisi
                    <strong class="text-slate-900">{{ rupiah(examples[1].commission) }}</strong> per toko.
                </p>
                <p class="mt-2 text-xs text-slate-500">Angka di atas adalah simulasi, bukan jaminan penghasilan.</p>
            </section>

            <!-- Ketentuan -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="ketentuan">
                <h2 id="ketentuan" class="text-lg font-bold text-slate-900">Ketentuan program</h2>
                <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-slate-700">
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span
                            >Komisi {{ percentLabel }}% dihitung dari setiap pembayaran langganan (paket maupun fitur tambahan) yang sudah dikonfirmasi, selama
                            {{ rules.months }} bulan sejak pembayaran pertama toko yang Anda ajak.</span
                        >
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span>Pendaftaran dan masa coba gratis belum menghasilkan komisi. Komisi muncul setelah toko membayar.</span>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span
                            >Link Anda diingat selama {{ rules.cookie_days }} hari sejak diklik. Bila calon pengguna mengklik link afiliator lain setelahnya, link
                            terakhir yang dihitung.</span
                        >
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span
                            >Komisi ditahan {{ rules.hold_days }} hari, lalu ditransfer ke rekening atau e-wallet Anda setelah terkumpul minimal
                            {{ rupiah(rules.min_payout) }}.</span
                        >
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span>Mendaftarkan toko sendiri lewat link sendiri tidak menghasilkan komisi. Komisi dibatalkan bila pembayarannya dikembalikan (refund).</span>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span>Dilarang mengirim spam atau memberi janji yang tidak sesuai dengan layanan Kasir UMKM. Pelanggaran dapat membuat akun afiliasi dibekukan.</span>
                    </li>
                </ul>

                <Link
                    v-if="!viewer"
                    href="/afiliasi/daftar"
                    class="mt-6 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-bold text-white transition-colors hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 sm:w-auto"
                >
                    Daftar jadi afiliator <ArrowRight class="h-5 w-5" />
                </Link>
            </section>
        </div>
    </AffiliateLayout>
</template>
