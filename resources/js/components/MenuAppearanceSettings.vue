<script setup lang="ts">
/**
 * Kartu + jendela "Atur tampilan" halaman pelanggan (menu digital Scan Meja & tautan Pesan Antar):
 * foto latar header, logo, dan banner geser. Dipakai di Manajemen Meja dan Pengantaran.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { ACCENT_PRESETS, DEFAULT_ACCENT, accentStyle, buttonColor, isAdjusted } from '@/lib/accentPalette';
import { useForm } from '@inertiajs/vue3';
import { Check, ExternalLink, ImagePlus, Images, Palette, Plus, ShoppingCart, Trash2, Utensils } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

export interface MenuAppearance {
    store_name: string;
    cover_url: string | null;
    logo_url: string | null;
    store_logo_url: string | null;
    banners?: { id: string; url: string }[];
    banner_limits?: { max: number; width: number; height: number; max_mb: number };
    /** Warna utama pilihan toko (#rrggbb). Kosong = ungu bawaan. */
    accent_color?: string | null;
}

const props = defineProps<{
    appearance?: MenuAppearance;
    /** Alamat penyimpanan: /tables/appearance atau /deliveries/appearance. */
    action: string;
    /** Halaman pelanggan untuk tombol "Lihat". */
    previewUrl?: string | null;
    /** Teks kecil di pratinjau: nama meja, atau "Pesan antar". */
    previewLabel?: string;
    variant?: 'table' | 'delivery';
}>();

const isDelivery = computed(() => props.variant === 'delivery');
const judul = computed(() => (isDelivery.value ? 'Tampilan halaman pesan antar' : 'Tampilan menu digital'));
const ringkas = computed(() =>
    isDelivery.value
        ? 'Ganti foto latar, logo, dan banner promo yang dilihat pelanggan di tautan pesan antar.'
        : 'Ganti foto latar header, logo, dan banner promo yang dilihat pelanggan saat scan QR.',
);
const label = computed(() => props.previewLabel ?? (isDelivery.value ? 'Pesan antar' : 'Meja 1'));

const batas = computed(() => props.appearance?.banner_limits ?? { max: 5, width: 1200, height: 480, max_mb: 2 });

const show = ref(false);
const form = useForm({
    cover: null as File | null,
    logo: null as File | null,
    remove_cover: false,
    remove_logo: false,
    banners: [] as File[],
    remove_banners: [] as string[],
    /** '' = warna bawaan. Selalu ikut terkirim, jadi nilai awalnya warna yang tersimpan. */
    accent_color: props.appearance?.accent_color ?? '',
});
const coverPreview = ref<string | null>(null);
const logoPreview = ref<string | null>(null);
/** Pratinjau banner baru, sejajar dengan form.banners. */
const bannerPreviews = ref<string[]>([]);
const bannerNotice = ref('');
const revoke = (u: string | null) => u && URL.revokeObjectURL(u);
const clearPreviews = () => {
    revoke(coverPreview.value);
    revoke(logoPreview.value);
    bannerPreviews.value.forEach(revoke);
    coverPreview.value = null;
    logoPreview.value = null;
    bannerPreviews.value = [];
    bannerNotice.value = '';
};
onBeforeUnmount(clearPreviews);

/**
 * Kosongkan form. Inertia menjadikan isi form terakhir sebagai nilai bawaan setelah simpan berhasil,
 * jadi reset() saja akan mengembalikan berkas yang baru diunggah: nilai bawaannya ditetapkan ulang dulu.
 */
const kosongkan = () => {
    form.defaults({
        cover: null,
        logo: null,
        remove_cover: false,
        remove_logo: false,
        banners: [],
        remove_banners: [],
        accent_color: props.appearance?.accent_color ?? '',
    });
    form.reset();
    form.clearErrors();
    clearPreviews();
};

const open = () => {
    kosongkan();
    show.value = true;
};

