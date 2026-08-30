<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { LogOut, Shield, Store, Crown, Landmark, History, Bot, Settings2, BarChart3, Ticket, Building2, CreditCard } from 'lucide-vue-next';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

interface Props {
    title: string;
    activeTab?: string;
    pendingPaymentsCount?: number;
}

const props = defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const handleLogout = () => {
    router.flushAll();
};

const tabs = computed(() => [
    { id: 'stores', label: 'Toko', icon: Building2, badge: null, url: '/superadmin' },
    { id: 'payments', label: 'Pembayaran', icon: CreditCard, badge: props.pendingPaymentsCount && props.pendingPaymentsCount > 0 ? props.pendingPaymentsCount : null, url: '/superadmin?tab=payments' },
    { id: 'transfer', label: 'Rekening & QRIS', icon: Landmark, badge: null, url: '/superadmin?tab=transfer' },
    { id: 'plans', label: 'Paket', icon: Crown, badge: null, url: '/superadmin?tab=plans' },
    { id: 'vouchers', label: 'Voucher', icon: Ticket, badge: null, url: '/superadmin?tab=vouchers' },
    { id: 'trial-logs', label: 'Log Trial', icon: History, badge: null, url: '/superadmin?tab=trial-logs' },
    { id: 'ai-auditor', label: 'AI Auditor', icon: Bot, badge: null, url: '/superadmin/ai-auditor' },
    { id: 'ai-settings', label: 'Pengaturan AI', icon: Settings2, badge: null, url: '/superadmin/ai-settings' },
]);
</script>

<template>
    <Head :title="props.title" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <!-- Header -->
        <div class="bg-white/80 backdrop-blur-md border-b border-slate-200/60 sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-20">
                    <div class="flex items-center gap-4">
                        <div class="p-2.5 bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl shadow-lg shadow-red-200">
                            <Shield class="h-6 w-6 text-white" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Super Admin</h1>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Management Panel</p>
                        </div>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                class="flex h-auto items-center gap-4 rounded-2xl px-2 py-1.5 hover:bg-slate-100/80"
                            >
                                <div class="text-right hidden sm:block">
                                    <p class="text-sm font-bold text-slate-800">{{ user.name }}</p>
                                    <p class="text-xs text-slate-400">Super Admin</p>
                                </div>
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 shadow-lg shadow-red-200"
                                >
                                    <span class="text-sm font-black text-white">SA</span>
                                </div>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="z-[60] w-56 rounded-2xl border border-slate-200 p-2 shadow-lg"
                        >
                            <DropdownMenuLabel class="px-2 py-1.5 font-normal">
                                <p class="text-sm font-bold text-slate-900">{{ user.name }}</p>
                                <p class="text-xs text-slate-500">Super Admin</p>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :as-child="true">
                                <Link
                                    class="flex w-full cursor-pointer items-center"
                                    :href="logout()"
                                    as="button"
                                    @click="handleLogout"
                                >
                                    <LogOut class="mr-2 h-4 w-4" />
                                    Keluar
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 lg:pt-8 pb-8">
            <!-- Tab Navigation (Menu Header) -->
            <div class="flex gap-2 mb-8 overflow-x-auto pb-2 scrollbar-hide">
                <Link
                    v-for="tab in tabs"
                    :key="tab.id"
                    :href="tab.url"
                    :class="[
                        'flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold transition-all duration-300 whitespace-nowrap',
                        props.activeTab === tab.id
                            ? 'bg-slate-900 text-white shadow-lg shadow-slate-300'
                            : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                    ]"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                    <span 
                        v-if="tab.badge" 
                        :class="[
                            'px-2 py-0.5 rounded-full text-xs font-black',
                            props.activeTab === tab.id ? 'bg-white/20 text-white' : 'bg-red-100 text-red-700'
                        ]"
                    >{{ tab.badge }}</span>
                </Link>
            </div>

            <!-- Page Content -->
            <slot />
        </div>
    </div>
</template>
