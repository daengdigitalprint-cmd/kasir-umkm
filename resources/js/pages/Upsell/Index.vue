<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowRight, Plus, Sparkles, Trash2, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { confirmDelete } from '@/lib/swal';

interface ProductOpt {
    id: number;
    name: string;
    price: number;
}

interface RuleRow {
    id: number;
    trigger_product_id: number;
    trigger_name: string;
    suggested_product_id: number;
    suggested_name: string;
    is_active: boolean;
}

interface PairRow {
    trigger_product_id: number;
    trigger_name: string;
    suggested_product_id: number;
    suggested_name: string;
    together: number;
}

const props = defineProps<{
    enabled: boolean;
    autoEnabled: boolean;
    products: ProductOpt[];
    rules: RuleRow[];
    frequentPairs: PairRow[];
    minTogether: number;
    lookbackDays: number;
}>();

// ── Saklar ──
const savingSettings = ref(false);
function saveSettings(enabled: boolean, autoEnabled: boolean) {
    savingSettings.value = true;
    router.post(
        '/upsell-rules/settings',
        { enabled, auto_enabled: autoEnabled },
        { preserveScroll: true, onFinish: () => (savingSettings.value = false) },
    );
}

// ── Aturan per menu pemicu ──
const grouped = computed(() => {
    const map = new Map<number, { trigger_name: string; rules: RuleRow[] }>();
    for (const rule of props.rules) {
        if (!map.has(rule.trigger_product_id)) map.set(rule.trigger_product_id, { trigger_name: rule.trigger_name, rules: [] });
        map.get(rule.trigger_product_id)!.rules.push(rule);
    }
    return [...map.entries()].map(([id, g]) => ({ trigger_product_id: id, ...g }));
});

const form = useForm({
    trigger_product_id: null as number | null,
    suggested_product_ids: [] as number[],
});

const suggestionChoices = computed(() => props.products.filter((p) => p.id !== form.trigger_product_id));

function toggleSuggestion(id: number) {
    const list = form.suggested_product_ids;
    const i = list.indexOf(id);
    if (i >= 0) list.splice(i, 1);
    else if (list.length < 3) list.push(id);
}