const pickImage = (field: 'cover' | 'logo', e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    (e.target as HTMLInputElement).value = '';
    if (!file) return;
    form[field] = file;
    form[field === 'cover' ? 'remove_cover' : 'remove_logo'] = false;
    const target = field === 'cover' ? coverPreview : logoPreview;
    revoke(target.value);
    target.value = URL.createObjectURL(file);
};
const removeImage = (field: 'cover' | 'logo') => {
    form[field] = null;
    form[field === 'cover' ? 'remove_cover' : 'remove_logo'] = true;
    const target = field === 'cover' ? coverPreview : logoPreview;
    revoke(target.value);
    target.value = null;
};
// Gambar yang tampil di pratinjau: pilihan baru → tersimpan → cadangan.
const shownCover = computed(() => coverPreview.value ?? (form.remove_cover ? null : (props.appearance?.cover_url ?? null)));
const shownLogo = computed(
    () => logoPreview.value ?? (form.remove_logo ? null : (props.appearance?.logo_url ?? null)) ?? props.appearance?.store_logo_url ?? null,
);
const hasOwnCover = computed(() => !!coverPreview.value || (!form.remove_cover && !!props.appearance?.cover_url));
const hasOwnLogo = computed(() => !!logoPreview.value || (!form.remove_logo && !!props.appearance?.logo_url));
const storeInitials = computed(() =>
    (props.appearance?.store_name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join(''),
);

// --- Warna toko ---
const warna = computed(() => (form.accent_color || DEFAULT_ACCENT).toLowerCase());
const pilihWarna = (hex: string) => {
    form.accent_color = hex.toLowerCase() === DEFAULT_ACCENT ? '' : hex.toLowerCase();
};
const warnaDigelapkan = computed(() => isAdjusted(warna.value));
const warnaTombol = computed(() => buttonColor(warna.value));
const warnaKustom = computed(() => !ACCENT_PRESETS.some((p) => p.hex === warna.value));
/** Pratinjau memakai variabel warna yang sama dengan halaman pelanggan. */
const previewStyle = computed(() => accentStyle(form.accent_color || null));

// --- Banner geser ---
const savedBanners = computed(() => (props.appearance?.banners ?? []).filter((b) => !form.remove_banners.includes(b.id)));
const bannerCount = computed(() => savedBanners.value.length + form.banners.length);
const bannerSlots = computed(() => Math.max(0, batas.value.max - bannerCount.value));
const shownBanners = computed(() => [...savedBanners.value.map((b) => b.url), ...bannerPreviews.value]);

const pickBanners = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    bannerNotice.value = '';
    const maxBytes = batas.value.max_mb * 1024 * 1024;
    const skipped: string[] = [];
    for (const file of files) {
        if (form.banners.length + savedBanners.value.length >= batas.value.max) {
            skipped.push(`${file.name} (sudah ${batas.value.max} banner)`);
            continue;
        }
        if (file.size > maxBytes) {
            skipped.push(`${file.name} (lebih dari ${batas.value.max_mb} MB)`);
            continue;
        }
        form.banners.push(file);
        bannerPreviews.value.push(URL.createObjectURL(file));
    }
    if (skipped.length) {
        bannerNotice.value = `Tidak ditambahkan: ${skipped.join(', ')}.`;
    }
};
const removeSavedBanner = (id: string) => {
    if (!form.remove_banners.includes(id)) form.remove_banners.push(id);
};
const removeNewBanner = (index: number) => {
    revoke(bannerPreviews.value[index] ?? null);
    bannerPreviews.value.splice(index, 1);
    form.banners.splice(index, 1);
};
const bannerError = computed(() => {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find((k) => k === 'banners' || k.startsWith('banners.'));
    return key ? errors[key] : '';
});

const save = () => {
    form.post(props.action, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            show.value = false;
            kosongkan(); // pesan berhasil datang dari server (toast global)
        },
    });
};
</script>

