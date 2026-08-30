<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Clock, Banknote, LockKeyhole } from 'lucide-vue-next';
import { toastSuccess, toastWarning } from '@/lib/swal';

interface ShiftRow {
    id: number;
    shift_number: string;
    user_name: string | null;
    opened_at: string | null;
    closed_at: string | null;
    opening_cash: number;
    expected_cash: number;
    closing_cash_counted: number | null;
    difference_amount: number | null;
    closing_notes: string | null;
    transactions_count: number;
}

interface OpenShift {
    id: number;
    shift_number: string;
    opened_at: string | null;
    opening_cash: number;
    expected_cash: number;
}

/** Shift aktif milik kasir lain di outlet yang sama. */
interface ActiveShiftByOther {
    shift_number: string;
    user_name: string;
    opened_at: string | null;
}

/** Data popup pemblokiran (dari flash `shift_blocked`). */
interface ShiftBlockedInfo {
    shift_number: string;
    user_name: string;
    opened_at: string | null;
    store_name?: string;
}

const props = defineProps<{
    shifts: ShiftRow[];
    openShift: OpenShift | null;
    activeShiftByOther?: ActiveShiftByOther | null;
}>();

const page = usePage();

const flashSuccess = computed(() => (page.props as { flash?: { success?: string } }).flash?.success);
const flashWarning = computed(() => (page.props as { flash?: { warning?: string } }).flash?.warning);
const flashBlocked = computed(
    () => (page.props as { flash?: { shift_blocked?: ShiftBlockedInfo | null } }).flash?.shift_blocked ?? null,
);

watch(flashSuccess, (m) => {
    if (m) void toastSuccess(m);
});
watch(flashWarning, (m) => {
    if (m) void toastWarning(m);
});

// Popup pemblokiran: muncul otomatis saat upaya buka shift diblokir karena
// sudah ada shift aktif oleh kasir lain di outlet yang sama.
const blockedModalOpen = ref(false);
const blockedInfo = ref<ShiftBlockedInfo | null>(null);

watch(flashBlocked, (info) => {
    if (info) {
        blockedInfo.value = info;
        blockedModalOpen.value = true;
    }
});

function confirmBlocked() {
    blockedModalOpen.value = false;
    blockedInfo.value = null;
}

const openForm = useForm({ opening_cash: '0' });

