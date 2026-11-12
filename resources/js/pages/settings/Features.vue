<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { toastError, toastSuccess } from '@/lib/swal';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Crown, Loader2, Sparkles } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface FeatureCard {
    key: string;
    label: string;
    description: string;
    active: boolean;
    in_use: boolean;
    needs_upgrade: boolean;
    toggleable: boolean;
}

interface FeatureGroup {
    key: string;
    label: string;
    features: FeatureCard[];
}

const props = defineProps<{
    groups: FeatureGroup[];
    uiMode: 'simple' | 'full';
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props as { flash?: { success?: string } }).flash?.success);
const flashError = computed(() => (page.props as { flash?: { error?: string } }).flash?.error);
watch(flashSuccess, (m) => {
    if (m) void toastSuccess(m);
});
watch(flashError, (m) => {
    if (m) void toastError(m);
});

const isSimple = computed(() => props.uiMode === 'simple');
const activeCount = computed(() => props.groups.reduce((n, g) => n + g.features.filter((f) => f.active).length, 0));
const totalCount = computed(() => props.groups.reduce((n, g) => n + g.features.length, 0));

const savingKey = ref<string | null>(null);

function toggle(feature: FeatureCard) {
    if (savingKey.value) return;
    savingKey.value = feature.key;
    router.put(`/settings/features/${feature.key}`, { enabled: !feature.active }, { preserveScroll: true, onFinish: () => (savingKey.value = null) });
}
</script>

<template>
    <AdminLayout title="Fitur Tambahan">
        <Head title="Fitur Tambahan" />

        <div class="mb-6">
            <h1 class="flex items-center gap-2 text-2xl font-bold text-gray-900">
                <Sparkles class="h-6 w-6 text-violet-600" />
                Fitur Tambahan
            </h1>
            <p class="mt-1 text-sm text-gray-600">
                Nyalakan fitur saat Anda membutuhkannya. Menunya langsung muncul di sidebar, dan data yang sudah ada tidak berubah.
            </p>
            <p class="mt-2 text-sm text-gray-500">{{ activeCount }} dari {{ totalCount }} fitur aktif</p>
        </div>

        <div v-if="!isSimple" class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            Toko ini memakai <strong>Mode Lengkap</strong>, jadi semua menu sudah tampil. Di sini Anda hanya bisa menyalakan atau mematikan modul yang
            punya saklar sendiri. Untuk tampilan yang lebih ringkas, ganti ke Mode Sederhana di
            <Link href="/settings/profile" class="font-semibold underline">Pengaturan</Link>.
        </div>

        <Card v-for="group in groups" :key="group.key" class="mb-6 border-0 shadow-lg">
            <CardHeader>
                <CardTitle>{{ group.label }}</CardTitle>
                <CardDescription class="sr-only">Daftar fitur {{ group.label }}</CardDescription>
            </CardHeader>
            <CardContent>
                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="feature in group.features"
                        :key="feature.key"
                        :class="[
                            'flex flex-col justify-between gap-3 rounded-xl border p-4',
                            feature.active ? 'border-green-200 bg-green-50/60' : 'border-gray-200 bg-white',
                        ]"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-900">{{ feature.label }}</span>
                                <span
                                    v-if="feature.active"
                                    class="inline-flex items-center gap-1 rounded-full bg-green-600 px-2 py-0.5 text-xs font-medium text-white"
                                >
                                    <Check class="h-3 w-3" /> Aktif
                                </span>
                                <span
                                    v-if="feature.needs_upgrade"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                                >
                                    <Crown class="h-3 w-3" /> Paket berbayar
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ feature.description }}</p>
                            <p v-if="feature.active && feature.in_use" class="mt-2 text-xs text-gray-500">
                                Masih ada data yang berjalan, jadi fitur ini belum bisa dimatikan.
                            </p>
                        </div>

                        <div class="flex justify-end">
                            <Button v-if="feature.needs_upgrade && !feature.active" as-child variant="outline" size="sm">
                                <Link href="/subscription">Lihat paket</Link>
                            </Button>
                            <span v-else-if="!feature.toggleable" class="text-xs text-gray-500">Selalu tampil di Mode Lengkap</span>
                            <Button
                                v-else
                                size="sm"
                                :variant="feature.active ? 'outline' : 'default'"
                                :disabled="savingKey !== null || (feature.active && feature.in_use)"
                                @click="toggle(feature)"
                            >
                                <Loader2 v-if="savingKey === feature.key" class="mr-1 h-4 w-4 animate-spin" />
                                {{ feature.active ? 'Matikan' : 'Aktifkan' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
