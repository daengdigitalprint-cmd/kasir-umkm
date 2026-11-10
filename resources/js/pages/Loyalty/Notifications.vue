<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Bell, Check } from 'lucide-vue-next';
import { index as loyaltyIndexRoute } from '@/routes/loyalty';
import { read as readNotificationRoute } from '@/routes/loyalty/notifications';

interface Notification {
    id: number;
    type: string;
    channel: string;
    title: string;
    body: string | null;
    customer_name: string | null;
    read_at: string | null;
    created_at: string | null;
}

defineProps<{
    notifications: {
        data: Notification[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    unreadCount: number;
}>();

const formatDate = (d: string | null) =>
    d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const typeLabel = (t: string) =>
    ({ points_earned: 'Poin bertambah', points_expiring: 'Poin kadaluarsa', redemption_success: 'Penukaran', promo: 'Promo' })[t] ?? t;

const markRead = (n: Notification) => {
    router.post(readNotificationRoute.url(n.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Notifikasi Loyalitas" />

    <AdminLayout title="Notifikasi Loyalitas">
        <div class="max-w-3xl mx-auto space-y-4 p-4">
            <div class="flex items-center gap-3">
                <Link :href="loyaltyIndexRoute.url()">
                    <Button variant="ghost" size="sm" class="gap-1.5 rounded-xl">
                        <ArrowLeft class="h-4 w-4" /> Kembali
                    </Button>
                </Link>
                <h1 class="flex-1 text-xl font-black text-slate-900 dark:text-slate-100">Notifikasi</h1>
                <Badge v-if="unreadCount > 0" variant="destructive">{{ unreadCount }} belum dibaca</Badge>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm dark:border-slate-700">
                <CardContent class="p-0">
                    <div v-if="notifications.data.length" class="divide-y divide-slate-100 dark:divide-slate-800">
                        <div
                            v-for="n in notifications.data"
                            :key="n.id"
                            class="flex items-start gap-3 p-4"
                            :class="{ 'bg-primary/5 dark:bg-primary/10': !n.read_at }"
                        >
                            <div class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800">
                                <Bell class="h-4 w-4 text-slate-500 dark:text-slate-400" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ n.title }}</p>
                                    <Badge variant="secondary" class="text-[10px]">{{ typeLabel(n.type) }}</Badge>
                                </div>
                                <p v-if="n.body" class="text-sm text-slate-600 dark:text-slate-300">{{ n.body }}</p>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    <span v-if="n.customer_name">{{ n.customer_name }} · </span>{{ formatDate(n.created_at) }}
                                </p>
                            </div>
                            <Button v-if="!n.read_at" variant="ghost" size="sm" class="shrink-0" @click="markRead(n)">
                                <Check class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <p v-else class="p-6 text-center text-sm text-slate-500 dark:text-slate-400">Belum ada notifikasi.</p>
                </CardContent>
            </Card>

            <div v-if="notifications.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Link
                    v-for="(link, i) in notifications.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :class="link.active ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                    v-html="link.label"
                />
            </div>
        </div>
    </AdminLayout>
</template>
