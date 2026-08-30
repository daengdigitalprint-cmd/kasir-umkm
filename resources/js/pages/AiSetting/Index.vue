<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { AlertTriangle, CheckCircle2, Eye, EyeOff, KeyRound, Loader2, LogOut, Save, Settings2, Shield, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toastSuccess } from '@/lib/swal';

import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';

interface ProviderOption {
    value: string;
    label: string;
}

interface Props {
    providers: ProviderOption[];
    activeProvider: string;
    maskedKey: string | null;
    hasApiKey: boolean;
    source: 'database' | 'env';
    user: { name: string };
}

const props = defineProps<Props>();

const provider = ref<string>(props.activeProvider);
const apiKey = ref<string>('');
const showKey = ref(false);
const saving = ref(false);
const deleting = ref(false);
const errorMessage = ref<string>('');

const isDirty = computed(() => provider.value !== props.activeProvider || apiKey.value.trim() !== '');

const canSave = computed(() => Boolean(provider.value) && (apiKey.value.trim() !== '' || props.hasApiKey || isDirty.value) && !saving.value);

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

async function save() {
    if (!canSave.value) return;

    saving.value = true;
    errorMessage.value = '';

    try {
        const res = await fetch('/superadmin/ai-settings', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                provider: provider.value,
                api_key: apiKey.value.trim() || null,
            }),
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            errorMessage.value = data.message || 'Gagal menyimpan pengaturan AI.';
            return;
        }

        toastSuccess('Berhasil', data.message || 'Pengaturan AI berhasil disimpan.');
        apiKey.value = '';

        router.reload({ only: [], preserveState: true });
    } catch {
        errorMessage.value = 'Koneksi ke server terputus. Periksa jaringan Anda lalu coba lagi.';
    } finally {
        saving.value = false;
    }
}

async function removeKey() {
    deleting.value = true;
    errorMessage.value = '';

    try {
        const res = await fetch('/superadmin/ai-settings', {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            errorMessage.value = data.message || 'Gagal menghapus API Key.';
            return;
        }

        toastSuccess('Berhasil', data.message || 'API Key berhasil dihapus.');
        router.reload({ only: [], preserveState: true });
    } catch {
        errorMessage.value = 'Koneksi ke server terputus. Periksa jaringan Anda lalu coba lagi.';
    } finally {
        deleting.value = false;
    }
}

</script>

<template>
    <SuperAdminLayout title="Pengaturan AI" active-tab="ai-settings">
        <div class="max-w-3xl mx-auto space-y-6">
            <!-- Info kartu konfigurasi aktif -->
            <Card class="border-0 shadow-lg rounded-3xl">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <Settings2 class="h-5 w-5 text-slate-600" />
                        Konfigurasi Aktif
                    </CardTitle>
                    <CardDescription>
                        AI Auditor memakai konfigurasi berikut saat menjalankan analisa.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-center justify-between border border-slate-200 rounded-2xl px-4 py-3">
                        <span class="text-sm font-bold text-slate-500">Provider</span>
                        <Badge class="bg-slate-900 text-white">{{ props.activeProvider }}</Badge>
                    </div>
                    <div class="flex items-center justify-between border border-slate-200 rounded-2xl px-4 py-3">
                        <span class="text-sm font-bold text-slate-500">API Key</span>
                        <div class="flex items-center gap-2">
                            <code v-if="props.maskedKey" class="text-xs bg-slate-100 rounded-lg px-2 py-1 font-mono">{{ props.maskedKey }}</code>
                            <Badge v-else class="bg-amber-100 text-amber-800">Belum ada</Badge>
                        </div>
                    </div>
                    <div class="flex items-center justify-between border border-slate-200 rounded-2xl px-4 py-3">
                        <span class="text-sm font-bold text-slate-500">Sumber Konfigurasi</span>
                        <Badge :class="props.source === 'database' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'">
                            {{ props.source === 'database' ? 'Pengaturan (Database)' : 'File .env' }}
                        </Badge>
                    </div>
                    <div v-if="!props.hasApiKey" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-2xl p-4">
                        <AlertTriangle class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" />
                        <p class="text-sm text-amber-800">
                            API Key belum dikonfigurasi. Isi form di bawah untuk mulai menggunakan AI Auditor.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Form Pengaturan -->
            <Card class="border-0 shadow-lg rounded-3xl">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <KeyRound class="h-5 w-5 text-slate-600" />
                        Ubah Konfigurasi
                    </CardTitle>
                    <CardDescription>
                        Pilih provider AI dan masukkan API Key-nya. Key disimpan di database aplikasi
                        sehingga bisa diganti kapan saja tanpa mengedit file .env.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="provider">Provider AI <span class="text-red-500">*</span></Label>
                        <select
                            id="provider"
                            v-model="provider"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option v-for="p in props.providers" :key="p.value" :value="p.value">
                                {{ p.label }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="api_key">API Key Baru</Label>
                        <div class="relative">
                            <Input
                                id="api_key"
                                v-model="apiKey"
                                :type="showKey ? 'text' : 'password'"
                                placeholder="Kosongkan jika tidak ingin mengganti key saat ini"
                                class="pr-10"
                                autocomplete="off"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-600"
                                @click="showKey = !showKey"
                            >
                                <Eye v-if="!showKey" class="h-4 w-4" />
                                <EyeOff v-else class="h-4 w-4" />
                            </button>
                        </div>
                        <p class="text-xs text-slate-500">
                            Biarkan kosong untuk mempertahankan key yang tersimpan saat ini.
                        </p>
                    </div>

                    <div v-if="errorMessage" class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-2xl p-4">
                        <AlertTriangle class="h-5 w-5 text-red-600 mt-0.5 shrink-0" />
                        <p class="text-sm text-red-800">{{ errorMessage }}</p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <Button
                            @click="save"
                            :disabled="!canSave"
                            class="flex-1 rounded-xl bg-slate-900 text-white hover:bg-slate-800 font-bold"
                        >
                            <Loader2 v-if="saving" class="h-4 w-4 mr-2 animate-spin" />
                            <Save v-else class="h-4 w-4 mr-2" />
                            {{ saving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
                        </Button>
                        <Button
                            v-if="props.source === 'database'"
                            @click="removeKey"
                            :disabled="deleting"
                            variant="outline"
                            class="flex-1 rounded-xl border-red-200 text-red-700 hover:bg-red-50 font-bold"
                        >
                            <Loader2 v-if="deleting" class="h-4 w-4 mr-2 animate-spin" />
                            <Trash2 v-else class="h-4 w-4 mr-2" />
                            {{ deleting ? 'Menghapus...' : 'Hapus Key Tersimpan' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Kartu bantuan -->
            <Card class="border-0 shadow-lg rounded-3xl">
                <CardContent class="flex items-start gap-3 p-5">
                    <CheckCircle2 class="h-5 w-5 text-slate-600 mt-0.5 shrink-0" />
                    <div class="text-sm text-slate-600 space-y-1">
                        <p class="font-bold text-slate-800">Catatan keamanan:</p>
                        <p>API Key tidak pernah ditampilkan penuh setelah disimpan (hanya sebagian untuk verifikasi).</p>
                        <p>Halaman ini hanya bisa diakses oleh Super Admin.</p>
                        <p>Bila key di database dihapus, sistem otomatis memakai key dari file .env bila tersedia.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </SuperAdminLayout>
</template>
