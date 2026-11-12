<script setup lang="ts">
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { logout } from '@/routes';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bot, Building2, CreditCard, Crown, History, Landmark, LogOut, Menu, Newspaper, Settings2, Shield, Ticket } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

interface Props {
    title: string;
    activeTab?: string;
    pendingPaymentsCount?: number;
}

const props = defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user as { name?: string; email?: string });
const initials = computed(() => {
    const name = (user.value?.name ?? 'Super Admin').trim();
    const parts = name.split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? 'S') + (parts[1]?.[0] ?? parts[0]?.[1] ?? 'A')).toUpperCase();
});

const mobileNavOpen = ref(false);

const handleLogout = () => {
    router.flushAll();
};

interface NavItem {
    id: string;
    label: string;
    icon: Component;
    url: string;
    badge?: number | null;
}

/** Menu dikelompokkan per tugas; URL & id sama seperti sebelumnya (fungsi tidak berubah). */
const navGroups = computed<{ label: string; items: NavItem[] }[]>(() => [
    {
        label: 'Operasional',
        items: [
            { id: 'stores', label: 'Toko', icon: Building2, url: '/superadmin' },
            {
                id: 'payments',
                label: 'Pembayaran',
                icon: CreditCard,
                url: '/superadmin?tab=payments',
                badge: props.pendingPaymentsCount && props.pendingPaymentsCount > 0 ? props.pendingPaymentsCount : null,
            },
        ],
    },
    {
        label: 'Langganan',
        items: [
            { id: 'plans', label: 'Paket', icon: Crown, url: '/superadmin?tab=plans' },
            { id: 'vouchers', label: 'Voucher', icon: Ticket, url: '/superadmin?tab=vouchers' },
            { id: 'transfer', label: 'Rekening & QRIS', icon: Landmark, url: '/superadmin?tab=transfer' },
        ],
    },
    {
        label: 'Konten & SEO',
        items: [{ id: 'articles', label: 'Artikel SEO', icon: Newspaper, url: '/superadmin/articles' }],
    },
    {
        label: 'Pemantauan & AI',
        items: [
            { id: 'trial-logs', label: 'Log Trial', icon: History, url: '/superadmin?tab=trial-logs' },
            { id: 'ai-auditor', label: 'AI Auditor', icon: Bot, url: '/superadmin/ai-auditor' },
            { id: 'ai-settings', label: 'Pengaturan AI', icon: Settings2, url: '/superadmin/ai-settings' },
        ],
    },
]);

const activeLabel = computed(() => {
    for (const g of navGroups.value) {
        const hit = g.items.find((i) => i.id === props.activeTab);
        if (hit) return hit.label;
    }
    return props.title;
});
</script>

<template>
    <Head :title="props.title" />

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <!-- ============ Sidebar (desktop) ============ -->
        <aside class="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
            <div class="flex h-16 items-center gap-3 border-b border-slate-100 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-red-600 shadow-sm">
                    <Shield class="h-5 w-5 text-white" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm leading-tight font-bold text-slate-900">Super Admin</p>
                    <p class="truncate text-xs text-slate-500">Panel pengelola Kasir UMKM by MaccaPOS</p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Menu super admin">
                <div v-for="group in navGroups" :key="group.label" class="mb-5 last:mb-0">
                    <p class="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ group.label }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="item in group.items" :key="item.id">
                            <Link
                                :href="item.url"
                                :aria-current="props.activeTab === item.id ? 'page' : undefined"
                                :class="[
                                    'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                                    props.activeTab === item.id
                                        ? 'bg-primary/10 text-primary'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                ]"
                            >
                                <component
                                    :is="item.icon"
                                    :class="[
                                        'h-4 w-4 shrink-0',
                                        props.activeTab === item.id ? 'text-primary' : 'text-slate-400 group-hover:text-slate-600',
                                    ]"
                                />
                                <span class="flex-1 truncate">{{ item.label }}</span>
                                <span
                                    v-if="item.badge"
                                    class="min-w-5 rounded-full bg-amber-500 px-1.5 py-0.5 text-center text-[11px] leading-none font-bold text-white"
                                    :aria-label="`${item.badge} menunggu konfirmasi`"
                                    >{{ item.badge }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="border-t border-slate-100 p-3">
                <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">
                        {{ initials }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ user?.name ?? 'Super Admin' }}</p>
                        <p class="truncate text-xs text-slate-500">{{ user?.email ?? 'Super Admin' }}</p>
                    </div>
                    <Link
                        :href="logout()"
                        as="button"
                        class="rounded-lg p-2 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                        title="Keluar"
                        aria-label="Keluar"
                        @click="handleLogout"
                    >
                        <LogOut class="h-4 w-4" />
                    </Link>
                </div>
            </div>
        </aside>

        <!-- ============ Top bar (mobile/tablet) ============ -->
        <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
            <button
                type="button"
                class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                aria-label="Buka menu"
                @click="mobileNavOpen = true"
            >
                <Menu class="h-5 w-5" />
            </button>
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-rose-500 to-red-600">
                    <Shield class="h-4 w-4 text-white" />
                </div>
                <p class="truncate text-sm font-semibold text-slate-900">{{ activeLabel }}</p>
            </div>
            <span
                v-if="props.pendingPaymentsCount && props.pendingPaymentsCount > 0"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"
            >
                {{ props.pendingPaymentsCount }} pending
            </span>
        </header>

        <Sheet v-model:open="mobileNavOpen">
            <SheetContent side="left" class="w-72 p-0">
                <SheetTitle class="sr-only">Menu super admin</SheetTitle>
                <SheetDescription class="sr-only">Navigasi panel super admin</SheetDescription>
                <div class="flex h-14 items-center gap-3 border-b border-slate-100 px-5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-rose-500 to-red-600">
                        <Shield class="h-4 w-4 text-white" />
                    </div>
                    <p class="text-sm font-bold text-slate-900">Super Admin</p>
                </div>
                <nav class="px-3 py-4" aria-label="Menu super admin">
                    <div v-for="group in navGroups" :key="group.label" class="mb-5 last:mb-0">
                        <p class="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ group.label }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.id">
                                <Link
                                    :href="item.url"
                                    :aria-current="props.activeTab === item.id ? 'page' : undefined"
                                    :class="[
                                        'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                                        props.activeTab === item.id ? 'bg-primary/10 text-primary' : 'text-slate-600 hover:bg-slate-100',
                                    ]"
                                    @click="mobileNavOpen = false"
                                >
                                    <component :is="item.icon" class="h-4 w-4 shrink-0" />
                                    <span class="flex-1">{{ item.label }}</span>
                                    <span
                                        v-if="item.badge"
                                        class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] leading-none font-bold text-white"
                                        >{{ item.badge }}</span
                                    >
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>
                <div class="absolute inset-x-0 bottom-0 border-t border-slate-100 p-3">
                    <div class="flex items-center gap-3 px-2 py-1">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">
                            {{ initials }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ user?.name ?? 'Super Admin' }}</p>
                            <p class="truncate text-xs text-slate-500">{{ user?.email ?? '' }}</p>
                        </div>
                        <Link
                            :href="logout()"
                            as="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600"
                            aria-label="Keluar"
                            @click="handleLogout"
                        >
                            <LogOut class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </SheetContent>
        </Sheet>

        <!-- ============ Konten ============ -->
        <main class="lg:pl-64">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </div>
        </main>
    </div>
</template>
