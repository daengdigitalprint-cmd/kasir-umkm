<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import PayrollNav from '@/components/PayrollNav.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pencil, Plus, Trash2, X } from 'lucide-vue-next';

interface ComponentItem {
    id: number;
    name: string;
    type: 'earning' | 'deduction';
    is_fixed: boolean;
    amount: number;
}

interface Employee {
    id: number;
    name: string;
    position: string | null;
    tax_status: string;
    dependents: number;
    components: ComponentItem[];
}

const props = defineProps<{
    employees: Employee[];
    ptkp_options: Record<string, string>;
}>();

const form = useForm({
    id: null as number | null,
    employee_id: '',
    name: '',
    type: 'earning' as 'earning' | 'deduction',
    is_fixed: true,
    amount: '' as string,
});

const editing = () => form.id !== null;

const formatAmount = (value: string | number) => {
    const num = String(value).replace(/\D/g, '');
    return num ? new Intl.NumberFormat('id-ID').format(Number(num)) : '';
};

const parseAmount = (value: string) => value.replace(/\D/g, '');

const onAmountInput = (e: Event) => {
    const input = e.target as HTMLInputElement;
    form.amount = parseAmount(input.value);
};

const onAmountBlur = (e: Event) => {
    const input = e.target as HTMLInputElement;
    input.value = formatAmount(form.amount);
};

const startEdit = (employeeId: number, item: ComponentItem) => {
    form.id = item.id;
    form.employee_id = String(employeeId);
    form.name = item.name;
    form.type = item.type;
    form.is_fixed = item.is_fixed;
    form.amount = String(item.amount);
};

const cancelEdit = () => form.reset();

const submit = () => {
    if (editing()) {
        form.put(`/payroll/salaries/${form.id}`, { preserveScroll: true, onSuccess: () => form.reset() });
    } else {
        form.post('/payroll/salaries', { preserveScroll: true, onSuccess: () => form.reset() });
    }
};

const remove = (id: number) => {
    if (confirm('Hapus komponen gaji ini?')) {
        router.delete(`/payroll/salaries/${id}`, { preserveScroll: true });
    }
};

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);

const earnings = (items: ComponentItem[]) => items.filter((i) => i.type === 'earning').reduce((s, i) => s + Number(i.amount), 0);
const deductions = (items: ComponentItem[]) => items.filter((i) => i.type === 'deduction').reduce((s, i) => s + Number(i.amount), 0);

