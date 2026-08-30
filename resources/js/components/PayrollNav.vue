<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarRange, Coins, Settings } from 'lucide-vue-next';

const items = [
    { label: 'Periode Gaji', href: '/payroll', icon: CalendarRange },
    { label: 'Komponen Gaji', href: '/payroll/salaries', icon: Coins },
    { label: 'Pengaturan', href: '/payroll/settings', icon: Settings },
];

const current = usePage().url;

const isActive = (href: string) =>
    href === '/payroll' ? current === '/payroll' : current.startsWith(href);
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
