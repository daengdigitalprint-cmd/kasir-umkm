<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete, toastWarning } from '@/lib/swal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, GripVertical, Layers, Loader2, Plus, Store, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface CategoryRow {
    id: number;
    name: string;
    sort_order: number;
    products_count: number;
}

const props = defineProps<{
    categories: CategoryRow[];
    store: { id: number; name: string };
}>();

const page = usePage();
const multiStore = computed(() => ((page.props as { accessible_stores?: unknown[] }).accessible_stores?.length ?? 0) > 1);

// ── Daftar lokal (urutan bisa digeser sebelum tersimpan) ────────────────
const rows = ref<CategoryRow[]>([]);
const names = ref<Record<number, string>>({});
watch(
    () => props.categories,
    (list) => {
        rows.value = [...list];
        names.value = Object.fromEntries(list.map((c) => [c.id, c.name]));
    },
    { immediate: true },
);

const usedCount = computed(() => rows.value.filter((c) => c.products_count > 0).length);

// ── Tambah ───────────────────────────────────────────────────────────────
const addForm = useForm({ name: '' });
function submitAdd() {
    if (!addForm.name.trim()) return;
    addForm.post('/product-categories', {
        preserveScroll: true,
        onSuccess: () => addForm.reset('name'),
    });
}

// ── Ganti nama langsung di tempat (simpan saat Enter / keluar kolom) ─────
const savingId = ref<number | null>(null);
function saveName(c: CategoryRow) {
    const name = (names.value[c.id] ?? '').trim();
    if (name === c.name) return;
    if (!name) {
        names.value[c.id] = c.name;
        return;
    }
    savingId.value = c.id;
    router.put(
        `/product-categories/${c.id}`,
        { name, sort_order: c.sort_order },
        {
            preserveScroll: true,
            // Jangan saling membatalkan dengan simpan urutan yang berjalan bersamaan.
            async: true,
            onError: (errors) => {
                names.value[c.id] = c.name;
                void toastWarning(errors.name ?? 'Nama kategori belum tersimpan.');
            },
            onFinish: () => (savingId.value = null),
        },
    );
}
function cancelName(c: CategoryRow, e: KeyboardEvent) {
    names.value[c.id] = c.name;
    (e.target as HTMLInputElement).blur();
}

// ── Urutan: geser (desktop) atau tombol naik/turun ──────────────────────
const savingOrder = ref(false);
function persistOrder() {
    savingOrder.value = true;
    router.post(
        '/product-categories/reorder',
        { ids: rows.value.map((c) => c.id) },
        { preserveScroll: true, preserveState: true, async: true, onFinish: () => (savingOrder.value = false) },
    );
}
function move(index: number, delta: number) {
    const target = index + delta;
    if (target < 0 || target >= rows.value.length) return;
    const next = [...rows.value];
    const [item] = next.splice(index, 1);
    next.splice(target, 0, item);
    rows.value = next;
    persistOrder();
}

// Baris hanya bisa digeser lewat pegangannya, supaya teks nama tetap bisa diseleksi.
const armedId = ref<number | null>(null);
const dragIndex = ref<number | null>(null);
const overIndex = ref<number | null>(null);
function onDragStart(index: number, e: DragEvent) {
    dragIndex.value = index;
    e.dataTransfer?.setData('text/plain', String(index));
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
}
function onDragOver(index: number) {
    overIndex.value = index;
}
function onDrop(index: number) {
    const from = dragIndex.value;
    dragIndex.value = null;
    overIndex.value = null;
    if (from === null || from === index) return;
    const next = [...rows.value];
    const [item] = next.splice(from, 1);
    next.splice(index, 0, item);
    rows.value = next;
    persistOrder();
}
function onDragEnd() {
    armedId.value = null;
    dragIndex.value = null;
    overIndex.value = null;
}

// ── Hapus: tawarkan pindah produk ke kategori lain ───────────────────────
const deleting = ref<CategoryRow | null>(null);
const moveTo = ref<number | ''>('');
const deleteBusy = ref(false);
const otherCategories = computed(() => rows.value.filter((c) => c.id !== deleting.value?.id));

