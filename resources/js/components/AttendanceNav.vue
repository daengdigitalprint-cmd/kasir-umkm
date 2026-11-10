<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    CalendarCheck,
    CalendarOff,
    CalendarRange,
    Clock,
    Coins,
    History,
    Settings,
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
const abilities = (page.props.abilities ?? {}) as { backoffice?: boolean; manage_store_settings?: boolean };
const isBackoffice = Boolean(abilities.backoffice);
const canManageSettings = Boolean(abilities.manage_store_settings);
const payrollEnabled = Boolean(page.props.payroll_enabled);
const pending = computed(() => (page.props.pending_hr_requests ?? {}) as { leave?: number; overtime?: number });

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
