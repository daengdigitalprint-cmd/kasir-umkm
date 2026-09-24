<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    AlertTriangle,
    Bot,
    CheckCircle2,
    Eye,
    EyeOff,
    Loader2,
    MessageCircle,
    Plus,
    Save,
    Settings2,
    TestTube2,
    Trash2,
    Wifi,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDialog, toastSuccess, toastError } from '@/lib/swal';

interface AiConfig {
    id: number;
    store_id: number | null;
    provider: string;
    base_url: string | null;
    model_id: string | null;
    api_key?: string;
    max_tokens: number;
    temperature: number;
    is_active: boolean;
    store?: { id: number; name: string } | null;
}

interface Props {
    configs: AiConfig[];
    defaultConfig: Record<string, unknown>;
}

const props = defineProps<Props>();

// Form state
const showForm = ref(false);
const editingId = ref<number | null>(null);
const saving = ref(false);
const testing = ref(false);
const testResult = ref<{ success: boolean; message: string; models?: string[] } | null>(null);

// Form fields
const form = ref({
    provider: 'openai',
    base_url: '',
    model_id: '',
    api_key: '',
    max_tokens: 4096,
    temperature: 0.7,
    is_active: true,
});

const showKey = ref(false);

const providerOptions = [
    { value: 'openai', label: 'OpenAI' },
    { value: 'anthropic', label: 'Anthropic' },
    { value: 'custom', label: 'Custom (OpenAI Compatible)' },
];

const isEditing = computed(() => editingId.value !== null);

function resetForm() {
    form.value = {
        provider: 'openai',
        base_url: '',
        model_id: '',
        api_key: '',
        max_tokens: 4096,
        temperature: 0.7,
        is_active: true,
    };
    editingId.value = null;
    showKey.value = false;
    testResult.value = null;
}

function openCreateForm() {
    resetForm();
    showForm.value = true;
}

function openEditForm(config: AiConfig) {
    editingId.value = config.id;
    form.value = {
        provider: config.provider,
        base_url: config.base_url || '',
        model_id: config.model_id || '',
        api_key: '',
        max_tokens: config.max_tokens,
        temperature: config.temperature,
        is_active: config.is_active,
    };
    showKey.value = false;
    testResult.value = null;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    resetForm();
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

async function saveConfig() {
    saving.value = true;
    testResult.value = null;

    try {
        const url = isEditing.value
            ? `/ai/settings/${editingId.value}`
            : '/ai/settings';
        const method = isEditing.value ? 'PUT' : 'POST';

        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.value),
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            toastError(data.message || 'Gagal menyimpan konfigurasi AI');
            return;
        }

        toastSuccess(data.message || 'Konfigurasi AI berhasil disimpan');
        closeForm();
        router.reload({ only: ['configs'] });
    } catch {
        toastError('Koneksi ke server terputus');
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    testing.value = true;
    testResult.value = null;

    try {
        const res = await fetch('/ai/settings/test-connection', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                base_url: form.value.base_url,
                model_id: form.value.model_id,
                api_key: form.value.api_key,
            }),
        });

        const data = await res.json().catch(() => ({}));
        testResult.value = {
            success: data.success === true,
            message: data.message || (data.success ? 'Koneksi berhasil!' : 'Gagal terhubung'),
            models: data.models,
        };
    } catch {
        testResult.value = { success: false, message: 'Koneksi ke server terputus' };
    } finally {
        testing.value = false;
    }
}

