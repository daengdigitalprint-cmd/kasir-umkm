<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { History, Filter, Search, RefreshCw } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface ActivityLogRow {
    id: number;
    store_id: number;
    user_id: number | null;
    action: string;
    description: string | null;
    metadata: Record<string, any> | null;
    ip_address: string | null;
    created_at: string;
    store: { id: number; name: string } | null;
    user: { id: number; name: string; email: string } | null;
}

interface User {
    id: number;
    name: string;
    email: string;
}

interface Props {
    logs: {
        data: ActivityLogRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    actions: string[];
    users: User[];
    filters: {
        store_id: number | null;
        action: string | null;
        user_id: number | null;
        start_date: string | null;
        end_date: string | null;
        search: string | null;
    };
}

const props = defineProps<Props>();

const form = ref({
    store_id: props.filters.store_id?.toString() || '',
    action: props.filters.action || '',
    user_id: props.filters.user_id?.toString() || '',
    start_date: props.filters.start_date || '',
    end_date: props.filters.end_date || '',
    search: props.filters.search || '',
});

const applyFilters = () => {
    router.get('/activity-logs', {
        store_id: form.value.store_id || undefined,
        action: form.value.action || undefined,
        user_id: form.value.user_id || undefined,
        start_date: form.value.start_date || undefined,
        end_date: form.value.end_date || undefined,
        search: form.value.search || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    form.value = {
        store_id: '',
        action: '',
        user_id: '',
        start_date: '',
        end_date: '',
        search: '',
    };
    router.get('/activity-logs', {}, { preserveState: true });
};

const formatDateTime = (iso: string) => {
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getActionBadgeClass = (action: string): string => {
    if (action.includes('login') || action.includes('logout')) return 'bg-blue-100 text-blue-800';
    if (action.includes('created')) return 'bg-green-100 text-green-800';
    if (action.includes('updated')) return 'bg-yellow-100 text-yellow-800';
    if (action.includes('deleted')) return 'bg-red-100 text-red-800';
    return 'bg-slate-100 text-slate-800';
};
</script>

<template>
    <Head title="Log Aktivitas" />

    <AdminLayout title="Log Aktivitas">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div>
                <h1 class="text-2xl font-black text-slate-900">Log Aktivitas</h1>
                <p class="text-sm text-slate-600 mt-1">
                    Riwayat aktivitas pengguna di semua outlet yang Anda kelola
                </p>
            </div>

            <!-- Filter Card -->
            <Card class="rounded-2xl shadow-sm">
                <CardHeader class="pb-4">
                    <div class="flex items-center gap-2">
                        <Filter class="h-5 w-5 text-slate-600" />
                        <CardTitle class="text-lg">Filter</CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Filter Outlet -->
                        <div v-if="$page.props.accessible_stores && $page.props.accessible_stores.length > 1" class="space-y-2">
                            <Label>Outlet</Label>
                            <select v-model="form.store_id" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                                <option value="">Semua outlet</option>
                                <option
                                    v-for="store in $page.props.accessible_stores"
                                    :key="store.id"
                                    :value="store.id.toString()"
                                >
                                    {{ store.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Filter Aksi -->
                        <div class="space-y-2">
                            <Label>Jenis Aksi</Label>
                            <select v-model="form.action" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                                <option value="">Semua aksi</option>
                                <option v-for="action in actions" :key="action" :value="action">
                                    {{ action }}
                                </option>
                            </select>
                        </div>

                        <!-- Filter User -->
                        <div class="space-y-2">
                            <Label>Pengguna</Label>
                            <select v-model="form.user_id" class="w-full rounded-lg border border-slate-200 px-3 py-2">
                                <option value="">Semua pengguna</option>
                                <option
                                    v-for="user in users"
                                    :key="user.id"
                                    :value="user.id.toString()"
                                >
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Filter Tanggal Mulai -->
                        <div class="space-y-2">
                            <Label>Tanggal Mulai</Label>
                            <Input v-model="form.start_date" type="date" />
                        </div>

                        <!-- Filter Tanggal Akhir -->
                        <div class="space-y-2">
                            <Label>Tanggal Akhir</Label>
                            <Input v-model="form.end_date" type="date" />
                        </div>

                        <!-- Pencarian -->
                        <div class="space-y-2">
                            <Label>Cari Deskripsi</Label>
                            <div class="relative">
                                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                                <Input
                                    v-model="form.search"
                                    placeholder="Kata kunci..."
                                    class="pl-10"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button @click="applyFilters" class="rounded-xl">
                            <Filter class="h-4 w-4 mr-2" />
                            Terapkan Filter
                        </Button>
                        <Button @click="resetFilters" variant="outline" class="rounded-xl">
                            <RefreshCw class="h-4 w-4 mr-2" />
                            Reset
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Logs Table -->
            <Card class="rounded-2xl shadow-sm">
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Waktu</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Outlet</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Aksi</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Pengguna</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Deskripsi</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="logs.data.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                                        <History class="h-12 w-12 mx-auto mb-2 text-slate-300" />
                                        <p>Tidak ada log aktivitas</p>
                                    </td>
                                </tr>
                                <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-3 text-sm text-slate-600 whitespace-nowrap">
                                        {{ formatDateTime(log.created_at) }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                        {{ log.store?.name || '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            :class="getActionBadgeClass(log.action)"
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                        >
                                            {{ log.action }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-700">
                                        <div v-if="log.user">
                                            <div class="font-medium">{{ log.user.name }}</div>
                                            <div class="text-xs text-slate-500">{{ log.user.email }}</div>
                                        </div>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-600 max-w-md">
                                        {{ log.description || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-500 font-mono">
                                        {{ log.ip_address || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="logs.last_page > 1" class="px-4 py-3 border-t bg-slate-50 flex items-center justify-between">
                        <div class="text-sm text-slate-600">
                            Menampilkan {{ logs.data.length }} dari {{ logs.total }} log
                        </div>
                        <div class="flex gap-1">
                            <Button
                                v-for="link in logs.links"
                                :key="link.label"
                                :disabled="!link.url || link.active"
                                :variant="link.active ? 'default' : 'outline'"
                                size="sm"
                                class="rounded-lg"
                                @click="link.url && router.visit(link.url)"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
