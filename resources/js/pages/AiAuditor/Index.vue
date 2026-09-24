<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { AlertTriangle, Bot, CheckCircle2, FileSearch, Loader2, LogOut, RefreshCw, Settings2, Shield, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { marked } from 'marked';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';

interface TargetUser {
    id: number;
    name: string;
    email: string;
    store_id: number | null;
    store_name: string | null;
    role: string;
    group_name: string;
}

interface Anomaly {
    type: string;
    severity: 'rendah' | 'sedang' | 'tinggi';
    description: string;
    evidence: string;
}

interface Props {
    users: TargetUser[];
    maxRangeDays: number;
    hasApiKey: boolean;
    aiProvider?: string;
    user: { name: string };
}

const props = defineProps<Props>();

// Filter form state
const selectedUserId = ref<number | ''>('');
const startDate = ref<string>('');
const endDate = ref<string>('');
const analyzing = ref(false);

// Hasil analisa
const summary = ref<string>('');
const anomalies = ref<Anomaly[]>([]);
const usage = ref<{ input_tokens: number | null; output_tokens: number | null } | null>(null);
const hasResult = ref(false);

// Error handling: jaringan terputus / token limit / server error
const errorMessage = ref<string>('');

const canAnalyze = computed(() => Boolean(selectedUserId.value && startDate.value && endDate.value) && !analyzing.value);

const groupedUsers = computed(() => {
    const groups: Record<string, TargetUser[]> = {};
    for (const u of props.users) {
        const key = u.group_name;
        if (!groups[key]) groups[key] = [];
        groups[key].push(u);
    }
    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
});

const renderedSummary = computed(() => (summary.value ? marked.parse(summary.value) as string : ''));

const severityClass = (severity: string) => {
    switch (severity) {
        case 'tinggi': return 'bg-red-100 text-red-800';
        case 'sedang': return 'bg-amber-100 text-amber-800';
        default: return 'bg-slate-100 text-slate-700';
    }
};

const formatCurrency = (n: number | null | undefined) => {
    if (n === null || n === undefined) return '-';
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);
};

const resetResult = () => {
    summary.value = '';
    anomalies.value = [];
    usage.value = null;
    hasResult.value = false;
    errorMessage.value = '';
};

async function startAnalysis() {
    if (!canAnalyze.value) return;

    analyzing.value = true;
    resetResult();

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/superadmin/ai-auditor/analyze', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                user_id: selectedUserId.value,
                start_date: startDate.value,
                end_date: endDate.value,
            }),
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            // Error handling: validasi (422), API key belum ada (503), AI gagal (500), token limit, dll.
            errorMessage.value = data.message || 'Analisa gagal dijalankan. Coba lagi nanti.';
            return;
        }

        if (!data.success) {
            errorMessage.value = data.message || 'Analisa gagal dijalankan. Coba lagi nanti.';
            return;
        }

        summary.value = data.summary || '';
        anomalies.value = data.anomalies || [];
        usage.value = data.usage || null;
        hasResult.value = true;
    } catch (err) {
        // Koneksi terputus / timeout
        errorMessage.value = 'Koneksi ke server terputus. Periksa jaringan Anda lalu coba lagi.';
    } finally {
        analyzing.value = false;
    }
}

</script>