function submit() {
    form.post('/upsell-rules', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function usePair(pair: PairRow) {
    form.trigger_product_id = pair.trigger_product_id;
    form.suggested_product_ids = [pair.suggested_product_id];
    document.getElementById('upsell-form')?.scrollIntoView({ behavior: 'smooth' });
}

function toggleRule(rule: RuleRow) {
    router.patch(`/upsell-rules/${rule.id}`, { is_active: !rule.is_active }, { preserveScroll: true });
}

async function removeRule(rule: RuleRow) {
    if (!(await confirmDelete(`Saran ${rule.suggested_name}`, `Tidak lagi ditawarkan saat ${rule.trigger_name} dipesan.`))) return;
    router.delete(`/upsell-rules/${rule.id}`, { preserveScroll: true });
}

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);
</script>

<template>
    <Head title="Saran Menu" />

    <AdminLayout title="Saran Menu">
        <div class="mx-auto max-w-5xl space-y-6 pb-8">
            <div>
                <h1 class="text-2xl font-black text-slate-900">Saran Tambahan Menu</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Saat menu tertentu dipesan, kasir dan menu Scan Meja menawarkan menu pasangannya. Contoh: Nasi Goreng → Es Teh.
                </p>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6">
                    <label class="flex items-start justify-between gap-4">
                        <span>
                            <span class="block font-semibold text-slate-900">Tampilkan saran menu</span>
                            <span class="block text-sm text-slate-500">Muncul di keranjang kasir dan di menu Scan Meja pelanggan.</span>
                        </span>
                        <input
                            type="checkbox"
                            class="mt-1 h-5 w-5 rounded"
                            :checked="enabled"
                            :disabled="savingSettings"
                            @change="saveSettings(($event.target as HTMLInputElement).checked, autoEnabled)"
                        />
                    </label>
                    <label class="flex items-start justify-between gap-4 border-t border-slate-100 pt-4">
                        <span>
                            <span class="block font-semibold text-slate-900">Tambahkan saran otomatis</span>
                            <span class="block text-sm text-slate-500">
                                Dari menu yang sering dibeli bersama ({{ lookbackDays }} hari terakhir, minimal {{ minTogether }} kali). Pasangan manual tetap didahulukan.
                            </span>
                        </span>
                        <input
                            type="checkbox"
                            class="mt-1 h-5 w-5 rounded"
                            :checked="autoEnabled"
                            :disabled="savingSettings"
                            @change="saveSettings(enabled, ($event.target as HTMLInputElement).checked)"
                        />
                    </label>
                </CardContent>
            </Card>

            <Card id="upsell-form" class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Tambah pasangan menu</CardTitle>
                    <CardDescription>Pilih menu pemicu, lalu sampai 3 menu yang ditawarkan.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="font-semibold text-slate-700">Saat pelanggan memesan</span>
                        <select v-model="form.trigger_product_id" class="h-10 w-full rounded-lg border border-slate-200 px-3" @change="form.suggested_product_ids = []">
                            <option :value="null" disabled>Pilih menu…</option>
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <span v-if="form.errors.trigger_product_id" class="text-xs text-red-600">{{ form.errors.trigger_product_id }}</span>
                    </label>

                    <div v-if="form.trigger_product_id" class="space-y-1.5 text-sm">
                        <span class="font-semibold text-slate-700">Tawarkan juga ({{ form.suggested_product_ids.length }}/3)</span>
                        <div class="flex max-h-56 flex-wrap gap-2 overflow-y-auto rounded-lg border border-slate-100 p-2">
                            <button
                                v-for="p in suggestionChoices"
                                :key="p.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="form.suggested_product_ids.includes(p.id) ? 'border-teal-600 bg-teal-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-teal-400'"
                                @click="toggleSuggestion(p.id)"
                            >
                                {{ p.name }}
                            </button>
                        </div>
                        <span v-if="form.errors.suggested_product_ids" class="text-xs text-red-600">{{ form.errors.suggested_product_ids }}</span>
                        <span v-for="(msg, key) in form.errors" :key="key">
                            <span v-if="String(key).startsWith('suggested_product_ids.')" class="block text-xs text-red-600">{{ msg }}</span>
                        </span>
                    </div>

                    <Button class="rounded-xl" :disabled="!form.trigger_product_id || form.suggested_product_ids.length === 0 || form.processing" @click="submit">
                        <Plus class="mr-1 h-4 w-4" /> Simpan pasangan
                    </Button>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><Sparkles class="h-5 w-5 text-amber-500" /> Pasangan manual</CardTitle>
                </CardHeader>
                <CardContent>
                    <p v-if="grouped.length === 0" class="py-6 text-center text-sm text-slate-500">Belum ada pasangan manual.</p>
                    <div v-else class="divide-y divide-slate-100">
                        <div v-for="g in grouped" :key="g.trigger_product_id" class="flex flex-wrap items-center gap-2 py-3">
                            <span class="font-semibold text-slate-900">{{ g.trigger_name }}</span>
                            <ArrowRight class="h-4 w-4 text-slate-400" />
                            <span
                                v-for="r in g.rules"
                                :key="r.id"
                                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="r.is_active ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-200 bg-slate-50 text-slate-400 line-through'"
                            >
                                <button type="button" :title="r.is_active ? 'Nonaktifkan' : 'Aktifkan'" @click="toggleRule(r)">{{ r.suggested_name }}</button>
                                <button type="button" class="text-red-500 hover:text-red-700" :aria-label="`Hapus ${r.suggested_name}`" @click="removeRule(r)">
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><TrendingUp class="h-5 w-5 text-violet-500" /> Sering dibeli bersama</CardTitle>
                    <CardDescription>Dari transaksi {{ lookbackDays }} hari terakhir. Klik untuk menjadikannya pasangan manual.</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="frequentPairs.length === 0" class="py-6 text-center text-sm text-slate-500">
                        Belum ada pasangan yang dibeli bersama minimal {{ minTogether }} kali.
                    </p>
                    <div v-else class="divide-y divide-slate-100">
                        <button
                            v-for="pair in frequentPairs"
                            :key="`${pair.trigger_product_id}-${pair.suggested_product_id}`"
                            type="button"
                            class="flex w-full items-center justify-between py-2.5 text-left text-sm hover:bg-slate-50"
                            @click="usePair(pair)"
                        >
                            <span>{{ pair.trigger_name }} + {{ pair.suggested_name }}</span>
                            <span class="text-xs text-slate-500">{{ fmt(pair.together) }} transaksi</span>
                        </button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
