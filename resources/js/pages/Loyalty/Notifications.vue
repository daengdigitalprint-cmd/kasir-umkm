<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { index as loyaltyIndexRoute, notifications as notificationsRoute } from '@/routes/loyalty';
import { readAll as readAllRoute, read as readNotificationRoute } from '@/routes/loyalty/notifications';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bell, Check, CheckCheck, Coins, Gift, Hourglass, Inbox, Megaphone } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Notification {
    id: number;
    type: string;
    channel: string;
    title: string;
    body: string | null;
    customer_id: number | null;
    customer_name: string | null;
    read_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    notifications: {
        data: Notification[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    unreadCount: number;
    typeCounts: Record<string, number>;
    filters: { type: string | null; status: 'all' | 'unread' };
}>();

const typeMeta: Record<string, { label: string; icon: typeof Bell; tone: string }> = {
    points_earned: { label: 'Poin bertambah', icon: Coins, tone: 'bg-emerald-50 text-emerald-600' },
    points_expiring: { label: 'Poin kadaluarsa', icon: Hourglass, tone: 'bg-amber-50 text-amber-600' },
    redemption_success: { label: 'Penukaran', icon: Gift, tone: 'bg-violet-50 text-violet-600' },
    promo: { label: 'Promo', icon: Megaphone, tone: 'bg-sky-50 text-sky-600' },
};
const metaOf = (t: string) => typeMeta[t] ?? { label: t, icon: Bell, tone: 'bg-slate-100 text-slate-500' };

const totalAll = computed(() => Object.values(props.typeCounts).reduce((a, b) => a + Number(b), 0));

// --- Filter (diproses di server agar akurat lintas halaman) ---
const applyFilter = (next: Partial<{ type: string | null; status: 'all' | 'unread' }>) => {
    const f = { ...props.filters, ...next };
    const query: Record<string, string> = {};
    if (f.type) query.type = f.type;
    if (f.status === 'unread') query.status = 'unread';
    router.get(notificationsRoute.url({ query }), {}, { preserveScroll: true, preserveState: true, replace: true });
};

// --- Kelompokkan per tanggal ---
const dayKey = (d: string | null) => (d ? new Date(d).toDateString() : '');
const dayLabel = (d: string | null) => {
    if (!d) return 'Tanpa tanggal';
    const date = new Date(d);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === today.toDateString()) return 'Hari ini';
    if (date.toDateString() === yesterday.toDateString()) return 'Kemarin';
    return date.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};
const groups = computed(() => {
    const out: { key: string; label: string; items: Notification[] }[] = [];
    for (const n of props.notifications.data) {
        const key = dayKey(n.created_at);
        let g = out.find((x) => x.key === key);
        if (!g) {
            g = { key, label: dayLabel(n.created_at), items: [] };
            out.push(g);
        }
        g.items.push(n);
    }
    return out;
});
const timeOf = (d: string | null) => (d ? new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '');

// --- Aksi ---
const markRead = (n: Notification) => {
    router.post(readNotificationRoute.url(n.id), {}, { preserveScroll: true });
};
const markingAll = ref(false);
const markAllRead = () => {
    router.post(
        readAllRoute.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (markingAll.value = true),
            onFinish: () => (markingAll.value = false),
        },
    );
};

// Label bawaan paginator bisa berisi entitas HTML (&laquo; / &raquo;) dan teks bahasa lain.
const pageLabel = (label: string) => (label.includes('laquo') ? '‹' : label.includes('raquo') ? '›' : label);
</script>