async function deleteConfig(config: AiConfig) {
    const ok = await confirmDialog(
        'Hapus Konfigurasi AI?',
        `Konfigurasi ${config.provider} akan dihapus permanen.`,
    );
    if (!ok) return;

    try {
        const res = await fetch(`/ai/settings/${config.id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            toastError(data.message || 'Gagal menghapus konfigurasi');
            return;
        }

        toastSuccess('Konfigurasi AI berhasil dihapus');
        router.reload({ only: ['configs'] });
    } catch {
        toastError('Koneksi ke server terputus');
    }
}

function maskedKey(key?: string): string {
    if (!key) return '***';
    if (key.length <= 8) return '***';
    return key.slice(0, 4) + '...' + key.slice(-4);
}
</script>

<template>
    <AdminLayout title="Pengaturan AI Assistant">
        <div class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Pengaturan AI Assistant</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Konfigurasi AI provider untuk asisten di POS
                    </p>
                </div>
                <Button
                    v-if="!showForm"
                    @click="openCreateForm"
                    class="gap-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700"
                >
                    <Plus class="h-4 w-4" />
                    Tambah Konfigurasi
                </Button>
            </div>

            <!-- Info Card -->
            <Card class="border-blue-200 bg-blue-50">
                <CardContent class="flex items-start gap-3 p-4">
                    <MessageCircle class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                    <div class="text-sm text-blue-800">
                        <p class="font-bold">Tentang AI Assistant</p>
                        <p class="mt-1">
                            AI Assistant membantu kasir mengelola produk, cek stok, dan melihat laporan
                            langsung dari halaman POS. Konfigurasi di sini menentukan AI provider yang
                            digunakan. Mendukung format OpenAI Chat Completion API (compatible dengan
                            OpenAI, Anthropic, local LLM, dll).
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Form Card -->
            <Card v-if="showForm" class="border-0 shadow-lg">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <Settings2 class="h-5 w-5 text-slate-600" />
                            {{ isEditing ? 'Edit Konfigurasi' : 'Tambah Konfigurasi Baru' }}
                        </CardTitle>
                        <button
                            @click="closeForm"
                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <CardDescription>
                        Isi detail konfigurasi AI provider. API Key akan di-encrypt sebelum disimpan.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Provider -->
                    <div class="space-y-1.5">
                        <Label>Provider <span class="text-red-500">*</span></Label>
                        <select
                            v-model="form.provider"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option v-for="p in providerOptions" :key="p.value" :value="p.value">
                                {{ p.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Base URL (for custom) -->
                    <div v-if="form.provider === 'custom'" class="space-y-1.5">
                        <Label>Base URL <span class="text-red-500">*</span></Label>
                        <Input
                            v-model="form.base_url"
                            placeholder="https://api.example.com/v1"
                        />
                        <p class="text-xs text-slate-500">
                            URL endpoint yang compatible dengan OpenAI Chat Completion API
                        </p>
                    </div>

                    <!-- Model ID -->
                    <div class="space-y-1.5">
                        <Label>Model ID</Label>
                        <Input
                            v-model="form.model_id"
                            :placeholder="form.provider === 'openai' ? 'gpt-4o-mini' : form.provider === 'anthropic' ? 'claude-3-5-haiku-20241022' : 'nama-model'"
                        />
                        <p class="text-xs text-slate-500">
                            Kosongkan untuk menggunakan default provider
                        </p>
                    </div>

                    <!-- API Key -->
                    <div class="space-y-1.5">
                        <Label>API Key {{ isEditing ? '' : '*' }}</Label>
                        <div class="relative">
                            <Input
                                v-model="form.api_key"
                                :type="showKey ? 'text' : 'password'"
                                :placeholder="isEditing ? 'Kosongkan jika tidak ingin mengganti' : 'Masukkan API Key'"
                                class="pr-10"
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
                    </div>

                    <!-- Max Tokens & Temperature -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <Label>Max Tokens</Label>
                            <Input
                                v-model.number="form.max_tokens"
                                type="number"
                                min="100"
                                max="128000"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label>Temperature</Label>
                            <Input
                                v-model.number="form.temperature"
                                type="number"
                                min="0"
                                max="2"
                                step="0.1"
                            />
                        </div>
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center gap-3">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <Label for="is_active" class="cursor-pointer">
                            Aktifkan konfigurasi ini
                        </Label>
                    </div>

                    <!-- Test Result -->
                    <div
                        v-if="testResult"
                        :class="[
                            'flex items-start gap-3 rounded-2xl p-4',
                            testResult.success ? 'border border-emerald-200 bg-emerald-50' : 'border border-red-200 bg-red-50',
                        ]"
                    >
                        <CheckCircle2 v-if="testResult.success" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                        <AlertTriangle v-else class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                        <div>
                            <p :class="['text-sm font-medium', testResult.success ? 'text-emerald-800' : 'text-red-800']">
                                {{ testResult.message }}
                            </p>
                            <p v-if="testResult.models?.length" class="mt-1 text-xs text-emerald-700">
                                Model tersedia: {{ testResult.models.join(', ') }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                        <Button
                            @click="testConnection"
                            :disabled="testing || (!form.base_url && form.provider === 'custom')"
                            variant="outline"
                            class="flex-1 gap-2 rounded-xl border-slate-200 font-bold"
                        >
                            <Loader2 v-if="testing" class="h-4 w-4 animate-spin" />
                            <TestTube2 v-else class="h-4 w-4" />
                            {{ testing ? 'Menguji...' : 'Test Koneksi' }}
                        </Button>
                        <Button
                            @click="saveConfig"
                            :disabled="saving"
                            class="flex-1 gap-2 rounded-xl bg-blue-600 font-bold text-white hover:bg-blue-700"
                        >
                            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                            <Save v-else class="h-4 w-4" />
                            {{ saving ? 'Menyimpan...' : 'Simpan' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Existing Configs -->
            <div v-if="props.configs.length > 0" class="space-y-4">
                <h2 class="text-lg font-semibold text-slate-900">Konfigurasi Tersimpan</h2>

                <div
                    v-for="config in props.configs"
                    :key="config.id"
                    class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex items-center gap-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-100">
                            <Bot class="h-5 w-5 text-blue-600" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="font-semibold text-slate-900">
                                    {{ providerOptions.find(p => p.value === config.provider)?.label || config.provider }}
                                </p>
                                <Badge v-if="config.is_active" class="bg-emerald-100 text-emerald-800">Aktif</Badge>
                                <Badge v-else class="bg-slate-100 text-slate-600">Nonaktif</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Model: {{ config.model_id || 'default' }} |
                                Key: {{ maskedKey(config.api_key) }} |
                                Tokens: {{ config.max_tokens }} |
                                Temp: {{ config.temperature }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            @click="openEditForm(config)"
                            variant="outline"
                            size="sm"
                            class="rounded-lg"
                        >
                            Edit
                        </Button>
                        <Button
                            @click="deleteConfig(config)"
                            variant="outline"
                            size="sm"
                            class="rounded-lg border-red-200 text-red-600 hover:bg-red-50"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <Card v-else-if="!showForm" class="border-0 shadow-lg">
                <CardContent class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="rounded-3xl bg-slate-100 p-4">
                        <Settings2 class="h-10 w-10 text-slate-400" />
                    </div>
                    <p class="mt-6 text-lg font-bold text-slate-900">Belum ada konfigurasi AI</p>
                    <p class="mt-1 max-w-md text-sm text-slate-500">
                        Tambahkan konfigurasi AI provider untuk mengaktifkan AI Assistant di halaman POS.
                    </p>
                    <Button
                        @click="openCreateForm"
                        class="mt-4 gap-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700"
                    >
                        <Plus class="h-4 w-4" />
                        Tambah Konfigurasi
                    </Button>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
