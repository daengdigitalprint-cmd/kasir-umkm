<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Blocks, Bluetooth, Building2, HardDrive, KeyRound, Lock, Package, Palette, Receipt, ShoppingCart, Store, User } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, type Component } from 'vue';

/**
 * Kerangka semua halaman Pengaturan: navigasi kiri (di HP jadi deretan tombol yang bisa digeser)
 * dengan dua kelompok — "Akun saya" untuk semua pengguna, "Toko" untuk pengelola toko.
 */
const props = defineProps<{
    /** Kunci menu yang sedang dibuka. */
    active: string;
    title: string;
    description?: string;
}>();

type NavItem = { key: string; label: string; href: string; icon: Component };

const page = usePage();
const p = page.props as {
    abilities?: { manage_store_settings?: boolean };
    accessible_stores?: Array<{ id: number; name: string }>;
    auth?: { user?: { store_id?: number | null } };
};

const canManageStore = computed(() => Boolean(p.abilities?.manage_store_settings));

const accountItems: NavItem[] = [
    { key: 'akun', label: 'Profil saya', href: '/settings/profile', icon: User },
    { key: 'password', label: 'Password', href: '/settings/password', icon: Lock },
    { key: 'tampilan', label: 'Tampilan', href: '/settings/appearance', icon: Palette },
    { key: 'printer', label: 'Printer Bluetooth', href: '/settings/bluetooth-printer', icon: Bluetooth },
];

const storeItems: NavItem[] = [
    { key: 'profil', label: 'Profil toko', href: '/settings/store/profil', icon: Building2 },
    { key: 'kasir', label: 'Kasir & pembayaran', href: '/settings/store/kasir', icon: ShoppingCart },
    { key: 'fitur', label: 'Fitur', href: '/settings/store/fitur', icon: Blocks },
    { key: 'struk', label: 'Struk', href: '/settings/store/struk', icon: Receipt },
    { key: 'stok', label: 'Stok & gudang', href: '/settings/store/stok', icon: Package },
    { key: 'pin', label: 'PIN kasir', href: '/settings/cashier-pin', icon: KeyRound },
    { key: 'backup', label: 'Backup data', href: '/settings/store/backup', icon: HardDrive },
];

const groups = computed(() => [{ label: 'Akun saya', items: accountItems }, ...(canManageStore.value ? [{ label: 'Toko', items: storeItems }] : [])]);

const isStoreSection = computed(() => storeItems.some((i) => i.key === props.active));
const stores = computed(() => p.accessible_stores ?? []);
// Di HP navigasi bisa digeser; pastikan menu yang aktif terlihat.
const navRef = ref<HTMLElement | null>(null);
onMounted(() => {
    void nextTick(() => {
        const el = navRef.value?.querySelector<HTMLElement>('[aria-current="page"]');
        const box = navRef.value;
        if (el && box && box.scrollWidth > box.clientWidth) {
            box.scrollLeft = el.offsetLeft - box.clientWidth / 2 + el.clientWidth / 2;
        }
    });
});

const activeStoreName = computed(() => stores.value.find((s) => s.id === p.auth?.user?.store_id)?.name ?? '');
</script>

<template>
    <AdminLayout title="Pengaturan">
        <Head :title="`${title} · Pengaturan`" />

        <div class="mx-auto w-full max-w-6xl">
            <div class="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                <!-- Navigasi -->
                <nav aria-label="Menu pengaturan" class="min-w-0">
                    <div
                        ref="navRef"
                        class="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] lg:mx-0 lg:block lg:space-y-5 lg:px-0 [&::-webkit-scrollbar]:hidden"
                    >
                        <div v-for="g in groups" :key="g.label" class="flex shrink-0 gap-1.5 lg:block lg:space-y-0.5">
                            <p class="hidden px-3 pb-1 text-[11px] font-black tracking-widest text-slate-400 uppercase lg:block">{{ g.label }}</p>
                            <Link
                                v-for="item in g.items"
                                :key="item.key"
                                :href="item.href"
                                :aria-current="item.key === active ? 'page' : undefined"
                                class="flex shrink-0 items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold whitespace-nowrap transition lg:border-transparent"
                                :class="
                                    item.key === active
                                        ? 'border-primary/20 bg-primary/10 text-primary'
                                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:bg-transparent'
                                "
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" />
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>
                </nav>

                <!-- Isi -->
                <div class="min-w-0">
                    <header class="mb-5">
                        <h1 class="text-2xl font-bold text-slate-900">{{ title }}</h1>
                        <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
                    </header>

                    <div
                        v-if="isStoreSection && stores.length > 1 && activeStoreName"
                        class="mb-5 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600"
                    >
                        <Store class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                        <p>
                            Pengaturan ini berlaku untuk <strong class="text-slate-900">{{ activeStoreName }}</strong> saja. Untuk outlet lain, ganti
                            outlet lewat pilihan di bagian atas sidebar.
                        </p>
                    </div>

                    <div class="space-y-5">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