<template>
    <Head title="Notifikasi Loyalitas" />

    <AdminLayout title="Notifikasi Loyalitas">
        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <!-- Kepala halaman -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link :href="loyaltyIndexRoute.url()">
                        <Button variant="outline" size="sm" class="h-9 w-9 rounded-xl p-0" title="Kembali ke Program Loyalitas">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Notifikasi pelanggan</h2>
                        <p class="text-sm text-slate-500">
                            Riwayat pesan poin, penukaran, dan promo yang dikirim ke pelanggan.
                            <span v-if="unreadCount > 0" class="font-semibold text-primary">{{ unreadCount }} belum dibaca.</span>
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" :disabled="unreadCount === 0 || markingAll" @click="markAllRead">
                    <CheckCheck class="h-4 w-4" /> Tandai semua dibaca
                </Button>
            </div>

            <!-- Filter -->
            <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex gap-1 rounded-xl bg-slate-100/70 p-1">
                    <button
                        v-for="s in [
                            { key: 'all', label: 'Semua' },
                            { key: 'unread', label: `Belum dibaca (${unreadCount})` },
                        ] as const"
                        :key="s.key"
                        type="button"
                        class="flex-1 rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="filters.status === s.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                        @click="applyFilter({ status: s.key })"
                    >
                        {{ s.label }}
                    </button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                        :class="
                            !filters.type ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="applyFilter({ type: null })"
                    >
                        Semua jenis · {{ totalAll }}
                    </button>
                    <button
                        v-for="(meta, key) in typeMeta"
                        :key="key"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition"
                        :class="
                            filters.type === key
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="applyFilter({ type: key })"
                    >
                        <component :is="meta.icon" class="h-3.5 w-3.5" />
                        {{ meta.label }} · {{ typeCounts[key] ?? 0 }}
                    </button>
                </div>
            </div>

            <!-- Daftar per tanggal -->
            <div v-if="notifications.data.length" class="space-y-5">
                <section v-for="g in groups" :key="g.key">
                    <h3 class="mb-2 px-1 text-xs font-bold tracking-wide text-slate-500 uppercase">{{ g.label }}</h3>
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div v-for="n in g.items" :key="n.id" class="flex items-start gap-3 p-4 transition" :class="n.read_at ? '' : 'bg-primary/5'">
                            <div class="relative mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="metaOf(n.type).tone">
                                <component :is="metaOf(n.type).icon" class="h-5 w-5" />
                                <span v-if="!n.read_at" class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-primary ring-2 ring-white" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <p class="font-semibold text-slate-900" :class="{ 'font-bold': !n.read_at }">{{ n.title }}</p>
                                    <span class="text-xs text-slate-400 tabular-nums">{{ timeOf(n.created_at) }}</span>
                                </div>
                                <p v-if="n.body" class="mt-0.5 text-sm text-slate-600">{{ n.body }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="rounded-full px-2 py-0.5 font-medium" :class="metaOf(n.type).tone">{{ metaOf(n.type).label }}</span>
                                    <Link
                                        v-if="n.customer_id && n.customer_name"
                                        :href="`/customers/${n.customer_id}`"
                                        class="font-medium text-slate-600 hover:text-primary hover:underline"
                                    >
                                        {{ n.customer_name }}
                                    </Link>
                                </div>
                            </div>
                            <Button
                                v-if="!n.read_at"
                                variant="ghost"
                                size="sm"
                                class="h-8 shrink-0 gap-1 rounded-lg px-2 text-xs text-slate-500 hover:text-primary"
                                title="Tandai sudah dibaca"
                                @click="markRead(n)"
                            >
                                <Check class="h-4 w-4" /> <span class="hidden sm:inline">Dibaca</span>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Kosong -->
            <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10">
                    <Inbox class="h-6 w-6 text-primary" />
                </div>
                <p class="mt-3 font-bold text-slate-900">
                    {{ filters.type || filters.status === 'unread' ? 'Tidak ada notifikasi yang cocok' : 'Belum ada notifikasi' }}
                </p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                    <template v-if="filters.type || filters.status === 'unread'">Coba ubah filter di atas.</template>
                    <template v-else
                        >Notifikasi muncul otomatis saat pelanggan mendapat poin, menukar hadiah, atau saat Anda mengirim promo.</template
                    >
                </p>
                <Button
                    v-if="filters.type || filters.status === 'unread'"
                    variant="outline"
                    size="sm"
                    class="mt-4 rounded-xl"
                    @click="applyFilter({ type: null, status: 'all' })"
                >
                    Tampilkan semua
                </Button>
            </div>

            <!-- Paginasi -->
            <div v-if="notifications.links.length > 3" class="flex flex-col items-center gap-2">
                <p class="text-xs text-slate-500">
                    Menampilkan {{ notifications.from }}–{{ notifications.to }} dari {{ notifications.total }} notifikasi
                </p>
                <div class="flex flex-wrap justify-center gap-1">
                    <template v-for="(link, i) in notifications.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm"
                            :class="
                                link.active ? 'border-primary bg-primary text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                            "
                        >
                            {{ pageLabel(link.label) }}
                        </Link>
                        <span v-else class="min-w-9 rounded-lg border border-slate-100 px-3 py-1.5 text-center text-sm text-slate-300">{{
                            pageLabel(link.label)
                        }}</span>
                    </template>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
