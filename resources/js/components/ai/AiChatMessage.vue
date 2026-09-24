<template>
    <div :class="['flex', message.role === 'user' ? 'justify-end' : 'justify-start']">
        <div :class="['flex max-w-[85%] items-start gap-2', message.role === 'user' ? 'flex-row-reverse' : '']">
            <!-- Avatar -->
            <img
                v-if="message.role === 'assistant'"
                src="/mas.webp"
                alt="AI Assistant"
                class="h-8 w-8 shrink-0 rounded-full object-contain"
            />

            <!-- Bubble -->
            <div
                :class="[
                    'rounded-2xl px-3 py-2 text-sm',
                    message.role === 'user'
                        ? 'rounded-br-sm bg-violet-600 text-white'
                        : 'rounded-tl-sm bg-slate-100 text-slate-800',
                ]"
            >
                <!-- Content -->
                <div v-if="message.confirmation" class="w-64 rounded-xl border border-amber-400 bg-white p-3 text-slate-800">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-600">Konfirmasi perubahan</p>
                    <p class="mt-2 text-sm font-semibold">{{ confirmationTitle }}</p>
                    <dl v-if="message.confirmation.tool === 'update_stock_bulk'" class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div class="col-span-2">
                            <dt class="text-slate-400">Perubahan</dt>
                            <dd class="font-medium">
                                {{ message.confirmation.arguments.set_to !== undefined ? `Setel stok menjadi ${message.confirmation.arguments.set_to}` : `Tambah ${message.confirmation.arguments.add} per produk` }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-slate-400">Produk ({{ bulkProductCount }})</dt>
                            <dd class="font-medium">{{ bulkProductNames }}</dd>
                        </div>
                    </dl>
                    <dl v-else-if="message.confirmation.tool === 'create_purchase_order'" class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div class="col-span-2">
                            <dt class="text-slate-400">Produk</dt>
                            <dd class="font-medium">{{ purchaseItemSummary }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Supplier</dt>
                            <dd class="font-medium">{{ message.confirmation.arguments.supplier_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Pembayaran</dt>
                            <dd class="font-medium">{{ message.confirmation.arguments.payment_method === 'credit' ? 'Kredit (tempo)' : 'Cash' }}</dd>
                        </div>
                    </dl>
                    <dl v-else class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div v-if="message.confirmation.arguments.name ?? message.confirmation.arguments.product_name">
                            <dt class="text-slate-400">Nama</dt>
                            <dd class="font-medium">{{ message.confirmation.arguments.name ?? message.confirmation.arguments.product_name }}</dd>
                        </div>
                        <div v-if="message.confirmation.arguments.price !== undefined && message.confirmation.arguments.price !== ''">
                            <dt class="text-slate-400">Harga jual</dt>
                            <dd class="font-medium">{{ formatCurrency(message.confirmation.arguments.price) }}</dd>
                        </div>
                        <div v-if="message.confirmation.arguments.cost"><dt class="text-slate-400">HPP</dt><dd class="font-medium">{{ formatCurrency(message.confirmation.arguments.cost) }}</dd></div>
                        <div v-if="message.confirmation.arguments.stock !== undefined"><dt class="text-slate-400">Stok awal</dt><dd class="font-medium">{{ message.confirmation.arguments.stock }} {{ message.confirmation.arguments.unit ?? 'pcs' }}</dd></div>
                        <div v-if="message.confirmation.arguments.category"><dt class="text-slate-400">Kategori</dt><dd class="font-medium">{{ message.confirmation.arguments.category }}</dd></div>
                    </dl>
                    <div class="mt-3 flex gap-2">
                        <button class="flex-1 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600" @click="emit('confirm', message.confirmation.id)">Setuju</button>
                        <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600" @click="emit('reject', message.confirmation.id)">Tolak</button>
                    </div>
                </div>
                <div v-else-if="message.role === 'assistant'" class="prose-ai" v-html="renderedContent" />
                <p v-else>{{ message.content }}</p>

                <!-- Timestamp & Feedback -->
                <div class="mt-1 flex items-center gap-2">
                    <p
                        :class="[
                            'text-[10px]',
                            message.role === 'user' ? 'text-violet-200' : 'text-slate-400',
                        ]"
                    >
                        {{ message.timestamp }}
                    </p>

                    <!-- Feedback buttons (hanya untuk assistant) -->
                    <div v-if="message.role === 'assistant' && typeof message.id === 'number'" class="flex items-center gap-1">
                        <button
                            v-if="!message.feedback || message.feedback === 'positive'"
                            :class="[
                                'rounded p-0.5 transition-colors',
                                message.feedback === 'positive'
                                    ? 'bg-green-100 text-green-600'
                                    : 'text-slate-300 hover:bg-green-50 hover:text-green-500',
                            ]"
                            title="Respons membantu"
                            @click="sendFeedback('positive')"
                        >
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
                            </svg>
                        </button>
                        <button
                            v-if="!message.feedback || message.feedback === 'negative'"
                            :class="[
                                'rounded p-0.5 transition-colors',
                                message.feedback === 'negative'
                                    ? 'bg-red-100 text-red-600'
                                    : 'text-slate-300 hover:bg-red-50 hover:text-red-500',
                            ]"
                            title="Respons kurang tepat"
                            @click="sendFeedback('negative')"
                        >
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018c.163 0 .326-.02.485-.06L17 4m-7 10v2a2 2 0 002 2h.095c.5 0 .905.405.905.904 0 .715-.211 1.413-.608 2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { marked } from 'marked'

interface Message {
    id: number | string
    role: 'user' | 'assistant'
    content: string
    feedback?: 'positive' | 'negative' | null
    timestamp: string
    confirmation?: {
        id: string
        tool: string
        arguments: Record<string, string | number | string[] | undefined>
    }
}

const props = defineProps<{
    message: Message
}>()

const emit = defineEmits<{
    confirm: [confirmationId: string]
    reject: [confirmationId: string]
    feedback: [messageId: number, feedback: 'positive' | 'negative']
}>()

// Set up marked with options
marked.setOptions({
    breaks: true,
    gfm: true,
})

const renderedContent = computed(() => {
    if (props.message.role !== 'assistant') return ''
    return marked.parse(props.message.content || '') as string
})

const confirmationTitle = computed(() => {
    const tool = props.message.confirmation?.tool
    if (tool === 'update_stock') return 'Penyesuaian stok'
    if (tool === 'update_stock_bulk') return 'Penyesuaian stok massal'
    if (tool === 'update_product') return 'Ubah produk'
    if (tool === 'create_purchase_order') return 'Pembelian baru'
    return 'Produk baru'
})

const purchaseItemSummary = computed(() => {
    const items = props.message.confirmation?.arguments.items
    if (!Array.isArray(items)) return '-'
    const rows = items as unknown as Array<Record<string, string | number | undefined>>
    if (rows.length === 0) return '-'
    return rows
        .map((item) => `${item.product_name ?? item.product_id ?? '-'} × ${item.quantity ?? '-'}${item.unit ? ` ${item.unit}` : ''}`)
        .join(', ')
})

const bulkProductNames = computed(() => {
    const names = props.message.confirmation?.arguments.product_names
    if (!Array.isArray(names)) return ''
    return names.length > 6 ? `${names.slice(0, 6).join(', ')}, …` : names.join(', ')
})

const bulkProductCount = computed(() => {
    const names = props.message.confirmation?.arguments.product_names
    return Array.isArray(names) ? names.length : 0
})

function formatCurrency(value: unknown): string {
    if (value === undefined || value === null || value === '') return '-'
    const num = Number(value)
    if (Number.isNaN(num)) return '-'
    return `Rp ${new Intl.NumberFormat('id-ID').format(num)}`
}

function sendFeedback(feedback: 'positive' | 'negative') {
    if (typeof props.message.id === 'number') {
        emit('feedback', props.message.id, feedback)
    }
}
</script>

<style scoped>
/* AI Message Markdown Styling */
.prose-ai :deep(h1),
.prose-ai :deep(h2),
.prose-ai :deep(h3) {
    font-size: 0.875rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0.5rem 0 0.25rem;
}

.prose-ai :deep(h1) {
    font-size: 1rem;
}

.prose-ai :deep(p) {
    margin: 0.25rem 0;
    line-height: 1.5;
}

.prose-ai :deep(strong) {
    font-weight: 600;
    color: #0f172a;
}

.prose-ai :deep(em) {
    font-style: italic;
}

.prose-ai :deep(code) {
    background: #e2e8f0;
    padding: 0.1rem 0.3rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-family: 'SFMono-Regular', 'Menlo', 'Consolas', monospace;
}

.prose-ai :deep(pre) {
    background: #f1f5f9;
    padding: 0.5rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 0.5rem 0;
}

.prose-ai :deep(pre code) {
    background: transparent;
    padding: 0;
}

.prose-ai :deep(ul),
.prose-ai :deep(ol) {
    padding-left: 1rem;
    margin: 0.25rem 0;
}

.prose-ai :deep(ul) {
    list-style-type: disc;
}

.prose-ai :deep(ol) {
    list-style-type: decimal;
}

.prose-ai :deep(li) {
    margin: 0.1rem 0;
}

.prose-ai :deep(blockquote) {
    border-left: 3px solid #cbd5e1;
    padding-left: 0.75rem;
    margin: 0.5rem 0;
    color: #64748b;
    font-style: italic;
}

/* Table styling */
.prose-ai :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0.5rem 0;
    font-size: 0.75rem;
}

.prose-ai :deep(thead) {
    background: #f1f5f9;
}

.prose-ai :deep(th) {
    padding: 0.4rem 0.5rem;
    text-align: left;
    font-weight: 600;
    color: #334155;
    border-bottom: 2px solid #cbd5e1;
}

.prose-ai :deep(td) {
    padding: 0.4rem 0.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.prose-ai :deep(tr:hover td) {
    background: #f8fafc;
}

/* Horizontal rule */
.prose-ai :deep(hr) {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 0.5rem 0;
}

/* Links */
.prose-ai :deep(a) {
    color: #2563eb;
    text-decoration: underline;
}
</style>
