<script setup lang="ts">
import AffiliateLayout from '@/layouts/AffiliateLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Banknote, Eye, Link2, Minus, Plus, Share2, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Rules {
    percent: number;
    months: number;
    /** Komisi sekali per toko (dari paket pertama), bukan berulang. */
    once: boolean;
    cookie_days: number;
    hold_days: number;
    min_payout: number;
    referred_trial_days: number;
    default_trial_days: number;
}

interface Example {
    plan: string;
    price: number;
    commission: number;
    months: number;
    cycle: string;
    label: string;
}

const props = defineProps<{
    rules: Rules;
    examples: Example[];
    viewer: { name: string } | null;
    /** Super admin membuka halaman ini dari panel admin untuk melihat tampilan pengunjung. */
    adminPreview?: boolean;
}>();

const rupiah = (n: number) => 'Rp' + Math.round(n).toLocaleString('id-ID');
const percentLabel = computed(() => props.rules.percent.toLocaleString('id-ID'));

/* Simulasi: jumlah toko yang diajak × komisi per paket. Paket terpanjang ditonjolkan karena komisinya paling besar. */
const stores = ref(5);
const featured = computed(() => (props.examples.length ? props.examples[props.examples.length - 1] : null));
const others = computed(() => props.examples.slice(0, -1).reverse());
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
            <p
                v-if="adminPreview"
                role="status"
                class="flex items-start gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <Eye class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    <strong>Pratinjau admin.</strong> Beginilah halaman ini terlihat oleh pengunjung. Angka di sini mengikuti Aturan program di panel admin.
                </span>
            </p>

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
                        <template v-if="rules.once">
                            Bagikan link Anda. Saat toko yang Anda ajak berlangganan, Anda mendapat {{ percentLabel }}% dari harga paket yang dibelinya. Komisi
                            diberikan sekali untuk setiap toko.
                        </template>
                        <template v-else>
                            Bagikan link Anda. Setiap kali toko yang Anda ajak membayar langganan, Anda mendapat {{ percentLabel }}% dari pembayarannya, selama
                            {{ rules.months }} bulan.
                        </template>
                    </p>

                    <!-- Belum masuk: daftar akun afiliator -->
                    <div v-if="!viewer" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Link
                            href="/afiliasi/daftar"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-bold text-white shadow-lg shadow-primary/25 transition-transform hover:scale-[1.02] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2"
                        >
                            Daftar jadi afiliator <ArrowRight class="h-5 w-5" />
                        </Link>
                        <Link href="/afiliasi/dashboard" class="text-center text-sm font-semibold text-slate-700 underline underline-offset-4 hover:text-slate-900">
                            Sudah punya akun? Masuk
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
            <section v-if="featured" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="simulasi">
                <h2 id="simulasi" class="text-lg font-bold text-slate-900">Berapa yang bisa Anda dapat?</h2>
                <p class="mt-1 text-sm text-slate-600">
                    <template v-if="rules.once">Komisi dihitung sekali dari harga paket yang dibeli toko. Makin panjang paketnya, makin besar komisinya.</template>
                    <template v-else-if="others.length">Komisi terbesar datang dari toko yang mengambil paket terpanjang.</template>
                    Atur jumlah toko yang Anda ajak untuk melihat perkiraannya.
                </p>

                <div class="mt-4">
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

                <div class="mt-5 grid gap-3 sm:grid-cols-5">
                    <!-- Paket yang ditonjolkan: tahunan (atau satu-satunya paket yang ada).
                         Latar sama dengan kartu "Otomatis" di beranda (fs-solution-chart_box fa-chart-two). -->
                    <div :class="['relative overflow-hidden rounded-[20px] bg-[#f2fff2] p-5 text-[#0d1738] sm:p-6', others.length ? 'sm:col-span-3' : 'sm:col-span-5']">
                        <div class="xb-item--shape pointer-events-none absolute inset-0" aria-hidden="true">
                            <div class="shape shape--1 absolute top-0 left-0 h-full max-h-[337px]">
                                <img width="195" height="337" src="/sastik-assets/img/shape/solution_shape05.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                            </div>
                            <div class="shape shape--2 absolute top-0 right-0 h-full max-h-[337px]">
                                <img width="195" height="337" src="/sastik-assets/img/shape/solution_shape06.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                            </div>
                            <div class="shape shape--3 absolute bottom-0 left-0 w-32 sm:w-48">
                                <img width="264" height="299" src="/sastik-assets/img/shape/solution_shape07.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                            </div>
                            <div class="shape shape--4 absolute right-0 bottom-0 w-32 sm:w-48">
                                <img width="264" height="300" src="/sastik-assets/img/shape/solution_shape08.png" alt="" loading="lazy" decoding="async" class="h-auto w-full" />
                            </div>
                            <div class="shape shape--5 absolute top-1/2 left-0 h-full max-h-[370px] -translate-y-1/2">
                                <img width="204" height="370" src="/sastik-assets/img/shape/circle-blur03.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                            </div>
                            <div class="shape shape--6 absolute top-1/2 right-0 h-full max-h-[370px] -translate-y-1/2">
                                <img width="204" height="370" src="/sastik-assets/img/shape/circle-blur03.png" alt="" loading="lazy" decoding="async" class="h-full w-auto" />
                            </div>
                        </div>

                        <div class="relative z-10">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-secondary px-3 py-1 text-xs font-black tracking-wide text-secondary-foreground uppercase">
                                    {{ others.length ? featured.label + ' · komisi terbesar' : featured.label }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm font-semibold text-[#5a5869]">{{ featured.plan }}</p>
                            <p class="text-2xl font-black text-[#0d1738] tabular-nums sm:text-3xl">
                                {{ rupiah(featured.price) }}<span class="text-base font-bold text-[#5a5869]"> / {{ featured.cycle }}</span>
                            </p>

                            <div class="mt-4 rounded-xl border border-[#d8ead8] bg-white/80 p-4">
                                <p class="text-sm font-semibold text-[#5a5869]">Perkiraan komisi Anda</p>
                                <p class="mt-0.5 text-4xl font-black text-emerald-700 tabular-nums">{{ rupiah(featured.commission * stores) }}</p>
                                <p class="mt-1 text-sm text-[#5a5869]">
                                    {{ stores }} toko × {{ rupiah(featured.commission) }}<template v-if="rules.once">, sekali untuk setiap toko yang mengambil paket ini</template
                                    ><template v-else-if="featured.months >= 12">, dari satu pembayaran tahunan per toko</template
                                    ><template v-else> setiap {{ featured.cycle }}, selama toko-toko itu terus berlangganan</template>.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Pembanding: paket yang lebih pendek -->
                    <div v-if="others.length" class="flex flex-col gap-3 sm:col-span-2">
                        <div v-for="plan in others" :key="plan.plan" class="flex-1 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:p-6">
                            <span class="rounded-full bg-white px-3 py-1 text-xs font-bold tracking-wide text-slate-600 uppercase ring-1 ring-slate-200 ring-inset">{{
                                plan.label
                            }}</span>
                            <p class="mt-3 text-sm font-semibold text-slate-600">{{ plan.plan }}</p>
                            <p class="text-xl font-black text-slate-900 tabular-nums">
                                {{ rupiah(plan.price) }}<span class="text-sm font-bold text-slate-500"> / {{ plan.cycle }}</span>
                            </p>
                            <p class="mt-4 text-sm font-semibold text-slate-600">Perkiraan komisi Anda</p>
                            <p class="mt-0.5 text-2xl font-black text-slate-900 tabular-nums">
                                {{ rupiah(plan.commission * stores) }}<span v-if="!rules.once" class="text-sm font-bold text-slate-500"> / {{ plan.cycle }}</span>
                            </p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ stores }} toko × {{ rupiah(plan.commission) }}<template v-if="rules.once">, sekali untuk setiap toko</template
                                ><template v-else> setiap {{ plan.cycle }}, selama toko-toko itu terus berlangganan</template>.
                            </p>
                        </div>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">Angka di atas adalah simulasi, bukan jaminan penghasilan.</p>
            </section>

            <!-- Ketentuan -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="ketentuan">
                <h2 id="ketentuan" class="text-lg font-bold text-slate-900">Ketentuan program</h2>
                <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-slate-700">
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span
                            ><template v-if="rules.once"
                                >Komisi {{ percentLabel }}% dihitung dari harga paket pertama yang dibeli toko yang Anda ajak, setelah pembayarannya
                                dikonfirmasi. Komisi diberikan sekali untuk setiap toko; perpanjangan berikutnya tidak menghasilkan komisi lagi.</template
                            ><template v-else
                                >Komisi {{ percentLabel }}% dihitung dari setiap pembayaran langganan (paket maupun fitur tambahan) yang sudah dikonfirmasi,
                                selama {{ rules.months }} bulan sejak pembayaran pertama toko yang Anda ajak.</template
                            ></span
                        >
                    </li>
                    <li class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span>Pendaftaran dan masa coba gratis belum menghasilkan komisi. Komisi muncul setelah toko membayar.</span>
                    </li>
                    <li v-if="rules.referred_trial_days > rules.default_trial_days" class="flex gap-2.5">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                        <span
                            >Toko yang mendaftar lewat link atau kode Anda bisa mencoba semua fitur gratis {{ rules.referred_trial_days }} hari (pendaftar biasa
                            {{ rules.default_trial_days }} hari).</span
                        >
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
                            >Komisi ditahan {{ rules.hold_days }} hari. Setelah terkumpul minimal {{ rupiah(rules.min_payout) }}, Anda bisa menekan tombol
                            Tarik komisi, lalu kami transfer ke rekening atau e-wallet Anda. Pemilik toko juga bisa memakai komisinya untuk membayar
                            langganan tokonya sendiri.</span
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
