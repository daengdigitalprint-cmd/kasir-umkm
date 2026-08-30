<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';

import { KeyRound, Shield, Users, Check, X, Loader2 } from 'lucide-vue-next';
import { toastSuccess, toastWarning, toastError } from '@/lib/swal';

interface CashierRow {
    id: number;
    name: string;
    position: string | null;
    has_pin: boolean;
    can_login_pos: boolean;
    pin_set_at: string | null;
}

const props = defineProps<{
    cashier_login_mode: string;
    cashiers: CashierRow[];
}>();

const page = usePage();

const flashSuccess = computed(() => (page.props as { flash?: { success?: string } }).flash?.success);
const flashError = computed(() => (page.props as { flash?: { error?: string } }).flash?.error);

watch(flashSuccess, (m) => { if (m) void toastSuccess(m); });
watch(flashError, (m) => { if (m) void toastError(m); });

const isPinMode = computed(() => props.cashier_login_mode === 'pin');

const modeForm = useForm({
    cashier_login_mode: props.cashier_login_mode,
});

function toggleMode() {
    modeForm.cashier_login_mode = modeForm.cashier_login_mode === 'pin' ? 'email' : 'pin';
    modeForm.put('/settings/cashier-pin/mode', {
        preserveScroll: true,
    });
}

const pinDialogOpen = ref(false);
const selectedEmployee = ref<CashierRow | null>(null);
const pinForm = useForm({ pin: '' });

function openPinDialog(emp: CashierRow) {
    selectedEmployee.value = emp;
    pinForm.pin = '';
    pinForm.clearErrors();
    pinDialogOpen.value = true;
}

function closePinDialog() {
    pinDialogOpen.value = false;
    selectedEmployee.value = null;
}

function submitPin() {
    if (!selectedEmployee.value) return;
    pinForm.post(`/settings/cashier-pin/${selectedEmployee.value.id}/pin`, {
        preserveScroll: true,
        onSuccess: () => closePinDialog(),
    });
}

function clearPin(emp: CashierRow) {
    if (!confirm(`Hapus PIN untuk ${emp.name}?`)) return;
    useForm({}).delete(`/settings/cashier-pin/${emp.id}/pin`, {
        preserveScroll: true,
    });
}

function togglePos(emp: CashierRow) {
    useForm({}).put(`/settings/cashier-pin/${emp.id}/toggle-pos`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <AdminLayout title="Pengaturan Kasir">
        <Head title="Pengaturan Kasir" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <KeyRound class="h-7 w-7 text-primary" />
                Pengaturan Login Kasir
            </h1>
            <p class="text-sm text-slate-600 mt-1">Kelola mode login kasir dan PIN untuk shift.</p>
        </div>

        <div class="grid gap-6">
            <Card class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Shield class="h-5 w-5 text-primary" />
                        Mode Login Kasir
                    </CardTitle>
                    <CardDescription>Pilih metode login yang digunakan kasir di outlet ini.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">
                                {{ isPinMode ? 'PIN-Based Shift' : 'Email/Password' }}
                            </p>
                            <p class="text-sm text-slate-500 mt-1">
                                {{ isPinMode
                                    ? 'Kasir login dengan memilih nama dan memasukkan PIN. Tidak perlu logout untuk ganti shift.'
                                    : 'Login standar menggunakan email dan password seperti biasa.'
                                }}
                            </p>
                        </div>
                        <Button @click="toggleMode" :disabled="modeForm.processing" variant="outline" class="rounded-xl">
                            <Loader2 v-if="modeForm.processing" class="h-4 w-4 mr-2 animate-spin" />
                            {{ isPinMode ? 'Ubah ke Email' : 'Ubah ke PIN' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="isPinMode" class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Users class="h-5 w-5 text-primary" />
                        Daftar Kasir
                    </CardTitle>
                    <CardDescription>Kelola akses POS dan PIN untuk setiap anggota tim. Tambah/hapus anggota melalui halaman Manajemen Tim.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="cashiers.length === 0" class="text-center py-8">
                        <p class="text-slate-500">Belum ada anggota tim terdaftar.</p>
                        <p class="text-sm text-slate-400 mt-1">Tambahkan anggota melalui halaman Manajemen Tim.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="emp in cashiers"
                            :key="emp.id"
                            class="flex items-center justify-between p-4 rounded-xl border border-slate-200"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                    <span class="text-primary font-bold">{{ emp.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-900">{{ emp.name }}</p>
                                    <p class="text-xs text-slate-500">{{ emp.position ?? 'Tanpa posisi' }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <div class="flex flex-col items-end gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Akses POS</span>
                                        <button
                                            type="button"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                                            :class="emp.can_login_pos ? 'bg-primary' : 'bg-slate-300'"
                                            @click="togglePos(emp)"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform"
                                                :class="emp.can_login_pos ? 'translate-x-4.5' : 'translate-x-1'"
                                            />
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="emp.has_pin" variant="secondary" class="text-xs">
                                            <Check class="h-3 w-3 mr-1" /> PIN aktif
                                        </Badge>
                                        <Badge v-else variant="outline" class="text-xs">
                                            <X class="h-3 w-3 mr-1" /> Belum ada PIN
                                        </Badge>
                                    </div>
                                </div>

                                <div class="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="rounded-lg"
                                        @click="openPinDialog(emp)"
                                    >
                                        {{ emp.has_pin ? 'Ubah PIN' : 'Set PIN' }}
                                    </Button>
                                    <Button
                                        v-if="emp.has_pin"
                                        variant="ghost"
                                        size="sm"
                                        class="rounded-lg text-red-600 hover:text-red-700 hover:bg-red-50"
                                        @click="clearPin(emp)"
                                    >
                                        Hapus
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Teleport to="body">
            <div
                v-if="pinDialogOpen"
                class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
            >
                <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
                    <h3 class="text-lg font-bold text-slate-900">
                        {{ selectedEmployee?.has_pin ? 'Ubah PIN' : 'Atur PIN' }}
                    </h3>
                    <p class="text-sm text-slate-500 mt-1">
                        Untuk {{ selectedEmployee?.name }}
                    </p>

                    <form class="mt-4 space-y-4" @submit.prevent="submitPin">
                        <div class="space-y-2">
                            <Label for="pin">PIN (4-6 digit angka)</Label>
                            <Input
                                id="pin"
                                v-model="pinForm.pin"
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                pattern="[0-9]*"
                                class="rounded-xl text-center text-2xl tracking-widest"
                                placeholder="****"
                                required
                                autofocus
                            />
                            <p v-if="pinForm.errors.pin" class="text-sm text-red-600">{{ pinForm.errors.pin }}</p>
                            <p class="text-xs text-slate-400 mt-1">
                                Hindari pola mudah seperti 1234, 0000, 1111.
                            </p>
                        </div>

                        <div class="flex gap-3 justify-end">
                            <Button type="button" variant="outline" @click="closePinDialog">Batal</Button>
                            <Button type="submit" :disabled="pinForm.processing || pinForm.pin.length < 4">
                                <Loader2 v-if="pinForm.processing" class="h-4 w-4 mr-2 animate-spin" />
                                Simpan PIN
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
