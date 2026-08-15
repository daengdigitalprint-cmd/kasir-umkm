<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { ArrowLeft, Plus, Phone, RefreshCw, Play, CheckCircle2, XCircle, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface QueueEntry {
    id: number;
    queue_id: number;
    queue_name: string | null;
    number: number;
    status: string;
    customer_name: string | null;
    customer_phone: string | null;
    priority: number;
    notes: string | null;
    called_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    transaction_id: number | null;
}

const props = defineProps<{
    queue: { id: number; name: string; code: string; is_active: boolean };
    entries: QueueEntry[];
    store: { id: number; name: string };
}>();

// --- Auto refresh ---
const refresh = () => {
    router.reload({ only: ['entries'] });
};

let pollId: ReturnType<typeof setInterval> | null = null;
onMounted(() => { pollId = setInterval(refresh, 30000); });
onUnmounted(() => { if (pollId !== null) clearInterval(pollId); });

// --- Filter ---
const statusFilter = ref<string>('all');

const filteredEntries = computed(() => {
    if (statusFilter.value === 'all') return props.entries;
    return props.entries.filter((e) => e.status === statusFilter.value);
});

const stats = computed(() => ({
    waiting: props.entries.filter((e) => e.status === 'waiting').length,
    serving: props.entries.filter((e) => e.status === 'serving').length,
    completed: props.entries.filter((e) => e.status === 'completed').length,
    cancelled: props.entries.filter((e) => e.status === 'cancelled').length,
}));

// --- Create entry ---
const showCreateDialog = ref(false);
const createForm = useForm({
    customer_name: '',
    customer_phone: '',
    notes: '',
    priority: 0,
});

const submitCreate = () => {
    createForm.post(`/queues/${props.queue.id}/entries`, {
        onSuccess: () => {
            createForm.reset();
            showCreateDialog.value = false;
        },
    });
};

// --- Status transitions ---
const updateStatus = (entry: QueueEntry, newStatus: string) => {
    router.patch(`/queue-entries/${entry.id}`, { status: newStatus }, { preserveScroll: true });
};

// --- Delete entry ---
const confirmDeleteEntry = ref<QueueEntry | null>(null);

const deleteEntry = (entry: QueueEntry) => {
    router.delete(`/queue-entries/${entry.id}`, {
        onSuccess: () => { confirmDeleteEntry.value = null; },
    });
};

// --- Helpers ---
function statusColor(status: string) {
    switch (status) {
        case 'waiting': return 'bg-amber-50 text-amber-700 border-amber-200';
        case 'serving': return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'cancelled': return 'bg-slate-50 text-slate-500 border-slate-200';
        default: return 'bg-slate-50 text-slate-500 border-slate-200';
    }
}

function statusLabel(status: string) {
    switch (status) {
        case 'waiting': return 'Menunggu';
        case 'serving': return 'Dilayani';
        case 'completed': return 'Selesai';
        case 'cancelled': return 'Dibatalkan';
        default: return status;
    }
}

function timeAgo(dateStr: string | null): string {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Baru saja';
    if (mins < 60) return `${mins} menit lalu`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs} jam lalu`;
    return `${Math.floor(hrs / 24)} hari lalu`;
}
</script>

<template>
    <AdminLayout :title="`Antrian: ${queue.name}`">
        <Head :title="`Antrian: ${queue.name}`" />

        <div class="mx-auto max-w-4xl space-y-6 pb-10">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <Link href="/queues" class="p-2 rounded-xl hover:bg-slate-100 transition-colors">
                        <ArrowLeft class="h-5 w-5 text-slate-500" />
                    </Link>
                    <div>
                        <h1 class="text-lg font-black text-slate-900">{{ queue.name }}</h1>
                        <p class="text-xs text-slate-500">Kode: {{ queue.code }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button type="button" variant="outline" class="gap-2 rounded-xl" @click="refresh">
                        <RefreshCw class="h-4 w-4" />
                        Muat ulang
                    </Button>
                    <Button type="button" class="gap-2 rounded-xl" @click="showCreateDialog = true">
                        <Plus class="h-4 w-4" />
                        Tambah Antrian
                    </Button>
                </div>
            </div>

            <!-- Stats + Filter -->
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="f in [
                        { key: 'all', label: 'Semua', count: entries.length },
                        { key: 'waiting', label: 'Menunggu', count: stats.waiting },
                        { key: 'serving', label: 'Dilayani', count: stats.serving },
                        { key: 'completed', label: 'Selesai', count: stats.completed },
                        { key: 'cancelled', label: 'Dibatalkan', count: stats.cancelled },
                    ]"
                    :key="f.key"
                    type="button"
                    @click="statusFilter = f.key"
                    :class="[
                        'px-3 py-1.5 rounded-xl text-xs font-bold transition-all',
                        statusFilter === f.key
                            ? 'bg-violet-600 text-white shadow-md'
                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
                    ]"
                >
                    {{ f.label }} ({{ f.count }})
                </button>
            </div>

            <!-- Empty -->
            <div v-if="filteredEntries.length === 0" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 py-14 text-center">
                <p class="text-sm text-slate-600 font-medium">
                    {{ statusFilter === 'all' ? 'Belum ada antrian.' : 'Tidak ada antrian dengan status ini.' }}
                </p>
            </div>

            <!-- Entries list -->
            <div v-else class="space-y-3">
                <Card
                    v-for="entry in filteredEntries"
                    :key="entry.id"
                    class="rounded-2xl border-slate-200 overflow-hidden"
                >
                    <CardContent class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-lg font-black text-slate-700 tabular-nums">
                                    #{{ entry.number }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-bold text-sm text-slate-900">
                                            {{ entry.customer_name || 'Tanpa nama' }}
                                        </span>
                                        <span
                                            :class="['text-[10px] font-bold px-2 py-0.5 rounded-full border', statusColor(entry.status)]"
                                        >
                                            {{ statusLabel(entry.status) }}
                                        </span>
                                        <span v-if="entry.priority > 0" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-50 text-red-600 border border-red-200">
                                            Prioritas {{ entry.priority }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        {{ timeAgo(entry.created_at) }}
                                        <span v-if="entry.called_at" class="ml-1">· Dipanggil {{ timeAgo(entry.called_at) }}</span>
                                    </p>
                                    <p v-if="entry.customer_phone" class="text-xs text-slate-500 mt-0.5 flex items-center gap-1">
                                        <Phone class="h-3 w-3" /> {{ entry.customer_phone }}
                                    </p>
                                    <p v-if="entry.notes" class="text-xs text-slate-500 mt-0.5 italic">"{{ entry.notes }}"</p>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-1.5 shrink-0">
                                <Button
                                    v-if="entry.status === 'waiting'"
                                    type="button"
                                    size="sm"
                                    class="gap-1 rounded-xl bg-blue-600 hover:bg-blue-700"
                                    @click="updateStatus(entry, 'serving')"
                                >
                                    <Play class="h-3 w-3" />
                                    Panggil
                                </Button>
                                <Button
                                    v-if="entry.status === 'serving'"
                                    type="button"
                                    size="sm"
                                    class="gap-1 rounded-xl bg-emerald-600 hover:bg-emerald-700"
                                    @click="updateStatus(entry, 'completed')"
                                >
                                    <CheckCircle2 class="h-3 w-3" />
                                    Selesai
                                </Button>
                                <Button
                                    v-if="entry.status === 'waiting'"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="gap-1 rounded-xl text-slate-500"
                                    @click="updateStatus(entry, 'cancelled')"
                                >
                                    <XCircle class="h-3 w-3" />
                                    Batal
                                </Button>
                                <button
                                    type="button"
                                    class="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                    @click="confirmDeleteEntry = entry"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- Create Dialog -->
        <Dialog :open="showCreateDialog" @update:open="showCreateDialog = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Tambah Antrian #{{ (entries.length > 0 ? Math.max(...entries.map(e => e.number)) : 0) + 1 }}</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div class="space-y-2">
                        <Label for="customer_name">Nama Pelanggan</Label>
                        <Input
                            id="customer_name"
                            v-model="createForm.customer_name"
                            placeholder="Kosongkan jika walk-in"
                            maxlength="255"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="customer_phone">No. Telepon</Label>
                        <Input
                            id="customer_phone"
                            v-model="createForm.customer_phone"
                            placeholder="Opsional"
                            maxlength="40"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="priority">Prioritas (0-10)</Label>
                        <Input
                            id="priority"
                            v-model.number="createForm.priority"
                            type="number"
                            min="0"
                            max="10"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="notes">Catatan</Label>
                        <Textarea
                            id="notes"
                            v-model="createForm.notes"
                            placeholder="Opsional"
                            :rows="2"
                            :maxlength="500"
                        />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showCreateDialog = false">Batal</Button>
                        <Button type="submit" :disabled="createForm.processing" class="gap-2">
                            <span v-if="createForm.processing" class="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                            Buat
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete Confirm -->
        <Dialog :open="!!confirmDeleteEntry" @update:open="confirmDeleteEntry = $event ? confirmDeleteEntry : null">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Hapus Antrian?</DialogTitle>
                </DialogHeader>
                <p class="text-sm text-slate-600">
                    Antrian <strong>#{{ confirmDeleteEntry?.number }}</strong> akan dihapus permanen.
                </p>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="confirmDeleteEntry = null">Batal</Button>
                    <Button type="button" variant="destructive" @click="confirmDeleteEntry && deleteEntry(confirmDeleteEntry)">
                        Hapus
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
