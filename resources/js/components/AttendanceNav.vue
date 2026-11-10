<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    CalendarCheck,
    CalendarOff,
    CalendarRange,
    Clock,
    Coins,
    History,
    Settings,
    Store,
    Wallet,
} from 'lucide-vue-next';

/**
 * Sub-menu halaman SDM & Gaji.
 *
 * - Karyawan biasa: satu baris tab (absen, riwayat, pengajuan, slip gaji).
 * - Pengelola: menu utama ada di sidebar grup "SDM & Gaji"; di sini hanya tab
 *   untuk halaman yang punya pasangan (Kehadiran, Pengajuan, Penggajian, Pengaturan SDM).
 */
type NavItem = { label: string; href: string; icon: unknown; badge?: number };

const page = usePage();
const abilities = (page.props.abilities ?? {}) as { backoffice?: boolean; manage_hr?: boolean };
const isBackoffice = Boolean(abilities.backoffice);
const canManageSettings = Boolean(abilities.manage_hr);
const payrollEnabled = Boolean(page.props.payroll_enabled);
type OtherOutlet = { id: number; name: string; leave: number; overtime: number };
const pending = computed(
    () => (page.props.pending_hr_requests ?? {}) as { leave?: number; overtime?: number; others?: OtherOutlet[] },
);

/** Di halaman Pengajuan: tampilkan outlet lain yang juga punya pengajuan menunggu. */
const otherOutlets = computed(() => {
    const url = current.value;
    if (!isBackoffice || !(url.startsWith('/attendance/leaves') || url.startsWith('/attendance/overtimes'))) return [];
    return pending.value.others ?? [];
});
const switching = ref<number | null>(null);
const openOutlet = (outlet: OtherOutlet) => {
    switching.value = outlet.id;
    const target = outlet.leave > 0 ? '/attendance/leaves' : '/attendance/overtimes';
    router.post(
        '/stores/switch',
        { store_id: outlet.id },
        { onSuccess: () => router.visit(target), onFinish: () => (switching.value = null) },
    );
};

const current = computed(() => page.url.split('?')[0]);

const staffItems = computed<NavItem[]>(() => [
    { label: 'Absensi Saya', href: '/attendance', icon: CalendarCheck },
    { label: 'Riwayat', href: '/attendance/history', icon: History },
    { label: 'Ajukan Cuti', href: '/attendance/my-leaves', icon: CalendarOff },
    { label: 'Ajukan Lembur', href: '/attendance/my-overtimes', icon: Clock },
    { label: 'Slip Gaji', href: '/attendance/my-payslips', icon: Wallet },
]);

const adminGroups = computed<NavItem[][]>(() => [
    [
        { label: 'Rekap Kehadiran', href: '/attendance/report', icon: BarChart3 },
        { label: 'Absen Saya', href: '/attendance', icon: CalendarCheck },
    ],
    [
        { label: 'Cuti', href: '/attendance/leaves', icon: CalendarOff, badge: pending.value.leave ?? 0 },
        { label: 'Lembur', href: '/attendance/overtimes', icon: Clock, badge: pending.value.overtime ?? 0 },
    ],
    [
        { label: 'Periode Gaji', href: '/payroll', icon: CalendarRange },
        { label: 'Komponen Gaji', href: '/payroll/salaries', icon: Coins },
    ],
    canManageSettings
        ? [
              { label: 'Absensi & Hari Libur', href: '/attendance/settings', icon: Settings },
              ...(payrollEnabled ? [{ label: 'Penggajian', href: '/payroll/settings', icon: Wallet }] : []),
          ]
        : [],
]);

const isActive = (href: string) => {
    const url = current.value;
    if (href === '/attendance') return url === '/attendance';
    if (href === '/payroll') {
        return url === '/payroll' || (url.startsWith('/payroll/') && !url.startsWith('/payroll/salaries') && !url.startsWith('/payroll/settings'));
    }
    return url.startsWith(href);
};

const items = computed<NavItem[]>(() => {
    if (!isBackoffice) return staffItems.value;
    const group = adminGroups.value.find((g) => g.some((item) => isActive(item.href)));
    return group && group.length > 1 ? group : [];
});
</script>

<template>
    <div
        v-if="otherOutlets.length"
        class="mb-3 flex flex-wrap items-center gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-900 ring-1 ring-amber-200"
    >
        <Store class="h-4 w-4 shrink-0 text-amber-600" />
        <span class="font-semibold">Pengajuan menunggu di outlet lain:</span>
        <button
            v-for="outlet in otherOutlets"
            :key="outlet.id"
            type="button"
            class="flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-bold ring-1 ring-amber-200 hover:bg-amber-100 disabled:opacity-60"
            :disabled="switching !== null"
            @click="openOutlet(outlet)"
        >
            {{ outlet.name }}
            <span class="rounded-full bg-red-500 px-1.5 text-[10px] text-white">{{ outlet.leave + outlet.overtime }}</span>
        </button>
    </div>
    <nav
        v-if="items.length"
        class="flex gap-1.5 overflow-x-auto rounded-xl bg-white p-1.5 ring-1 ring-slate-200 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
    >
        <Link
            v-for="item in items"
            :key="item.href"
            :href="item.href"
            class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition"
            :class="isActive(item.href)
                ? 'bg-indigo-600 text-white'
                : 'text-slate-600 hover:bg-slate-100'"
        >
            <component :is="item.icon" class="h-4 w-4" />
            {{ item.label }}
            <span
                v-if="item.badge"
                class="flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-[10px] font-black"
                :class="isActive(item.href) ? 'bg-white text-red-600' : 'bg-red-500 text-white'"
            >
                {{ item.badge > 99 ? '99+' : item.badge }}
            </span>
        </Link>
    </nav>
</template>
