<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Line = { account_code: string; account_name: string; debit: number; credit: number; memo: string };
type Entry = {
    id: number;
    entry_date: string;
    description: string;
    reference_type: string;
    is_reversal: boolean;
    reversed_entry_id: number | null;
    user: string | null;
    lines: Line[];
};

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    entries: {
        data: Entry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();

const reversing = ref<number | null>(null);

const reverseForm = useForm({
    entry_date: new Date().toISOString().slice(0, 10),
    note: '',
});

const startReverse = (entry: Entry) => {
    reversing.value = entry.id;
    reverseForm.entry_date = new Date().toISOString().slice(0, 10);
    reverseForm.note = '';
};

const submitReverse = (entry: Entry) => {
    reverseForm.post(`/reports/financial/journal/${entry.id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => {
            reversing.value = null;
            reverseForm.reset('note');
        },
    });
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const goTo = (url: string | null) => {
    if (url) router.get(url, {}, { preserveScroll: true });
};
</script>

<template>
    <AdminLayout title="Jurnal umum">
        <Head title="Jurnal umum" />
        <ReportTabs active-tab="jurnal" />

        <p class="mb-4 text-sm text-slate-600">
            Daftar jurnal double entry. Untuk mengoreksi kesalahan pada periode yang sudah ditutup, buat jurnal
            pembalik pada periode terbuka.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
            <CardContent class="p-0">
                <div class="divide-y divide-slate-100">
                    <div v-for="entry in props.entries.data" :key="entry.id" class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ entry.description }}</span>
                                    <span
                                        v-if="entry.is_reversal"
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"
                                    >
                                        Pembalik #{{ entry.reversed_entry_id }}
                                    </span>
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ entry.entry_date }} · {{ entry.user ?? '-' }}
                                </div>
                            </div>
                            <Button
                                v-if="!entry.is_reversal"
                                size="sm"
                                variant="outline"
                                @click="startReverse(entry)"
                            >
                                Balik
                            </Button>
                        </div>

                        <table class="mt-2 w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-slate-400">
                                    <th class="py-1 font-medium">Akun</th>
                                    <th class="py-1 text-right font-medium">Debit</th>
                                    <th class="py-1 text-right font-medium">Kredit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, i) in entry.lines" :key="i" class="border-t border-slate-100">
                                    <td class="py-1">
                                        <span class="font-mono text-xs text-slate-500">{{ line.account_code }}</span>
                                        <span class="ml-2">{{ line.account_name }}</span>
                                    </td>
                                    <td class="py-1 text-right tabular-nums">
                                        {{ line.debit > 0 ? formatCurrency(line.debit) : '' }}
                                    </td>
                                    <td class="py-1 text-right tabular-nums">
                                        {{ line.credit > 0 ? formatCurrency(line.credit) : '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div
                            v-if="reversing === entry.id"
                            class="mt-3 rounded-xl border border-violet-200 bg-violet-50 p-3"
                        >
                            <p class="mb-2 text-xs font-semibold text-violet-900">
                                Jurnal pembalik pada periode terbuka
                            </p>
                            <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitReverse(entry)">
                                <div class="space-y-1.5">
                                    <Label class="text-xs">Tanggal</Label>
                                    <Input v-model="reverseForm.entry_date" type="date" class="w-40" required />
                                    <div v-if="reverseForm.errors.entry_date" class="text-xs text-red-600">
                                        {{ reverseForm.errors.entry_date }}
                                    </div>
                                </div>
                                <div class="space-y-1.5 flex-1 min-w-[12rem]">
                                    <Label class="text-xs">Catatan (opsional)</Label>
                                    <Input v-model="reverseForm.note" type="text" placeholder="Alasan pembalikan" />
                                </div>
                                <Button type="submit" size="sm" :disabled="reverseForm.processing">
                                    Simpan pembalik
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="reversing = null"
                                >
                                    Batal
                                </Button>
                            </form>
                        </div>
                    </div>

                    <div v-if="!props.entries.data.length" class="px-4 py-10 text-center text-slate-500">
                        Belum ada jurnal.
                    </div>
                </div>
            </CardContent>
        </Card>

        <div v-if="props.entries.links.length > 3" class="mt-4 flex items-center gap-2">
            <Button
                v-for="link in props.entries.links"
                :key="link.label"
                size="sm"
                variant="outline"
                :disabled="!link.url || link.active"
                @click="goTo(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </AdminLayout>
</template>
