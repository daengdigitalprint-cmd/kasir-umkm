<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Save, AlertCircle, Clock, Timer, Shield, ShieldCheck, Receipt } from 'lucide-vue-next';

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

const parseAmount = (value: string) => value.replace(/\D/g, '');

const onAmountInput = (field: string, e: Event) => {
    const input = e.target as HTMLInputElement;
    (form as any)[field] = parseAmount(input.value);
};

const onAmountBlur = (field: string, e: Event) => {
    const input = e.target as HTMLInputElement;
    input.value = formatAmount((form as any)[field]);
};

const submit = () => {
    form.post('/payroll/settings', {
        method: 'put',
        preserveScroll: true,
    });
};

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
                </button>
            </div>

            <!-- Tab: Keterlambatan -->
            <Card v-if="activeTab === 'late'" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Potongan Keterlambatan</CardTitle>
                    <CardDescription>
                        Konfigurasi denda otomatis untuk karyawan yang terlambat berdasarkan data absensi.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="space-y-1">
                        <Label>Metode Denda</Label>
                        <select
                            v-model="form.late_penalty_method"
                            class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="per_day">Per hari telat</option>
                            <option value="per_minute">Per menit telat</option>
                            <option value="none">Tanpa denda</option>
                        </select>
                        <p class="text-xs text-slate-500">Hanya satu metode denda yang diterapkan.</p>
                        <p v-if="form.errors.late_penalty_method" class="text-sm text-red-600">{{ form.errors.late_penalty_method }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label>Denda per Hari Telat</Label>
                            <Input
                                type="text"
                                inputmode="numeric"
                                :value="formatAmount(form.late_penalty_per_day)"
                                @input="onAmountInput('late_penalty_per_day', $event)"
                                @blur="onAmountBlur('late_penalty_per_day', $event)"
                                placeholder="10.000"
                            />
                            <p class="text-xs text-slate-500">Denda untuk setiap hari keterlambatan</p>
                            <p v-if="form.errors.late_penalty_per_day" class="text-sm text-red-600">{{ form.errors.late_penalty_per_day }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Denda per Menit Telat</Label>
                            <Input
                                type="text"
                                inputmode="numeric"
                                :value="formatAmount(form.late_penalty_per_minute)"
                                @input="onAmountInput('late_penalty_per_minute', $event)"
                                @blur="onAmountBlur('late_penalty_per_minute', $event)"
                                placeholder="100"
                            />
                            <p class="text-xs text-slate-500">Denda untuk setiap menit keterlambatan</p>
                            <p v-if="form.errors.late_penalty_per_minute" class="text-sm text-red-600">{{ form.errors.late_penalty_per_minute }}</p>
                        </div>
                    </div>

                    <div class="rounded-lg bg-amber-50 border border-amber-200 p-4">
                        <div class="flex gap-2">
                            <AlertCircle class="h-5 w-5 text-amber-600 shrink-0" />
                            <div class="text-sm text-amber-800">
                                <p class="font-medium">Contoh Perhitungan:</p>
                                <p class="mt-1">
                                    Jika karyawan terlambat <strong>2 hari</strong> dengan total <strong>30 menit</strong>:<br>
                                    <template v-if="form.late_penalty_method === 'per_minute'">
                                        Potongan = 30 × {{ formatAmount(form.late_penalty_per_minute) || '0' }}
                                    </template>
                                    <template v-else-if="form.late_penalty_method === 'none'">
                                        Potongan = 0 (tanpa denda)
                                    </template>
                                    <template v-else>
                                        Potongan = 2 × {{ formatAmount(form.late_penalty_per_day) || '0' }}
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
                        Multiplier upah lembur terhadap upah per jam dasar.
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
                        Konfigurasi persentase iuran BPJS. Potongan karyawan akan otomatis dihitung dari gaji pokok.
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
                                    <Input
                                        :model-value="formatAmount(form.bpjs_health_wage_cap)"
                                        inputmode="numeric"
                                        @input="onAmountInput('bpjs_health_wage_cap', $event)"
                                        @blur="onAmountBlur('bpjs_health_wage_cap', $event)"
                                    />
                                    <p class="text-xs text-slate-500">Saat ini Rp 12.000.000 (Perpres 64/2020).</p>
                                    <p v-if="form.errors.bpjs_health_wage_cap" class="text-sm text-red-600">{{ form.errors.bpjs_health_wage_cap }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label>Batas upah Jaminan Pensiun (Rp)</Label>
                                    <Input
                                        :model-value="formatAmount(form.bpjs_jp_wage_cap)"
                                        inputmode="numeric"
                                        @input="onAmountInput('bpjs_jp_wage_cap', $event)"
                                        @blur="onAmountBlur('bpjs_jp_wage_cap', $event)"
                                    />
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
