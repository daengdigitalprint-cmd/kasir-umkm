<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { LogIn, LogOut, Store } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Kerangka area Program Afiliasi. Sengaja tanpa menu samping: afiliator (yang sering
 * membuka dari HP dan belum tentu punya toko) hanya butuh satu halaman yang ringkas.
 * Pesan flash ditampilkan oleh toast global (lib/swal), jadi tidak diulang di sini.
 */
interface Props {
    title: string;
}

const props = defineProps<Props>();

const page = usePage();
const user = computed(() => (page.props as any).auth?.user as { name?: string } | null);
const hasStore = computed(() => ((page.props as any).accessible_stores ?? []).length > 0);

const firstName = computed(() => (user.value?.name ?? '').trim().split(/\s+/)[0] ?? '');

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <Head :title="props.title + ' - Kasir UMKM by MaccaPOS'" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 text-slate-900">
        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between gap-3 px-4 sm:px-6">
                <Link href="/afiliasi" class="flex min-w-0 items-center gap-3 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                    <img src="/logo-kasir-umkm.png?v=2" alt="Kasir UMKM by MaccaPOS" class="h-10 w-auto shrink-0" />
                    <span class="hidden rounded-full bg-primary/10 px-3 py-1 text-xs font-bold tracking-wide text-primary sm:inline">Program Afiliasi</span>
                </Link>

                <div v-if="user" class="flex items-center gap-1.5">
                    <span class="mr-1 hidden max-w-[10rem] truncate text-sm font-semibold text-slate-700 sm:inline">Halo, {{ firstName }}</span>
                    <Link
                        v-if="hasStore"
                        href="/dashboard"
                        class="inline-flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    >
                        <Store class="h-4 w-4" />
                        <span>Toko saya</span>
                    </Link>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="inline-flex h-10 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                        @click="handleLogout"
                    >
                        <LogOut class="h-4 w-4" />
                        <span class="hidden sm:inline">Keluar</span>
                    </Link>
                </div>
                <Link
                    v-else
                    href="/login"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                >
                    <LogIn class="h-4 w-4" />
                    Masuk
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>
    </div>
</template>