<template>
    <SuperAdminLayout title="AI Auditor" active-tab="ai-auditor">
        <div class="max-w-7xl mx-auto space-y-6">
            <!-- Banner peringatan jika API Key belum dikonfigurasi -->
            <Card v-if="!props.hasApiKey" class="border-amber-200 bg-amber-50 rounded-3xl shadow-lg">
                <CardContent class="flex items-start gap-3 p-5">
                    <AlertTriangle class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" />
                    <div class="text-sm text-amber-800">
                        <p class="font-bold">API Key AI belum dikonfigurasi.</p>
                        <p class="mt-1">
                            Anda harus mengatur API Key terlebih dahulu sebelum bisa melakukan analisa.
                        </p>
                        <Link href="/superadmin/ai-settings">
                            <Button size="sm" class="mt-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold">
                                Buka Pengaturan AI
                            </Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Form Filter -->
            <Card class="border-0 shadow-lg rounded-3xl">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <FileSearch class="h-5 w-5 text-slate-600" />
                        Filter Analisa
                    </CardTitle>
                    <CardDescription>
                        Pilih akun user penjual dan rentang tanggal yang akan dianalisa.
                        Maksimal rentang {{ props.maxRangeDays }} hari per analisa.
                        AI hanya melakukan analisa read-only dan tidak dapat mengubah data.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="space-y-1.5">
                            <Label for="user_id">Akun User <span class="text-red-500">*</span></Label>
                            <select
                                id="user_id"
                                v-model="selectedUserId"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            >
                                <option value="" disabled>Pilih user...</option>
                                <optgroup v-for="([groupName, users]) in groupedUsers" :key="groupName" :label="groupName">
                                    <option v-for="u in users" :key="u.id" :value="u.id">
                                        {{ u.name }} ({{ u.email }}){{ u.store_name ? ` - ${u.store_name}` : '' }}
                                    </option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="start_date">Tanggal Mulai <span class="text-red-500">*</span></Label>
                            <Input id="start_date" v-model="startDate" type="date" />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="end_date">Tanggal Selesai <span class="text-red-500">*</span></Label>
                            <Input id="end_date" v-model="endDate" type="date" />
                        </div>
                        <div class="flex items-end">
                            <Button
                                @click="startAnalysis"
                                :disabled="!canAnalyze"
                                class="w-full rounded-xl bg-slate-900 text-white hover:bg-slate-800 font-bold"
                            >
                                <Loader2 v-if="analyzing" class="h-4 w-4 mr-2 animate-spin" />
                                <Sparkles v-else class="h-4 w-4 mr-2" />
                                {{ analyzing ? 'Menganalisa...' : 'Mulai Analisa' }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Loading State -->
            <Card v-if="analyzing" class="border-0 shadow-lg rounded-3xl">
                <CardContent class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="relative">
                        <div class="p-4 bg-gradient-to-br from-red-500 to-rose-600 rounded-3xl shadow-lg shadow-red-200">
                            <Bot class="h-10 w-10 text-white animate-pulse" />
                        </div>
                    </div>
                    <p class="mt-6 text-lg font-black text-slate-900">AI sedang menganalisa data...</p>
                    <p class="mt-1 text-sm text-slate-500">Proses ini bisa memakan waktu hingga 1-2 menit. Mohon tunggu.</p>
                    <div class="mt-6 flex items-center gap-2 text-xs text-slate-400 font-mono">
                        <RefreshCw class="h-3.5 w-3.5 animate-spin" />
                        Membaca transaksi, arus kas, dan detail item...
                    </div>
                </CardContent>
            </Card>

            <!-- Error Handling -->
            <Card v-if="errorMessage && !analyzing" class="border-red-200 bg-red-50 rounded-3xl shadow-lg">
                <CardContent class="flex items-start gap-3 p-5">
                    <AlertTriangle class="h-5 w-5 text-red-600 mt-0.5 shrink-0" />
                    <div class="text-sm text-red-800">
                        <p class="font-bold">Analisa gagal.</p>
                        <p class="mt-1">{{ errorMessage }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Hasil Analisa -->
            <template v-if="hasResult && !analyzing">
                <!-- Ringkasan -->
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-lg">
                                <Bot class="h-5 w-5 text-slate-600" />
                                Ringkasan Audit
                            </div>
                            <Badge
                                :class="anomalies.length === 0
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-red-100 text-red-800'"
                            >
                                {{ anomalies.length === 0 ? 'Data Normal' : `${anomalies.length} Anomali` }}
                            </Badge>
                        </CardTitle>
                        <CardDescription v-if="usage">
                            Token terpakai: {{ usage.input_tokens ?? '-' }} input / {{ usage.output_tokens ?? '-' }} output
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="prose prose-sm max-w-none text-slate-700"
                            v-html="renderedSummary"
                        ></div>
                    </CardContent>
                </Card>

                <!-- Daftar Anomali -->
                <Card v-if="anomalies.length > 0" class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <AlertTriangle class="h-5 w-5 text-red-600" />
                            Anomali Terdeteksi ({{ anomalies.length }})
                        </CardTitle>
                        <CardDescription>
                            Daftar temuan anomali berdasarkan analisa AI. AI tidak mengubah data apa pun;
                            semua temuan bersifat laporan untuk ditindaklanjuti manual.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-for="(anomaly, i) in anomalies"
                            :key="i"
                            class="border border-slate-200 rounded-2xl p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Badge :class="severityClass(anomaly.severity)">
                                            {{ anomaly.severity }}
                                        </Badge>
                                        <span class="text-xs font-mono text-slate-500 uppercase">{{ anomaly.type }}</span>
                                    </div>
                                    <p class="mt-2 text-sm text-slate-800">{{ anomaly.description }}</p>
                                    <p class="mt-2 text-xs text-slate-500 bg-slate-50 rounded-lg px-3 py-2">
                                        <span class="font-bold">Bukti:</span> {{ anomaly.evidence }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Status Normal -->
                <Card v-else class="border-0 shadow-lg rounded-3xl">
                    <CardContent class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="p-4 bg-green-100 rounded-3xl">
                            <CheckCircle2 class="h-10 w-10 text-green-600" />
                        </div>
                        <p class="mt-6 text-lg font-black text-slate-900">Semua data sinkron dan normal</p>
                        <p class="mt-1 text-sm text-slate-500">
                            Tidak ditemukan anomali pada rentang tanggal yang dianalisa.
                        </p>
                    </CardContent>
                </Card>
            </template>

            <!-- Empty State -->
            <Card v-if="!hasResult && !analyzing && !errorMessage" class="border-0 shadow-lg rounded-3xl">
                <CardContent class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="p-4 bg-slate-100 rounded-3xl">
                        <FileSearch class="h-10 w-10 text-slate-400" />
                    </div>
                    <p class="mt-6 text-lg font-black text-slate-900">Belum ada analisa</p>
                    <p class="mt-1 text-sm text-slate-500 max-w-md">
                        Pilih akun user dan rentang tanggal di atas, lalu tekan "Mulai Analisa"
                        untuk menjalankan audit AI terhadap data transaksi, arus kas, dan detail item.
                    </p>
                </CardContent>
            </Card>
        </div>
    </SuperAdminLayout>
</template>
