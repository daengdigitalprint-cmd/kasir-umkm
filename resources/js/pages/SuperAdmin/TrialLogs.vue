<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { History, ArrowLeft, CheckCircle, XCircle, Clock, AlertTriangle, Shield } from 'lucide-vue-next';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LogOut } from 'lucide-vue-next';

interface TrialLog {
    id: number;
    action: string;
    old_plan_slug: string | null;
    new_plan_slug: string | null;
    trial_start: string | null;
    trial_end: string | null;
    notes: string | null;
    created_at: string;
    store: { id: number; name: string; slug: string } | null;
    admin: { id: number; name: string } | null;
}

interface Props {
    logs: {
        data: TrialLog[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    user: { name: string };
}

const props = defineProps<Props>();

const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const actionLabel = (a: string) =>
    ({ activated: 'Diaktifkan', stopped: 'Dihentikan', expired: 'Berakhir' })[a] ?? a;

const actionIcon = (a: string) =>
    ({ activated: CheckCircle, stopped: XCircle, expired: Clock })[a] ?? Clock;

const actionClass = (a: string) =>
    ({
        activated: 'bg-green-100 text-green-800',
        stopped: 'bg-amber-100 text-amber-800',
        expired: 'bg-slate-100 text-slate-600',
    })[a] ?? 'bg-slate-100 text-slate-600';

const handleLogout = () => { router.flushAll(); };
</script>

<template>
    <Head title="Log Trial Pro — Super Admin" />

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
                        <DropdownMenuContent align="end" class="z-[60] w-56 rounded-2xl border border-slate-200 p-2 shadow-lg">
                            <DropdownMenuItem :as-child="true">
                                <Link class="flex w-full cursor-pointer items-center" :href="'/superadmin'" as="button">
                                    <ArrowLeft class="mr-2 h-4 w-4" />
                                    Dashboard
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :as-child="true">
                                <Link class="flex w-full cursor-pointer items-center" :href="'/logout'" as="button" @click="handleLogout">
                                    <LogOut class="mr-2 h-4 w-4" />
                                    Keluar
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 lg:pt-8 pb-8">
            <div class="flex items-center gap-3 mb-6">
                <Link href="/superadmin">
                    <Button variant="ghost" size="sm" class="gap-1.5 rounded-xl">
                        <ArrowLeft class="h-4 w-4" /> Kembali
                    </Button>
                </Link>
                <h1 class="flex-1 text-xl font-black text-slate-900">Log Aktivitas Trial Pro</h1>
            </div>

            <Card class="border-0 shadow-lg rounded-3xl">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <History class="h-5 w-5 text-slate-600" />
                        Riwayat Aktivasi / Penghentian / Kedaluwarsa
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="logs.data.length === 0" class="text-center py-12">
                        <div class="p-4 bg-slate-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                            <History class="h-8 w-8 text-slate-400" />
                        </div>
                        <p class="text-slate-500">Belum ada aktivitas trial</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="log in logs.data"
                            :key="log.id"
                            class="border border-slate-200 rounded-2xl p-4 hover:shadow-md transition-all duration-300"
                        >
                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <Badge :class="actionClass(log.action)">
                                            <component :is="actionIcon(log.action)" class="h-3 w-3 mr-1" />
                                            {{ actionLabel(log.action) }}
                                        </Badge>
                                        <span class="font-bold text-slate-900">{{ log.store?.name ?? '-' }}</span>
                                    </div>
                                    <div class="mt-2 space-y-1 text-sm text-slate-600">
                                        <p v-if="log.old_plan_slug || log.new_plan_slug">
                                            <span class="font-medium">Paket:</span>
                                            {{ log.old_plan_slug ?? '-' }}
                                            <span class="text-slate-400">→</span>
                                            {{ log.new_plan_slug ?? '-' }}
                                        </p>
                                        <p v-if="log.trial_start && log.trial_end">
                                            <span class="font-medium">Periode:</span>
                                            {{ formatDate(log.trial_start) }}
                                            <span class="text-slate-400">—</span>
                                            {{ formatDate(log.trial_end) }}
                                        </p>
                                        <p v-if="log.notes" class="text-xs text-slate-400 italic">"{{ log.notes }}"</p>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-xs text-slate-400">{{ formatDate(log.created_at) }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        {{ log.admin ? `oleh ${log.admin.name}` : 'otomatis (sistem)' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="logs.links && logs.links.length > 3" class="mt-6 flex justify-center">
                        <nav class="flex items-center gap-1">
                            <Link
                                v-for="link in logs.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                :class="[
                                    'px-3 py-2 text-sm rounded-xl font-bold transition-colors',
                                    link.active
                                        ? 'bg-slate-900 text-white'
                                        : 'text-slate-600 hover:bg-slate-100'
                                ]"
                            >
                                <span v-html="link.label" />
                            </Link>
                        </nav>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
