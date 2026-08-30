<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarTrigger } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BookOpen, Folder, LayoutGrid, ShoppingCart, Package, Users, BarChart3, Settings, Wallet, QrCode } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'POS',
        href: '/pos',
        icon: ShoppingCart,
    },
    {
        title: 'Manajemen Meja',
        href: '/tables',
        icon: QrCode,
    },
    {
        title: 'Produk',
        href: '/products',
        icon: Package,
    },
    {
        title: 'Pelanggan',
        href: '/customers',
        icon: Users,
    },
    {
        title: 'Laporan',
        href: '/reports',
        icon: BarChart3,
    },
    {
        title: 'Biaya Usaha',
        href: '/expenses',
        icon: Wallet,
    },
    {
        title: 'Pengaturan',
        href: '/settings',
        icon: Settings,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="offcanvas" class="border-r border-slate-200 bg-white">
        <!-- Branding Header -->
        <SidebarHeader class="h-20 px-4 flex items-center border-b border-slate-100">
            <Link :href="dashboard()" class="flex-1 min-w-0">
                <AppLogo />
            </Link>
        </SidebarHeader>

        <!-- Navigation Menu -->
        <SidebarContent class="py-4">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <!-- Footer Profile -->
        <SidebarFooter class="p-4 border-t border-slate-50">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
