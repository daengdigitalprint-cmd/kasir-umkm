<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    Croissant,
    LoaderCircle,
    Pill,
    Scissors,
    ShoppingBasket,
    Store,
    Utensils,
    WashingMachine,
} from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * Onboarding toko pertama (langkah 2 dari 2 setelah daftar).
 * Hanya menanyakan nama toko + jenis usaha; sisanya diisi otomatis di server
 * (StoreController::store) dan bisa diubah nanti di Pengaturan Toko.
 */
interface OnboardingProps {
    businessTypes?: Record<string, string>;
    defaultBusinessType?: string;
    userName?: string;
}

const props = withDefaults(defineProps<OnboardingProps>(), {
    businessTypes: () => ({}),
    defaultBusinessType: 'general',
    userName: '',
});

const businessTypeIcons: Record<string, Component> = {
    general: Store,
    retail: ShoppingBasket,
    fnb: Utensils,
    bakery: Croissant,
    pharmacy: Pill,
    laundry: WashingMachine,
    barbershop: Scissors,
};

/** Tebak zona waktu Indonesia dari selisih jam perangkat (WIB/WITA/WIT). */
function detectTimezone(): string {
    const offsetHours = -new Date().getTimezoneOffset() / 60;
    if (offsetHours === 8) return 'Asia/Makassar';
    if (offsetHours === 9) return 'Asia/Jayapura';
    return 'Asia/Jakarta';
}

const form = useForm({
    name: '',
    business_type: props.defaultBusinessType,
    timezone: detectTimezone(),
});

const submit = () => {
    form.post('/stores');
};

const logout = () => {
    router.post('/logout');
};

const firstName = props.userName.trim().split(/\s+/)[0] ?? '';
</script>

<template>
    <Head title="Siapkan toko Anda" />

    <div class="min-h-screen bg-gradient-to-br from-[#f3fbec] via-white to-[#eef7e6] px-4 py-8 sm:py-12">
        <div class="mx-auto w-full max-w-xl">
            <div class="mb-6 text-center">
                <img src="/logo-macca.png" alt="Kasir UMKM by MaccaPOS" class="mx-auto h-14 w-auto" />
            </div>

            <div class="rounded-2xl border border-[#A0EA71]/25 bg-white p-5 shadow-lg shadow-slate-200/60 sm:p-8">
                <!-- Indikator langkah -->
                <div class="mb-6">
                    <div class="mb-2 flex items-center justify-between text-xs font-bold text-slate-500">
                        <span class="uppercase tracking-[0.14em] text-[#3d7a12]">Langkah 2 dari 2</span>
                        <span class="inline-flex items-center gap-1 text-slate-400">
                            <Check class="h-3.5 w-3.5" />
                            Akun dibuat
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-2" aria-hidden="true">
                        <div class="h-1.5 rounded-full bg-[#A0EA71]"></div>
                        <div class="h-1.5 rounded-full bg-[#A0EA71]"></div>
                    </div>
                </div>

                <h1 class="text-2xl font-extrabold tracking-tight text-[#173401]">
                    {{ firstName ? `Halo ${firstName}, siapkan toko Anda` : 'Siapkan toko Anda' }}
                </h1>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Cukup dua hal ini untuk mulai berjualan. Alamat, struk, dan pengaturan lain bisa dilengkapi nanti.
                </p>

                <form class="mt-6 space-y-6" @submit.prevent="submit">
                    <div class="space-y-2">
                        <label for="store-name" class="text-sm font-bold text-slate-700">Nama toko</label>
                        <input
                            id="store-name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            maxlength="255"
                            autocomplete="organization"
                            placeholder="Contoh: Warung Makmur"
                            class="h-13 w-full rounded-xl border-[1.5px] border-[#A0EA71]/40 bg-white px-4 py-3 text-base font-medium text-slate-900 placeholder:text-slate-400 focus:border-[#A0EA71] focus:outline-none focus:ring-4 focus:ring-[#A0EA71]/25"
                        />
                        <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-bold text-slate-700">Jenis usaha</legend>
                        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                            <label
                                v-for="(label, key) in businessTypes"
                                :key="key"
                                class="relative flex cursor-pointer flex-col items-center gap-2 rounded-xl border-[1.5px] px-3 py-4 text-center transition-colors focus-within:ring-4 focus-within:ring-[#A0EA71]/25"
                                :class="
                                    form.business_type === key
                                        ? 'border-[#5fb02a] bg-[#f3fbec] text-[#173401]'
                                        : 'border-slate-200 bg-white text-slate-600 hover:border-[#A0EA71]/60'
                                "
                            >
                                <input
                                    v-model="form.business_type"
                                    type="radio"
                                    name="business_type"
                                    :value="key"
                                    class="sr-only"
                                />
                                <span
                                    v-if="form.business_type === key"
                                    class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-[#5fb02a] text-white"
                                >
                                    <Check class="h-3 w-3" />
                                </span>
                                <component :is="businessTypeIcons[key] ?? Store" class="h-7 w-7" />
                                <span class="text-xs font-bold leading-snug">{{ label }}</span>
                            </label>
                        </div>
                        <p class="text-xs leading-relaxed text-slate-500">
                            Dipakai untuk menyiapkan kategori dan satuan produk yang cocok. Bisa diubah nanti di Pengaturan.
                        </p>
                        <p v-if="form.errors.business_type" class="text-sm text-red-600">{{ form.errors.business_type }}</p>
                    </fieldset>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-13 w-full items-center justify-center gap-2 rounded-xl border-2 border-[#A0EA71] bg-[#A0EA71] py-3 text-sm font-extrabold uppercase tracking-wide text-[#05111A] transition-colors hover:border-[#173401] hover:bg-[#173401] hover:text-[#A0EA71] disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <LoaderCircle v-if="form.processing" class="h-4.5 w-4.5 animate-spin" />
                        <span>{{ form.processing ? 'Menyiapkan toko...' : 'Mulai pakai kasir' }}</span>
                        <ArrowRight v-if="!form.processing" class="h-4.5 w-4.5" />
                    </button>
                </form>
            </div>

            <p class="mt-5 text-center text-xs text-slate-500">
                Bukan akun Anda?
                <button type="button" class="font-bold text-[#3d7a12] underline-offset-2 hover:underline" @click="logout">
                    Keluar
                </button>
            </p>
        </div>
    </div>
</template>