async function askDelete(c: CategoryRow) {
    if (c.products_count === 0) {
        if (await confirmDelete('Hapus kategori?', `Kategori «${c.name}» akan dihapus.`)) {
            router.delete(`/product-categories/${c.id}`, { preserveScroll: true });
        }
        return;
    }
    moveTo.value = '';
    deleting.value = c;
}
function confirmDeleteWithMove() {
    const c = deleting.value;
    if (!c) return;
    deleteBusy.value = true;
    router.delete(`/product-categories/${c.id}`, {
        data: moveTo.value === '' ? {} : { move_to: moveTo.value },
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
        onFinish: () => (deleteBusy.value = false),
    });
}

const productsLink = (c: CategoryRow) => `/products?category=${encodeURIComponent(c.name)}`;
</script>

<template>
    <Head title="Kategori Produk" />

    <AdminLayout title="Kategori">
        <template #header-actions>
            <Button as="a" href="/products" variant="outline" size="sm" class="inline-flex items-center gap-2">
                <ArrowLeft class="h-4 w-4" />
                Produk
            </Button>
        </template>

        <div class="mx-auto max-w-3xl space-y-5">
            <div>
                <p class="text-sm text-slate-500">Kategori mengelompokkan menu di kasir dan menu digital, serta dipakai untuk diskon dan laporan.</p>
                <p v-if="multiStore" class="mt-2 flex items-center gap-1.5 text-xs text-slate-500">
                    <Store class="h-3.5 w-3.5 text-primary" />
                    <span>Berlaku untuk <strong class="text-slate-700">{{ store.name }}</strong></span>
                </p>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <!-- Tambah -->
                <form class="flex gap-2 border-b border-slate-100 p-3" @submit.prevent="submitAdd">
                    <div class="relative flex-1">
                        <Layers class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="addForm.name"
                            type="text"
                            maxlength="100"
                            placeholder="Nama kategori baru, mis. Minuman"
                            aria-label="Nama kategori baru"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                        />
                    </div>
                    <Button type="submit" class="h-10 shrink-0 gap-1.5" :disabled="addForm.processing || !addForm.name.trim()">
                        <Loader2 v-if="addForm.processing" class="h-4 w-4 animate-spin" />
                        <Plus v-else class="h-4 w-4" />
                        Tambah
                    </Button>
                </form>
                <p v-if="addForm.errors.name" class="px-4 pt-2 text-sm text-red-600">{{ addForm.errors.name }}</p>

                <div class="flex items-center justify-between px-4 pt-3 pb-1 text-xs text-slate-500">
                    <span>{{ rows.length }} kategori · {{ usedCount }} dipakai</span>
                    <span class="flex items-center gap-1.5">
                        <Loader2 v-if="savingOrder" class="h-3.5 w-3.5 animate-spin" />
                        <span class="hidden sm:inline">Geser untuk mengatur urutan di kasir</span>
                        <span class="sm:hidden">Pakai panah untuk mengatur urutan</span>
                    </span>
                </div>

                <!-- Daftar -->
                <ul v-if="rows.length" class="divide-y divide-slate-100 px-1 pb-1">
                    <li
                        v-for="(c, index) in rows"
                        :key="c.id"
                        class="group flex items-center gap-2 rounded-xl px-2 py-2 transition-colors"
                        :class="[
                            dragIndex === index ? 'opacity-40' : '',
                            overIndex === index && dragIndex !== null && dragIndex !== index ? 'bg-primary/5' : '',
                        ]"
                        :draggable="armedId === c.id"
                        @dragstart="onDragStart(index, $event)"
                        @dragover.prevent="onDragOver(index)"
                        @drop.prevent="onDrop(index)"
                        @dragend="onDragEnd"
                    >
                        <GripVertical
                            class="hidden h-4 w-4 shrink-0 cursor-grab text-slate-300 group-hover:text-slate-400 sm:block"
                            aria-hidden="true"
                            @mousedown="armedId = c.id"
                            @mouseup="armedId = null"
                        />
                        <div class="flex shrink-0 flex-col sm:hidden">
                            <button
                                type="button"
                                class="rounded p-0.5 text-slate-400 disabled:opacity-30"
                                :disabled="index === 0"
                                :aria-label="`Naikkan ${c.name}`"
                                @click="move(index, -1)"
                            >
                                <ArrowUp class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-0.5 text-slate-400 disabled:opacity-30"
                                :disabled="index === rows.length - 1"
                                :aria-label="`Turunkan ${c.name}`"
                                @click="move(index, 1)"
                            >
                                <ArrowDown class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <input
                            v-model="names[c.id]"
                            type="text"
                            maxlength="100"
                            :aria-label="`Nama kategori ${c.name}`"
                            class="h-9 min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-2 text-sm font-semibold text-slate-800 hover:border-slate-200 focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/20 focus:outline-none"
                            @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                            @keydown.esc.prevent="cancelName(c, $event)"
                            @blur="saveName(c)"
                        />
                        <Loader2 v-if="savingId === c.id" class="h-4 w-4 shrink-0 animate-spin text-slate-400" />

                        <Link
                            v-if="c.products_count > 0"
                            :href="productsLink(c)"
                            class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-primary/10 hover:text-primary"
                        >
                            {{ c.products_count }} produk
                        </Link>
                        <span v-else class="shrink-0 rounded-full px-2.5 py-1 text-xs text-slate-400">Belum dipakai</span>

                        <div class="hidden shrink-0 sm:flex">
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-slate-300 opacity-0 group-hover:opacity-100 hover:bg-slate-100 hover:text-slate-600 focus:opacity-100 disabled:opacity-0"
                                :disabled="index === 0"
                                :aria-label="`Naikkan ${c.name}`"
                                @click="move(index, -1)"
                            >
                                <ArrowUp class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-slate-300 opacity-0 group-hover:opacity-100 hover:bg-slate-100 hover:text-slate-600 focus:opacity-100 disabled:opacity-0"
                                :disabled="index === rows.length - 1"
                                :aria-label="`Turunkan ${c.name}`"
                                @click="move(index, 1)"
                            >
                                <ArrowDown class="h-4 w-4" />
                            </button>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                            :aria-label="`Hapus ${c.name}`"
                            @click="askDelete(c)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </li>
                </ul>
                <div v-else class="px-4 py-12 text-center">
                    <p class="font-semibold text-slate-700">Belum ada kategori</p>
                    <p class="mt-1 text-sm text-slate-500">Tambahkan di atas, lalu pilih saat membuat produk.</p>
                </div>
            </section>

            <p class="px-1 text-xs text-slate-400">Klik nama untuk menggantinya; tersimpan otomatis saat menekan Enter atau pindah kolom.</p>
        </div>

        <!-- Hapus kategori yang masih berisi produk -->
        <Dialog :open="deleting !== null" @update:open="(v) => !v && !deleteBusy && (deleting = null)">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Hapus kategori «{{ deleting?.name }}»?</DialogTitle>
                    <DialogDescription>
                        Masih ada {{ deleting?.products_count }} produk di kategori ini. Produknya tidak ikut terhapus.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-2">
                    <label for="move_to" class="text-sm font-semibold text-slate-700">Produknya dipindah ke</label>
                    <select
                        id="move_to"
                        v-model="moveTo"
                        class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    >
                        <option value="">Biarkan tanpa kategori</option>
                        <option v-for="o in otherCategories" :key="o.id" :value="o.id">{{ o.name }}</option>
                    </select>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" :disabled="deleteBusy" @click="deleting = null">Batal</Button>
                    <Button type="button" variant="destructive" :disabled="deleteBusy" @click="confirmDeleteWithMove">
                        <Loader2 v-if="deleteBusy" class="mr-1 h-4 w-4 animate-spin" />
                        Hapus kategori
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
