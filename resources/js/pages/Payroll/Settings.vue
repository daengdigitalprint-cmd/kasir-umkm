<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import RupiahInput from '@/components/RupiahInput.vue';
import { Label } from '@/components/ui/label';
import { Save, AlertCircle, Clock, Timer, Shield, ShieldCheck, Receipt, ListChecks, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{
    settings: {
        late_penalty_per_day: number;
        late_penalty_per_minute: number;
        late_penalty_method: string;
        overtime_rate_first_hour: number;
        overtime_rate_next_hour: number;
        overtime_rate_holiday: number;
        bpjs_enabled: boolean;
        bpjs_health_employee: number;
        bpjs_health_employer: number;
        bpjs_jht_employee: number;
        bpjs_jht_employer: number;
        bpjs_jp_employee: number;
        bpjs_jp_employer: number;
        bpjs_jkk: number;
        bpjs_jkm: number;
        bpjs_health_wage_cap: number;
        bpjs_jp_wage_cap: number;
        absence_deduction_enabled: boolean;
        pph21_enabled: boolean;
        owner_approval: boolean;
    };
    has_owner: boolean;
}>();

const activeTab = ref<'late' | 'overtime' | 'bpjs' | 'tax' | 'approval'>('late');

const form = useForm({
    late_penalty_per_day: String(props.settings.late_penalty_per_day),
    late_penalty_per_minute: String(props.settings.late_penalty_per_minute),
    late_penalty_method: props.settings.late_penalty_method,
    overtime_rate_first_hour: String(props.settings.overtime_rate_first_hour),
    overtime_rate_next_hour: String(props.settings.overtime_rate_next_hour),
    overtime_rate_holiday: String(props.settings.overtime_rate_holiday),
    bpjs_enabled: props.settings.bpjs_enabled,
    bpjs_health_employee: String(props.settings.bpjs_health_employee),
    bpjs_health_employer: String(props.settings.bpjs_health_employer),
    bpjs_jht_employee: String(props.settings.bpjs_jht_employee),
    bpjs_jht_employer: String(props.settings.bpjs_jht_employer),
    bpjs_jp_employee: String(props.settings.bpjs_jp_employee),
    bpjs_jp_employer: String(props.settings.bpjs_jp_employer),
    bpjs_jkk: String(props.settings.bpjs_jkk),
    bpjs_jkm: String(props.settings.bpjs_jkm),
    bpjs_health_wage_cap: String(Math.round(props.settings.bpjs_health_wage_cap)),
    bpjs_jp_wage_cap: String(Math.round(props.settings.bpjs_jp_wage_cap)),
    absence_deduction_enabled: props.settings.absence_deduction_enabled,
    pph21_enabled: props.settings.pph21_enabled,
    owner_approval: props.settings.owner_approval,
});

const formatAmount = (value: string | number) => {
    const num = String(value).replace(/\D/g, '');
    return num ? new Intl.NumberFormat('id-ID').format(Number(num)) : '';
};

const submit = () => {
    // Rutenya PUT. Dulu ditulis form.post(..., { method: 'put' }), tapi opsi `method` diabaikan
    // sehingga yang terkirim tetap POST dan server menjawab 405.
    form.put('/payroll/settings', {
        preserveScroll: true,
        // Tombol Simpan mengirim semua tab sekaligus. Kalau ada isian yang ditolak di tab lain,
        // pindahkan ke tab itu supaya pesannya terlihat (dulu tampak seperti tidak terjadi apa-apa).
        onError: (errors) => {
            const first = Object.keys(errors)[0];
            const tab = first ? tabOfField(first) : null;
            if (tab) activeTab.value = tab;
        },
    });
};

type TabKey = 'late' | 'overtime' | 'bpjs' | 'tax' | 'approval';
const tabOfField = (field: string): TabKey | null => {
    if (field.startsWith('late_') || field === 'absence_deduction_enabled') return 'late';
    if (field.startsWith('overtime_')) return 'overtime';
    if (field.startsWith('bpjs_')) return 'bpjs';
    if (field === 'pph21_enabled') return 'tax';
    if (field === 'owner_approval') return 'approval';
    return null;
};
const errorCount = computed(() => Object.keys(form.errors).length);
const tabHasError = (tab: TabKey) => Object.keys(form.errors).some((f) => tabOfField(f) === tab);

const rupiah = (value: string | number) => `Rp ${formatAmount(String(Math.round(Number(value) || 0))) || '0'}`;
const kali = (value: string | number) => `${String(Number(value) || 0).replace('.', ',')}×`;

/** Contoh denda di tab Keterlambatan: 2 hari telat, total 30 menit. */
const contohDenda = computed(() => {
    if (form.late_penalty_method === 'none') return 0;
    return form.late_penalty_method === 'per_minute'
        ? 30 * (Number(form.late_penalty_per_minute) || 0)
        : 2 * (Number(form.late_penalty_per_day) || 0);
});

/**
 * Daftar aturan yang SEDANG BERLAKU, dibaca dari data tersimpan (bukan dari isian yang sedang diubah),
 * jadi setelah Simpan daftar ini langsung menunjukkan hasilnya.
 */
const aturan = computed(() => {
    const st = props.settings;
    const telat =
        st.late_penalty_method === 'none'
            ? 'Tidak ada denda'
            : st.late_penalty_method === 'per_minute'
              ? `${rupiah(st.late_penalty_per_minute)} per menit telat`
              : `${rupiah(st.late_penalty_per_day)} per hari telat`;
    return [
        { tab: 'late' as TabKey, label: 'Denda terlambat', nilai: telat, aktif: st.late_penalty_method !== 'none' },
        {
            tab: 'late' as TabKey,
            label: 'Alpa & cuti tanpa gaji',
            nilai: st.absence_deduction_enabled ? 'Gaji dipotong per hari tidak masuk' : 'Tidak memotong gaji',
            aktif: st.absence_deduction_enabled,
        },
        {
            tab: 'overtime' as TabKey,
            label: 'Upah lembur',
            nilai: `Jam pertama ${kali(st.overtime_rate_first_hour)} · jam berikutnya ${kali(st.overtime_rate_next_hour)} · hari libur ${kali(st.overtime_rate_holiday)}`,
            aktif: true,
        },
        {
            tab: 'bpjs' as TabKey,
            label: 'BPJS',
            nilai: st.bpjs_enabled ? 'Dihitung dan dipotong dari gaji' : 'Tidak dihitung',
            aktif: st.bpjs_enabled,
        },
        { tab: 'tax' as TabKey, label: 'PPh 21', nilai: st.pph21_enabled ? 'Dihitung dan dipotong dari gaji' : 'Tidak dihitung', aktif: st.pph21_enabled },
        {
            tab: 'approval' as TabKey,
            label: 'Persetujuan gaji',
            nilai: st.owner_approval ? 'Hanya pemilik yang menyetujui' : 'Admin atau manajer boleh menyetujui',
            aktif: st.owner_approval,
        },
    ];
});

const tabs = [
    { key: 'late' as const, label: 'Keterlambatan', icon: Clock },
    { key: 'overtime' as const, label: 'Lembur', icon: Timer },
    { key: 'bpjs' as const, label: 'BPJS', icon: Shield },
    { key: 'tax' as const, label: 'PPh 21', icon: Receipt },
    { key: 'approval' as const, label: 'Persetujuan', icon: ShieldCheck },
];
</script>

<template>
    <Head title="Pengaturan SDM" />

    <AdminLayout title="Pengaturan SDM · Penggajian">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <!-- Aturan yang sedang berlaku (dari data tersimpan) -->
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="aturan-berlaku" data-testid="aturan-berlaku">
                <div class="flex items-center gap-2">
                    <ListChecks class="h-5 w-5 text-indigo-600" />
                    <h2 id="aturan-berlaku" class="text-base font-bold text-slate-900">Aturan gaji yang berlaku sekarang</h2>
                </div>
                <p class="mt-0.5 text-sm text-slate-500">Dipakai setiap kali gaji dihitung. Tekan salah satu untuk mengubahnya.</p>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                    <li v-for="a in aturan" :key="a.label">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-left transition hover:border-indigo-300 hover:bg-indigo-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
                            @click="activeTab = a.tab"
                        >
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="a.aktif ? 'bg-emerald-500' : 'bg-slate-300'" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ a.label }}</span>
                                <span class="block text-sm font-semibold text-slate-900">{{ a.nilai }}</span>
                            </span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-slate-400" />
                        </button>
                    </li>
                </ul>
            </section>

            <p v-if="errorCount" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" role="alert">
                Belum tersimpan: ada {{ errorCount }} isian yang perlu diperbaiki. Tab yang bermasalah diberi tanda merah.
            </p>
            <p v-else-if="form.isDirty" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                Ada perubahan yang belum disimpan. Tekan <strong>Simpan Pengaturan</strong> agar berlaku.
            </p>

            <!-- Tab Navigation -->
            <div class="flex gap-2 overflow-x-auto rounded-xl bg-white p-1.5 ring-1 ring-slate-200">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === tab.key ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    @click="activeTab = tab.key"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                    <span v-if="tabHasError(tab.key)" class="h-2 w-2 rounded-full bg-red-500" aria-label="ada isian yang perlu diperbaiki" />
                </button>
            </div>

            <!-- Tab: Keterlambatan -->
            <Card v-if="activeTab === 'late'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Potongan Keterlambatan</CardTitle>
                    <CardDescription>
                        Denda dipotong otomatis dari gaji karyawan yang terlambat, berdasarkan data absensinya.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="space-y-1">
                        <Label for="late-method">Cara menghitung denda</Label>
                        <select
                            id="late-method"
                            v-model="form.late_penalty_method"
                            class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="per_day">Per hari telat (berapa pun menitnya, dendanya sama)</option>
                            <option value="per_minute">Per menit telat (makin lama telat, makin besar)</option>
                            <option value="none">Tanpa denda</option>
                        </select>
                        <p class="text-xs text-slate-500">Hanya satu cara yang dipakai. Isian di bawah menyesuaikan pilihan ini.</p>
                        <p v-if="form.errors.late_penalty_method" class="text-sm text-red-600">{{ form.errors.late_penalty_method }}</p>
                    </div>

                    <div v-if="form.late_penalty_method === 'per_day'" class="space-y-1 sm:max-w-xs">
                        <Label for="late-per-day">Denda per hari telat (Rp)</Label>
                        <RupiahInput id="late-per-day" v-model="form.late_penalty_per_day" placeholder="10.000" />
                        <p class="text-xs text-slate-500">Dikenakan sekali untuk setiap hari karyawan datang terlambat.</p>
                        <p v-if="form.errors.late_penalty_per_day" class="text-sm text-red-600">{{ form.errors.late_penalty_per_day }}</p>
                    </div>
                    <div v-else-if="form.late_penalty_method === 'per_minute'" class="space-y-1 sm:max-w-xs">
                        <Label for="late-per-minute">Denda per menit telat (Rp)</Label>
                        <RupiahInput id="late-per-minute" v-model="form.late_penalty_per_minute" placeholder="100" />
                        <p class="text-xs text-slate-500">Dikalikan jumlah menit terlambat selama periode gaji.</p>
                        <p v-if="form.errors.late_penalty_per_minute" class="text-sm text-red-600">{{ form.errors.late_penalty_per_minute }}</p>
                    </div>
                    <p v-else class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">Keterlambatan tetap tercatat di absensi, tetapi tidak memotong gaji.</p>

                    <div class="rounded-lg bg-amber-50 border border-amber-200 p-4">
                        <div class="flex gap-2">
                            <AlertCircle class="h-5 w-5 text-amber-600 shrink-0" />
                            <div class="text-sm text-amber-800">
                                <p class="font-medium">Contoh:</p>
                                <p class="mt-1" data-testid="contoh-denda">
                                    Karyawan terlambat <strong>2 hari</strong> dengan total <strong>30 menit</strong> dalam satu periode gaji.<br />
                                    <template v-if="form.late_penalty_method === 'per_minute'">
                                        Potongan = 30 menit × {{ rupiah(form.late_penalty_per_minute) }} = <strong>{{ rupiah(contohDenda) }}</strong>
                                    </template>
                                    <template v-else-if="form.late_penalty_method === 'none'">Potongan = <strong>Rp 0</strong> (tanpa denda)</template>
                                    <template v-else>
                                        Potongan = 2 hari × {{ rupiah(form.late_penalty_per_day) }} = <strong>{{ rupiah(contohDenda) }}</strong>
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Potongan alpa -->
                    <div class="space-y-2 rounded-xl border border-slate-200 p-4">
                        <div class="flex items-center gap-3">
                            <input
                                v-model="form.absence_deduction_enabled"
                                type="checkbox"
                                id="absence-deduction"
                                class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <Label for="absence-deduction" class="font-medium">Potong gaji untuk hari alpa & cuti tanpa gaji</Label>
                        </div>
                        <p class="text-xs text-slate-500">
                            Upah sehari = total komponen gaji tetap ÷ 21 (jadwal 5 hari kerja) atau ÷ 25 (6 hari kerja).
                            Potongan tidak melebihi gaji tetap periode itu.
                        </p>
                    </div>

                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
                    </Button>
                </CardContent>
            </Card>

            <!-- Tab: Lembur -->
            <Card v-if="activeTab === 'overtime'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Tarif Upah Lembur</CardTitle>
                    <CardDescription>
                        Pengali upah lembur terhadap upah per jam. Contoh: 1,5 berarti lembur dibayar satu setengah kali upah per jam.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label>Jam Pertama (×)</Label>
                            <Input v-model="form.overtime_rate_first_hour" type="number" step="0.01" min="0" placeholder="1.50" />
                            <p class="text-xs text-slate-500">1.5 = 150% upah/jam</p>
                            <p v-if="form.errors.overtime_rate_first_hour" class="text-sm text-red-600">{{ form.errors.overtime_rate_first_hour }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jam Berikutnya (×)</Label>
                            <Input v-model="form.overtime_rate_next_hour" type="number" step="0.01" min="0" placeholder="2.00" />
                            <p class="text-xs text-slate-500">2.0 = 200% upah/jam</p>
                            <p v-if="form.errors.overtime_rate_next_hour" class="text-sm text-red-600">{{ form.errors.overtime_rate_next_hour }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Hari Libur (×)</Label>
                            <Input v-model="form.overtime_rate_holiday" type="number" step="0.01" min="0" placeholder="2.00" />
                            <p class="text-xs text-slate-500">2.0 = 200% upah/jam</p>
                            <p v-if="form.errors.overtime_rate_holiday" class="text-sm text-red-600">{{ form.errors.overtime_rate_holiday }}</p>
                        </div>
                    </div>

                    <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                        <div class="flex gap-2">
                            <AlertCircle class="h-5 w-5 text-blue-600 shrink-0" />
                            <div class="text-sm text-blue-800">
                                <p class="font-medium">Catatan:</p>
                                <p class="mt-1">
                                    Lembur dihitung per hari: jam pertama memakai tarif <strong>Jam Pertama</strong>, jam ke-2 dst
                                    memakai tarif <strong>Jam Berikutnya</strong>. Lembur pada tanggal yang terdaftar sebagai
                                    hari libur di Pengaturan Absensi memakai tarif <strong>Hari Libur</strong> untuk semua jam.
                                    Upah per jam = 1/173 × total komponen gaji tetap.
                                </p>
                            </div>
                        </div>
                    </div>

                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
                    </Button>
                </CardContent>
            </Card>

            <!-- Tab: BPJS -->
            <Card v-if="activeTab === 'bpjs'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>BPJS Kesehatan & Ketenagakerjaan</CardTitle>
                    <CardDescription>
                        Persentase iuran BPJS. Potongan karyawan dihitung otomatis dari total komponen gaji tetap (gaji pokok dan tunjangan tetap).
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <!-- Toggle BPJS -->
                    <div class="flex items-center gap-3">
                        <input
                            v-model="form.bpjs_enabled"
                            type="checkbox"
                            id="bpjs-enabled"
                            class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <Label for="bpjs-enabled" class="font-medium">Aktifkan perhitungan BPJS</Label>
                    </div>

                    <div v-if="form.bpjs_enabled" class="space-y-6">
                        <!-- BPJS Kesehatan -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-slate-700">BPJS Kesehatan</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label>Karyawan (%)</Label>
                                    <Input v-model="form.bpjs_health_employee" type="number" step="0.01" min="0" max="100" />
                                </div>
                                <div class="space-y-1">
                                    <Label>Perusahaan (%)</Label>
                                    <Input v-model="form.bpjs_health_employer" type="number" step="0.01" min="0" max="100" />
                                </div>
                            </div>
                        </div>

                        <!-- JHT -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-slate-700">Jaminan Hari Tua (JHT)</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label>Karyawan (%)</Label>
                                    <Input v-model="form.bpjs_jht_employee" type="number" step="0.01" min="0" max="100" />
                                </div>
                                <div class="space-y-1">
                                    <Label>Perusahaan (%)</Label>
                                    <Input v-model="form.bpjs_jht_employer" type="number" step="0.01" min="0" max="100" />
                                </div>
                            </div>
                        </div>

                        <!-- JP -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-slate-700">Jaminan Pensiun (JP)</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label>Karyawan (%)</Label>
                                    <Input v-model="form.bpjs_jp_employee" type="number" step="0.01" min="0" max="100" />
                                </div>
                                <div class="space-y-1">
                                    <Label>Perusahaan (%)</Label>
                                    <Input v-model="form.bpjs_jp_employer" type="number" step="0.01" min="0" max="100" />
                                </div>
                            </div>
                        </div>

                        <!-- Batas upah -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-slate-700">Batas Upah Iuran</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label>Batas upah BPJS Kesehatan (Rp)</Label>
                                    <RupiahInput v-model="form.bpjs_health_wage_cap" />
                                    <p class="text-xs text-slate-500">Saat ini Rp 12.000.000 (Perpres 64/2020).</p>
                                    <p v-if="form.errors.bpjs_health_wage_cap" class="text-sm text-red-600">{{ form.errors.bpjs_health_wage_cap }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label>Batas upah Jaminan Pensiun (Rp)</Label>
                                    <RupiahInput v-model="form.bpjs_jp_wage_cap" />
                                    <p class="text-xs text-slate-500">Diumumkan BPJS Ketenagakerjaan tiap Maret — perbarui setiap tahun.</p>
                                    <p v-if="form.errors.bpjs_jp_wage_cap" class="text-sm text-red-600">{{ form.errors.bpjs_jp_wage_cap }}</p>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500">JHT, JKK, dan JKM dihitung dari seluruh upah tetap tanpa batas.</p>
                        </div>

                        <!-- JKK & JKM -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-slate-700">JKK & JKM (Tanggung Perusahaan)</h4>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label>JKK (%)</Label>
                                    <Input v-model="form.bpjs_jkk" type="number" step="0.01" min="0" max="100" />
                                    <p class="text-xs text-slate-500">Jaminan Kecelakaan Kerja</p>
                                </div>
                                <div class="space-y-1">
                                    <Label>JKM (%)</Label>
                                    <Input v-model="form.bpjs_jkm" type="number" step="0.01" min="0" max="100" />
                                    <p class="text-xs text-slate-500">Jaminan Kematian</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4">
                            <div class="flex gap-2">
                                <AlertCircle class="h-5 w-5 text-emerald-600 shrink-0" />
                                <div class="text-sm text-emerald-800">
                                    <p class="font-medium">Standar BPJS 2024:</p>
                                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                                        <li>Kesehatan: 1% karyawan, 4% perusahaan</li>
                                        <li>JHT: 2% karyawan, 3.7% perusahaan</li>
                                        <li>JP: 1% karyawan, 2% perusahaan</li>
                                        <li>JKK: 0.24% - 1.74% (tergantung risiko)</li>
                                        <li>JKM: 0.3% perusahaan</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
                    </Button>
                </CardContent>
            </Card>

            <!-- Tab: Persetujuan gaji -->
            <Card v-if="activeTab === 'approval'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Persetujuan Gaji</CardTitle>
                    <CardDescription>
                        Pisahkan yang menghitung dan yang menyetujui gaji. Admin atau manajer menghitung dan memeriksa,
                        lalu pemilik yang menyetujui sebelum gaji dibayar dan slip terbuka untuk karyawan.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input v-model="form.owner_approval" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <span>
                            <span class="block font-medium text-slate-800">Gaji hanya bisa disetujui oleh pemilik (owner)</span>
                            <span class="block text-sm text-slate-500">Admin dan manajer tetap bisa menghitung ulang selama gaji masih berstatus draf.</span>
                        </span>
                    </label>
                    <p v-if="form.owner_approval && !has_owner" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        Toko ini belum punya akun pemilik (owner), jadi aturan ini belum berlaku: admin tetap bisa menyetujui.
                        Tambahkan pemilik di menu Tim & Akses.
                    </p>
                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
                    </Button>
                </CardContent>
            </Card>

            <!-- Tab: PPh 21 -->
            <Card v-if="activeTab === 'tax'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>PPh 21 (Pajak Penghasilan)</CardTitle>
                    <CardDescription>
                        Perhitungan PPh 21 dengan tarif progresif sesuai peraturan pajak Indonesia 2024.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <!-- Toggle PPh 21 -->
                    <div class="flex items-center gap-3">
                        <input
                            v-model="form.pph21_enabled"
                            type="checkbox"
                            id="pph21-enabled"
                            class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <Label for="pph21-enabled" class="font-medium">Aktifkan perhitungan PPh 21</Label>
                    </div>

                    <div v-if="form.pph21_enabled" class="space-y-6">
                        <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                            <div class="flex gap-2">
                                <AlertCircle class="h-5 w-5 text-blue-600 shrink-0" />
                                <div class="text-sm text-blue-800">
                                    <p class="font-medium">Tarif PPh 21 Progresif (Per Tahun):</p>
                                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                                        <li>Penghasilan 0 - 60 juta: <strong>5%</strong></li>
                                        <li>Penghasilan 60 - 250 juta: <strong>15%</strong></li>
                                        <li>Penghasilan 250 - 500 juta: <strong>25%</strong></li>
                                        <li>Penghasilan 500 juta - 5 miliar: <strong>30%</strong></li>
                                        <li>Penghasilan &gt; 5 miliar: <strong>35%</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4">
                            <div class="flex gap-2">
                                <AlertCircle class="h-5 w-5 text-emerald-600 shrink-0" />
                                <div class="text-sm text-emerald-800">
                                    <p class="font-medium">PTKP (Penghasilan Tidak Kena Pajak) per Tahun:</p>
                                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                                        <li>TK/0 (Tidak Kawin, 0 tanggungan): Rp 54.000.000</li>
                                        <li>TK/1 (Tidak Kawin, 1 tanggungan): Rp 58.500.000</li>
                                        <li>K/0 (Kawin, 0 tanggungan): Rp 58.500.000</li>
                                        <li>K/1 (Kawin, 1 tanggungan): Rp 63.000.000</li>
                                        <li>K/2 (Kawin, 2 tanggungan): Rp 67.500.000</li>
                                        <li>K/3 (Kawin, 3 tanggungan): Rp 72.000.000</li>
                                    </ul>
                                    <p class="mt-2 text-xs">
                                        Status PTKP diatur per karyawan di halaman Komponen Gaji.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                        <Save class="h-4 w-4 mr-1" /> Simpan Pengaturan
                    </Button>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