// Tampilkan kas awal dengan pemisah ribuan (contoh: 900.000), simpan tanpa titik.
const openingCashDisplay = computed({
    get() {
        const raw = openForm.opening_cash;
        if (!raw) return '';
        return Number(raw).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    set(val: string) {
        openForm.opening_cash = val.replace(/[^0-9]/g, '');
    },
});

const closeForm = useForm({
    closing_cash_counted: '',
    closing_notes: '',
});

const closingCashDisplay = computed({
    get() {
        const raw = closeForm.closing_cash_counted;
        if (!raw) return '';
        return Number(raw).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    set(val: string) {
        closeForm.closing_cash_counted = val.replace(/[^0-9]/g, '');
    },
});

function submitOpen() {
    openForm.post('/cash-shifts/open', { preserveScroll: true });
}

function submitClose() {
    if (!props.openShift) return;
    closeForm.post(`/cash-shifts/${props.openShift.id}/close`, { preserveScroll: true });
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatDt(iso: string | null) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <AdminLayout title="Shift kasir">
        <Head title="Shift kasir" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <Clock class="h-7 w-7 text-primary" />
                Shift kasir
            </h1>
            <p class="text-sm text-slate-600 mt-1">Hanya satu shift aktif per outlet. Penjualan tunai di POS tertaut untuk tutup kas.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card v-if="!openShift && !activeShiftByOther" class="border-0 shadow-lg border-l-4 border-l-amber-400">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Banknote class="h-5 w-5 text-amber-600" />
                        Buka shift
                    </CardTitle>
                    <CardDescription>Masukkan kas awal (uang di laci) sebelum melayani pembayaran tunai.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submitOpen">
                        <div class="space-y-2">
                            <Label for="opening_cash">Kas awal (Rp)</Label>
                            <Input
                                id="opening_cash"
                                v-model="openingCashDisplay"
                                type="text"
                                inputmode="decimal"
                                class="rounded-xl"
                                required
                            />
                            <p v-if="openForm.errors.opening_cash" class="text-sm text-red-600">{{ openForm.errors.opening_cash }}</p>
                        </div>
                        <Button type="submit" class="rounded-xl" :disabled="openForm.processing">Buka shift</Button>
                    </form>
                </CardContent>
            </Card>

            <Card v-else-if="activeShiftByOther && !openShift" class="border-0 shadow-lg border-l-4 border-l-red-400 bg-red-50/40">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <LockKeyhole class="h-5 w-5 text-red-600" />
                        Shift sedang aktif oleh kasir lain
                    </CardTitle>
                    <CardDescription>
                        Shift <span class="font-mono font-semibold">{{ activeShiftByOther.shift_number }}</span> dibuka oleh
                        <span class="font-semibold">{{ activeShiftByOther.user_name }}</span> pada
                        {{ formatDt(activeShiftByOther.opened_at) }}. Anda tidak dapat membuka shift baru di outlet ini selama
                        shift tersebut masih berjalan.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Link href="/pos" class="text-primary text-sm font-medium hover:underline">Kembali ke POS</Link>
                </CardContent>
            </Card>

            <Card v-else class="border-0 shadow-lg border-l-4 border-l-emerald-500">
                <CardHeader>
                    <CardTitle>Shift aktif: {{ openShift.shift_number }}</CardTitle>
                    <CardDescription>
                        Dibuka {{ formatDt(openShift.opened_at) }} · Kas awal Rp {{ formatMoney(openShift.opening_cash) }} · Perkiraan kas (tunai
                        selesai) Rp {{ formatMoney(openShift.expected_cash) }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submitClose">
                        <div class="space-y-2">
                            <Label for="closing_cash_counted">Kas dihitung saat tutup (Rp)</Label>
                            <Input
                                id="closing_cash_counted"
                                v-model="closingCashDisplay"
                                type="text"
                                inputmode="numeric"
                                class="rounded-xl"
                                :placeholder="formatMoney(openShift.expected_cash)"
                                required
                            />
                            <p v-if="closeForm.errors.closing_cash_counted" class="text-sm text-red-600">
                                {{ closeForm.errors.closing_cash_counted }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="closing_notes">Catatan (opsional)</Label>
                            <Input id="closing_notes" v-model="closeForm.closing_notes" type="text" class="rounded-xl" maxlength="2000" />
                        </div>
                        <div class="flex gap-3">
                            <Button type="submit" variant="destructive" class="rounded-xl" :disabled="closeForm.processing">Tutup shift</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <Card class="border-0 shadow-lg mt-8">
            <CardHeader>
                <CardTitle>Riwayat shift Anda</CardTitle>
                <CardDescription>Ringkasan perkiraan vs kas dihitung (setelah tutup).</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="pb-3 pr-3 font-medium">Shift</th>
                            <th class="pb-3 pr-3 font-medium">Buka</th>
                            <th class="pb-3 pr-3 font-medium">Tutup</th>
                            <th class="pb-3 pr-3 font-medium text-right">Kas awal</th>
                            <th class="pb-3 pr-3 font-medium text-right">Perkiraan</th>
                            <th class="pb-3 pr-3 font-medium text-right">Dihitung</th>
                            <th class="pb-3 pr-3 font-medium text-right">Selisih</th>
                            <th class="pb-3 font-medium text-right">Trx</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shifts" :key="s.id" class="border-b border-slate-100">
                            <td class="py-3 pr-3">
                                <span class="text-xs font-semibold text-slate-800">{{ s.user_name || 'Kasir' }}</span>
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap">{{ formatDt(s.opened_at) }}</td>
                            <td class="py-3 pr-3 whitespace-nowrap">{{ s.closed_at ? formatDt(s.closed_at) : '—' }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(s.opening_cash) }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(s.expected_cash) }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums">
                                {{ s.closing_cash_counted !== null ? `Rp ${formatMoney(s.closing_cash_counted)}` : '—' }}
                            </td>
                            <td class="py-3 pr-3 text-right">
                                <template v-if="s.difference_amount !== null">
                                    <Badge :variant="s.difference_amount === 0 ? 'secondary' : 'outline'" class="tabular-nums">
                                        {{ s.difference_amount >= 0 ? '+' : '' }}{{ formatMoney(s.difference_amount) }}
                                    </Badge>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="py-3 text-right">{{ s.transactions_count }}</td>
                        </tr>
                        <tr v-if="shifts.length === 0">
                            <td colspan="8" class="py-12 text-center text-slate-500">Belum ada riwayat shift.</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-4 text-xs text-slate-500">
                    <Link href="/pos" class="text-primary font-medium hover:underline">Kembali ke POS</Link>
                </p>
            </CardContent>
        </Card>

        <!-- Popup konfirmasi: shift sudah dibuka oleh kasir lain di outlet yang sama.
             Hanya bisa ditutup setelah pengguna mengonfirmasi (tombol "Mengerti").
             Tidak ada X, tidak ada close saat klik backdrop, dan tidak ada close via ESC,
             sehingga tidak ada alur yang memungkinkan melanjutkan pembukaan shift. -->
        <Teleport to="body">
            <div
                v-if="blockedModalOpen"
                class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                aria-labelledby="blocked-shift-title"
                aria-describedby="blocked-shift-desc"
            >
                <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full border border-slate-200 p-6">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-red-100 flex-shrink-0">
                            <LockKeyhole class="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h2 id="blocked-shift-title" class="text-lg font-black text-slate-900">Shift sudah dibuka</h2>
                            <p id="blocked-shift-desc" class="text-sm text-slate-600 mt-1">
                                Shift <span class="font-mono font-semibold">{{ blockedInfo?.shift_number }}</span> sudah dibuka oleh
                                <span class="font-semibold">{{ blockedInfo?.user_name }}</span> pada
                                <span class="font-semibold">{{ formatDt(blockedInfo?.opened_at ?? null) }}</span>.
                                Anda tidak dapat membuka shift baru selama shift tersebut masih aktif.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <Button class="rounded-xl" @click="confirmBlocked">Mengerti</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
