<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    CalendarCheck,
    CalendarClock,
    CalendarOff,
    Clock,
    History,
    Settings,
    Users,
} from 'lucide-vue-next';

const page = usePage();
const abilities = (page.props.abilities ?? {}) as { backoffice?: boolean; manage_store_settings?: boolean };
const isBackoffice = Boolean(abilities.backoffice);
const canManageSettings = Boolean(abilities.manage_store_settings);

const items = computed(() => [
    { label: 'Absensi Saya', href: '/attendance', icon: CalendarCheck },
    ...(isBackoffice
        ? [
            { label: 'Karyawan', href: '/attendance/employees', icon: Users },
            { label: 'Laporan', href: '/attendance/report', icon: BarChart3 },
            { label: 'Jadwal Kerja', href: '/attendance/schedules', icon: CalendarClock },
            { label: 'Cuti', href: '/attendance/leaves', icon: CalendarOff },
            { label: 'Lembur', href: '/attendance/overtimes', icon: Clock },
        ]
        : [
            { label: 'Riwayat', href: '/attendance/history', icon: History },
            { label: 'Ajukan Cuti', href: '/attendance/my-leaves', icon: CalendarOff },
            { label: 'Ajukan Lembur', href: '/attendance/my-overtimes', icon: Clock },
        ]),
    ...(canManageSettings ? [{ label: 'Pengaturan', href: '/attendance/settings', icon: Settings }] : []),
]);

const current = usePage().url;

const isActive = (href: string) =>
    href === '/attendance' ? current === '/attendance' : current.startsWith(href);
</script>

<template>
    <nav class="flex gap-1.5 overflow-x-auto rounded-xl bg-white p-1.5 ring-1 ring-slate-200 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
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
        </Link>
    </nav>
</template>