<template>
    <!-- Kartu ringkas -->
    <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="relative h-16 w-28 shrink-0 overflow-hidden rounded-xl bg-violet-100">
            <img v-if="appearance?.cover_url" :src="appearance.cover_url" alt="" class="h-full w-full object-cover" />
            <div
                class="absolute bottom-1.5 left-1.5 flex h-7 w-7 items-center justify-center overflow-hidden rounded-lg border-2 border-white bg-white text-[10px] font-black text-violet-700"
            >
                <img
                    v-if="appearance?.logo_url || appearance?.store_logo_url"
                    :src="(appearance.logo_url || appearance.store_logo_url)!"
                    alt=""
                    class="h-full w-full object-cover"
                />
                <span v-else>{{ storeInitials }}</span>
            </div>
        </div>
        <div class="min-w-0 flex-1">
            <p class="font-bold text-slate-900">{{ judul }}</p>
            <p class="text-sm text-slate-500">{{ ringkas }}</p>
            <p class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold text-slate-500">
                <Images class="h-3.5 w-3.5" />
                {{ appearance?.banners?.length ? `${appearance.banners.length} banner promo terpasang` : 'Belum ada banner promo' }}
            </p>
        </div>
        <div class="flex gap-2">
            <a v-if="previewUrl" :href="previewUrl" target="_blank" rel="noopener">
                <Button variant="outline" class="gap-1.5 rounded-xl"><ExternalLink class="h-4 w-4" /> Lihat</Button>
            </a>
            <Button variant="outline" class="gap-1.5 rounded-xl" @click="open"><Palette class="h-4 w-4" /> Atur tampilan</Button>
        </div>
    </div>

    <!-- Jendela pengaturan -->
    <Dialog v-model:open="show">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-[760px]">
            <DialogHeader>
                <DialogTitle>{{ judul }}</DialogTitle>
                <DialogDescription>
                    Atur foto latar header, logo, dan banner promo yang dilihat pelanggan. Berlaku untuk menu digital meja dan tautan pesan antar.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-6 md:grid-cols-[1fr_260px]">
                <!-- Pengaturan -->
                <div class="space-y-5">
                    <div>
                        <Label>Foto latar header</Label>
                        <p class="mt-0.5 text-xs text-slate-500">Disarankan foto lebar (mis. 1200×500), JPG/PNG/WebP maks 3 MB.</p>
                        <div class="mt-2 overflow-hidden rounded-xl border border-dashed border-slate-300">
                            <div class="relative aspect-[12/5] bg-slate-50">
                                <img v-if="shownCover" :src="shownCover" alt="" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 text-center text-xs text-slate-500">
                                    <ImagePlus class="h-6 w-6 text-slate-400" />
                                    Belum ada foto. Menu memakai foto produk pertama.
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                <ImagePlus class="h-4 w-4" /> {{ hasOwnCover ? 'Ganti foto' : 'Pilih foto' }}
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage('cover', $event)" />
                            </label>
                            <Button v-if="hasOwnCover" type="button" variant="ghost" size="sm" class="text-red-600 hover:bg-red-50" @click="removeImage('cover')">
                                <Trash2 class="h-4 w-4" /> Hapus
                            </Button>
                        </div>
                        <p v-if="form.errors.cover" class="mt-1 text-sm text-red-600">{{ form.errors.cover }}</p>
                    </div>

                    <div>
                        <Label>Logo di menu</Label>
                        <p class="mt-0.5 text-xs text-slate-500">Persegi (mis. 400×400), maks 1 MB. Kosongkan untuk memakai logo toko.</p>
                        <div class="mt-2 flex items-center gap-3">
                            <div
                                class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-violet-50 text-xl font-black text-violet-700"
                            >
                                <img v-if="shownLogo" :src="shownLogo" alt="" class="h-full w-full object-cover" />
                                <span v-else>{{ storeInitials }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    <ImagePlus class="h-4 w-4" /> {{ hasOwnLogo ? 'Ganti logo' : 'Pilih logo' }}
                                    <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage('logo', $event)" />
                                </label>
                                <Button v-if="hasOwnLogo" type="button" variant="ghost" size="sm" class="text-red-600 hover:bg-red-50" @click="removeImage('logo')">
                                    <Trash2 class="h-4 w-4" /> Hapus
                                </Button>
                            </div>
                        </div>
                        <p v-if="!hasOwnLogo" class="mt-1 text-xs text-slate-500">
                            {{ appearance?.store_logo_url ? 'Saat ini memakai logo toko.' : 'Belum ada logo — menu menampilkan inisial toko.' }}
                        </p>
                        <p v-if="form.errors.logo" class="mt-1 text-sm text-red-600">{{ form.errors.logo }}</p>
                    </div>

                    <!-- Warna toko -->
                    <div>
                        <Label>Warna toko</Label>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Dipakai untuk tombol, harga, dan penanda di halaman pelanggan. Pilih yang sesuai warna merek Anda.
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2" role="group" aria-label="Pilihan warna toko">
                            <button
                                v-for="p in ACCENT_PRESETS"
                                :key="p.hex"
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white shadow ring-1 ring-slate-200 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                :class="warna === p.hex ? 'scale-110 ring-2 ring-slate-900' : 'hover:scale-105'"
                                :style="{ backgroundColor: p.hex }"
                                :aria-label="`Warna ${p.name}`"
                                :aria-pressed="warna === p.hex"
                                :title="p.name"
                                @click="pilihWarna(p.hex)"
                            >
                                <Check v-if="warna === p.hex" class="h-4 w-4 text-white" />
                            </button>
                            <label
                                class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-full border border-slate-200 py-1 pr-3 pl-1 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                :class="warnaKustom ? 'ring-2 ring-slate-900' : ''"
                            >
                                <input
                                    type="color"
                                    class="h-7 w-7 cursor-pointer rounded-full border-0 bg-transparent p-0"
                                    :value="warna"
                                    aria-label="Pilih warna lain"
                                    @input="pilihWarna(($event.target as HTMLInputElement).value)"
                                />
                                Warna lain
                            </label>
                        </div>
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-3 w-3 rounded-full ring-1 ring-slate-200" :style="{ backgroundColor: warnaTombol }" />
                                Dipakai: <span class="font-mono font-semibold text-slate-700">{{ warnaTombol }}</span>
                            </span>
                            <button v-if="form.accent_color" type="button" class="font-semibold text-slate-700 underline underline-offset-2" @click="pilihWarna(DEFAULT_ACCENT)">
                                Kembali ke ungu bawaan
                            </button>
                        </p>
                        <p v-if="warnaDigelapkan" class="mt-1 text-xs text-amber-700" role="status">
                            Warna pilihan Anda terlalu terang untuk tulisan putih, jadi dipakai versi yang sedikit lebih tua agar tombol tetap terbaca.
                        </p>
                        <p v-if="form.errors.accent_color" class="mt-1 text-sm text-red-600" role="alert">{{ form.errors.accent_color }}</p>
                    </div>

                    <!-- Banner geser -->
                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <Label>Banner promo (geser)</Label>
                            <span class="text-xs font-semibold text-slate-500">{{ bannerCount }} / {{ batas.max }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Tampil bergantian di bawah nama toko, bisa digeser pelanggan. Cocok untuk promo, menu baru, atau info jam buka.
                        </p>
                        <div class="mt-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs leading-relaxed text-violet-900">
                            <p class="font-bold">Ukuran banner: {{ batas.width }} × {{ batas.height }} piksel (rasio 5:2, melebar)</p>
                            <p>
                                JPG, PNG, atau WebP · maks {{ batas.max_mb }} MB per gambar · paling banyak {{ batas.max }} banner. Gambar dengan rasio lain
                                dipotong otomatis dari tengah, jadi taruh tulisan penting di bagian tengah.
                            </p>
                        </div>

                        <ul v-if="bannerCount" class="mt-2 grid grid-cols-2 gap-2">
                            <li v-for="(b, i) in savedBanners" :key="b.id" class="relative overflow-hidden rounded-xl border border-slate-200">
                                <img :src="b.url" :alt="`Banner ${i + 1}`" class="aspect-[5/2] w-full object-cover" />
                                <span class="absolute top-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ i + 1 }}</span>
                                <button
                                    type="button"
                                    class="absolute top-1 right-1 flex h-7 w-7 items-center justify-center rounded-lg bg-white/95 text-red-600 shadow hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
                                    :aria-label="`Hapus banner ${i + 1}`"
                                    @click="removeSavedBanner(b.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </li>
                            <li v-for="(src, i) in bannerPreviews" :key="src" class="relative overflow-hidden rounded-xl border border-violet-300">
                                <img :src="src" :alt="`Banner baru ${i + 1}`" class="aspect-[5/2] w-full object-cover" />
                                <span class="absolute top-1 left-1 rounded bg-violet-600 px-1.5 py-0.5 text-[10px] font-bold text-white">Baru</span>
                                <button
                                    type="button"
                                    class="absolute top-1 right-1 flex h-7 w-7 items-center justify-center rounded-lg bg-white/95 text-red-600 shadow hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
                                    :aria-label="`Batalkan banner baru ${i + 1}`"
                                    @click="removeNewBanner(i)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </li>
                        </ul>
                        <p v-else class="mt-2 rounded-xl border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500">
                            Belum ada banner. Halaman pelanggan tampil tanpa slider.
                        </p>

                        <div class="mt-2">
                            <label
                                v-if="bannerSlots > 0"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                <ImagePlus class="h-4 w-4" /> Tambah banner
                                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="pickBanners" />
                            </label>
                            <p v-else class="text-xs font-semibold text-slate-500">Sudah {{ batas.max }} banner. Hapus salah satu untuk menambah yang baru.</p>
                        </div>
                        <p v-if="bannerNotice" class="mt-1 text-sm text-amber-700" role="status">{{ bannerNotice }}</p>
                        <p v-if="bannerError" class="mt-1 text-sm text-red-600" role="alert">{{ bannerError }}</p>
                    </div>
                </div>

                <!-- Pratinjau ala layar HP -->
                <div>
                    <p class="mb-2 text-xs font-semibold text-slate-500">Pratinjau di HP pelanggan</p>
                    <div class="overflow-hidden rounded-[1.75rem] border-4 border-slate-800 bg-white" :style="previewStyle">
                        <div class="relative h-24 bg-violet-100">
                            <img v-if="shownCover" :src="shownCover" alt="" class="h-full w-full object-cover" />
                            <div v-else class="flex h-full items-center justify-center"><Utensils class="h-8 w-8 text-violet-200" /></div>
                            <span class="absolute top-2 right-2 rounded-full bg-white/95 px-2 py-0.5 text-[9px] font-bold text-slate-800">{{ label }}</span>
                        </div>
                        <div class="relative -mt-7 px-2.5">
                            <div class="flex gap-2 rounded-xl border border-slate-200 bg-white p-2">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-violet-50 text-xs font-black text-violet-700"
                                >
                                    <img v-if="shownLogo" :src="shownLogo" alt="" class="h-full w-full object-cover" />
                                    <span v-else>{{ storeInitials }}</span>
                                </div>
                                <div class="min-w-0">
                                    <span class="rounded bg-violet-50 px-1 text-[8px] font-bold text-violet-700">✓ {{ isDelivery ? 'Pesan antar' : 'Pesan dari meja' }}</span>
                                    <p class="truncate text-xs font-black text-slate-900">{{ appearance?.store_name }}</p>
                                    <p class="text-[9px] text-slate-500">{{ label }}</p>
                                </div>
                            </div>
                        </div>
                        <div v-if="shownBanners.length" class="px-2.5 pt-2">
                            <img :src="shownBanners[0]" alt="" class="aspect-[5/2] w-full rounded-lg object-cover" />
                            <div class="mt-1 flex justify-center gap-1">
                                <span
                                    v-for="(_, i) in shownBanners"
                                    :key="i"
                                    class="h-1 rounded-full"
                                    :class="i === 0 ? 'w-3 bg-violet-600' : 'w-1 bg-slate-300'"
                                />
                            </div>
                        </div>
                        <div class="space-y-2 p-2.5">
                            <div v-for="i in 3" :key="i" class="flex gap-2">
                                <div class="h-10 w-10 rounded-lg bg-slate-100" />
                                <div class="flex-1 space-y-1 pt-1">
                                    <div class="h-2 w-3/4 rounded bg-slate-200" />
                                    <div class="h-2 w-1/3 rounded bg-violet-300" />
                                </div>
                                <span class="mt-1.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-violet-300 text-violet-700">
                                    <Plus class="h-3 w-3" />
                                </span>
                            </div>
                            <div class="flex items-center justify-between rounded-full bg-violet-600 px-3 py-1.5 text-[9px] font-bold text-white">
                                <span class="inline-flex items-center gap-1"><ShoppingCart class="h-3 w-3" /> Keranjang · 2 item</span>
                                <span>Rp 30.000</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="show = false">Batal</Button>
                <Button type="button" :disabled="form.processing || !form.isDirty" @click="save">
                    {{ form.processing ? 'Menyimpan…' : 'Simpan tampilan' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