const updateTaxStatus = (employee: Employee, taxStatus: string) => {
    router.put(`/payroll/salaries/${employee.id}/tax`, { tax_status: taxStatus }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Komponen Gaji" />

    <AdminLayout title="Komponen Gaji">
        <div class="space-y-6 pb-8">
            <PayrollNav />

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>{{ editing() ? 'Edit Komponen Gaji' : 'Tambah Komponen Gaji' }}</CardTitle>
                    <CardDescription>
                        Isi gaji tiap karyawan sebagai beberapa baris: misalnya <b>Gaji Pokok</b>, <b>Tunjangan Transport</b>
                        (penghasilan), atau <b>Cicilan Kasbon</b> (potongan). Nilai di sini dipakai setiap kali gaji dihitung.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div class="space-y-1">
                            <Label>Karyawan</Label>
                            <select v-model="form.employee_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="" disabled>Pilih karyawan</option>
                                <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                                    {{ employee.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.employee_id" class="text-sm text-red-600">{{ form.errors.employee_id }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Nama Komponen</Label>
                            <Input v-model="form.name" placeholder="Gaji Pokok" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label>Jenis</Label>
                            <select v-model="form.type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="earning">Penghasilan (menambah gaji)</option>
                                <option value="deduction">Potongan (mengurangi gaji)</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label>Nominal per bulan (Rp)</Label>
                            <Input
                                type="text"
                                inputmode="numeric"
                                v-model="form.amount"
                                @input="onAmountInput"
                                @blur="onAmountBlur"
                                placeholder="5.000.000"
                            />
                            <p v-if="form.errors.amount" class="text-sm text-red-600">{{ form.errors.amount }}</p>
                        </div>
                    </div>
                    <div v-if="form.type === 'earning'" class="mt-3 flex items-start gap-2">
                        <input
                            id="is-fixed"
                            v-model="form.is_fixed"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label for="is-fixed" class="text-sm text-slate-600">
                            <span class="font-medium text-slate-700">Gaji tetap</span> — gaji pokok & tunjangan tetap. Menjadi dasar BPJS,
                            upah lembur, prorata, dan potongan alpa. Kosongkan untuk tunjangan tidak tetap (mis. uang makan, insentif).
                        </label>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <Button type="button" class="rounded-xl" :disabled="form.processing" @click="submit">
                            <Pencil v-if="editing()" class="h-4 w-4 mr-1" />
                            <Plus v-else class="h-4 w-4 mr-1" />
                            {{ editing() ? 'Simpan Perubahan' : 'Tambah Komponen' }}
                        </Button>
                        <Button v-if="editing()" type="button" variant="outline" class="rounded-xl" @click="cancelEdit">
                            <X class="h-4 w-4 mr-1" /> Batal
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar Komponen per Karyawan</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-3">Karyawan</th>
                                <th class="py-2 pr-3" title="Status PTKP untuk PPh 21: TK = tidak kawin, K = kawin, angka = jumlah tanggungan">Status pajak (PTKP)</th>
                                <th class="py-2 pr-3">Komponen</th>
                                <th class="py-2 pr-3 text-right">Total penghasilan</th>
                                <th class="py-2 pr-3 text-right">Total potongan</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in employees" :key="employee.id" class="border-b border-slate-100 align-top">
                                <td class="py-2 pr-3">
                                    <p class="font-medium">{{ employee.name }}</p>
                                    <p class="text-xs text-slate-400">{{ employee.position ?? '—' }}</p>
                                </td>
                                <td class="py-2 pr-3">
                                    <select
                                        :value="employee.tax_status"
                                        @change="updateTaxStatus(employee, ($event.target as HTMLSelectElement).value)"
                                        class="rounded-lg border border-slate-300 px-2 py-1 text-xs"
                                    >
                                        <option v-for="(label, key) in ptkp_options" :key="key" :value="key">
                                            {{ key }}
                                        </option>
                                    </select>
                                </td>
                                <td class="py-2 pr-3">
                                    <div class="space-y-1">
                                        <p v-for="item in employee.components" :key="item.id" class="flex items-center gap-2">
                                            <span :class="item.type === 'deduction' ? 'text-red-600' : ''">
                                                {{ item.name }} ({{ fmt(Number(item.amount)) }})
                                            </span>
                                            <span
                                                v-if="item.type === 'earning' && !item.is_fixed"
                                                class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                                                >tidak tetap</span
                                            >
                                            <button type="button" class="text-slate-400 hover:text-slate-600" @click="startEdit(employee.id, item)">
                                                <Pencil class="h-3.5 w-3.5" />
                                            </button>
                                            <button type="button" class="text-slate-400 hover:text-red-600" @click="remove(item.id)">
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </button>
                                        </p>
                                        <p
                                            v-if="!employee.components.some((c) => c.type === 'earning')"
                                            class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700"
                                        >
                                            Belum ada gaji — karyawan ini tidak ikut dihitung.
                                            <button type="button" class="underline" @click="form.employee_id = String(employee.id); form.name = 'Gaji Pokok'">
                                                Isi sekarang
                                            </button>
                                        </p>
                                    </div>
                                </td>
                                <td class="py-2 pr-3 text-right font-medium tabular-nums">{{ fmt(earnings(employee.components)) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums" :class="deductions(employee.components) ? 'text-red-600' : 'text-slate-300'">
                                    {{ deductions(employee.components) ? fmt(deductions(employee.components)) : '—' }}
                                </td>
                                <td class="py-2"></td>
                            </tr>
                            <tr v-if="!employees.length">
                                <td colspan="6" class="py-6 text-center text-slate-400">Belum ada karyawan aktif.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
