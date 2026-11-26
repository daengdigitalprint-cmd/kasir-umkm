<script setup lang="ts">
import AffiliateLayout from '@/layouts/AffiliateLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    rules: { percent: number; months: number; once: boolean };
}>();

const form = useForm({
    name: '',
    email: '',
    whatsapp: '',
    password: '',
    password_confirmation: '',
    agree: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post('/afiliasi/daftar', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const inputClass =
    'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-base text-slate-900 placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <AffiliateLayout title="Daftar Afiliator">
        <div class="mx-auto max-w-md">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-black tracking-widest text-primary uppercase">Program Afiliasi</p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Daftar jadi afiliator</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Gratis dan tidak perlu punya toko. Komisi {{ rules.percent.toLocaleString('id-ID') }}%
                    <template v-if="rules.once">dari harga paket yang dibeli toko yang Anda ajak, sekali per toko.</template>
                    <template v-else>dari setiap pembayaran selama {{ rules.months }} bulan.</template>
                </p>

                <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
                    <div>
                        <label for="aff-name" class="mb-1.5 block text-sm font-semibold text-slate-700">Nama lengkap</label>
                        <input
                            id="aff-name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Nama Anda"
                            :class="inputClass"
                            :aria-invalid="!!form.errors.name"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label for="aff-email" class="mb-1.5 block text-sm font-semibold text-slate-700">Email</label>
                        <input
                            id="aff-email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            placeholder="nama@email.com"
                            :class="inputClass"
                            :aria-invalid="!!form.errors.email"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="aff-wa" class="mb-1.5 block text-sm font-semibold text-slate-700">Nomor WhatsApp</label>
                        <input
                            id="aff-wa"
                            v-model="form.whatsapp"
                            type="tel"
                            required
                            inputmode="tel"
                            autocomplete="tel"
                            maxlength="20"
                            placeholder="0812 3456 7890"
                            :class="inputClass"
                            :aria-invalid="!!form.errors.whatsapp"
                        />
                        <p v-if="form.errors.whatsapp" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ form.errors.whatsapp }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">Dipakai untuk kabar pencairan komisi.</p>
                    </div>

                    <div>
                        <label for="aff-password" class="mb-1.5 block text-sm font-semibold text-slate-700">Kata sandi</label>
                        <div class="relative">
                            <input
                                id="aff-password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                autocomplete="new-password"
                                placeholder="Minimal 8 karakter"
                                :class="[inputClass, 'pr-12']"
                                :aria-invalid="!!form.errors.password"
                            />
                            <button
                                type="button"
                                class="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="h-5 w-5" />
                                <Eye v-else class="h-5 w-5" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label for="aff-password2" class="mb-1.5 block text-sm font-semibold text-slate-700">Ulangi kata sandi</label>
                        <input
                            id="aff-password2"
                            v-model="form.password_confirmation"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Ketik lagi kata sandi"
                            :class="inputClass"
                        />
                    </div>

                    <div>
                        <label class="flex cursor-pointer items-start gap-3 text-sm text-slate-700">
                            <input v-model="form.agree" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-primary focus:ring-primary/40" />
                            <span>
                                Saya setuju dengan
                                <a href="/afiliasi#ketentuan" target="_blank" rel="noopener" class="font-semibold text-primary underline underline-offset-2">ketentuan program afiliasi</a>.
                            </span>
                        </label>
                        <p v-if="form.errors.agree" class="mt-1 text-sm font-medium text-red-600" role="alert">{{ form.errors.agree }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-bold text-white transition-colors hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:opacity-60"
                    >
                        <LoaderCircle v-if="form.processing" class="h-5 w-5 animate-spin" />
                        <template v-else>Daftar dan dapatkan link <ArrowRight class="h-5 w-5" /></template>
                    </button>
                </form>
            </div>

            <p class="mt-5 text-center text-sm text-slate-600">
                Sudah punya akun Kasir UMKM atau akun afiliasi?
                <Link href="/afiliasi/dashboard" class="font-semibold text-primary underline underline-offset-2">Masuk di sini</Link>.
            </p>
        </div>
    </AffiliateLayout>
</template>
