<template>
    <Transition name="slide-right">
        <div
            v-if="isOpen"
            :class="[
                'fixed top-0 right-0 z-50 flex h-full w-full flex-col bg-white',
                fullscreen ? 'inset-0' : 'border-l border-slate-200 shadow-xl sm:w-96',
            ]"
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div class="flex items-center gap-2">
                    <img src="/mas.webp" alt="AI Assistant" class="h-8 w-8 rounded-full object-contain" />
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">AI Assistant</h3>
                        <p class="text-xs text-slate-500">Siap membantu Anda</p>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button
                        v-if="fullscreen"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        title="Riwayat percakapan"
                        @click="toggleHistory"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                    <button
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        title="Percakapan baru"
                        @click="startNewConversation"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                    <button
                        v-if="!fullscreen"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        title="Buka layar penuh"
                        @click="$emit('expand')"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4" />
                        </svg>
                    </button>
                    <button
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        :title="fullscreen ? 'Tutup layar penuh' : 'Tutup'"
                        @click="$emit('close')"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div :class="['flex min-h-0 flex-1 flex-col', fullscreen ? 'relative mx-auto w-full max-w-3xl' : '']">
                <!-- Riwayat percakapan sebelumnya (hanya layar penuh) -->
                <div v-if="fullscreen && showHistory" class="absolute inset-0 z-20 flex flex-col bg-white">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h4 class="text-sm font-semibold text-slate-900">Riwayat Percakapan</h4>
                        <button
                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            title="Tutup riwayat"
                            @click="showHistory = false"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-3">
                        <!-- Loading skeleton -->
                        <div v-if="isLoadingHistory" class="space-y-2">
                            <div v-for="n in 4" :key="n" class="h-16 animate-pulse rounded-xl bg-slate-100" />
                        </div>

                        <!-- Empty state -->
                        <div v-else-if="conversations.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
                            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                                <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 10h.01M12 10h.01M16 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                    />
                                </svg>
                            </div>
                            <p class="text-sm font-medium text-slate-700">Belum ada riwayat percakapan</p>
                            <p class="mt-1 text-xs text-slate-500">Percakapan Anda dengan AI akan tersimpan di sini</p>
                        </div>

                        <!-- Daftar percakapan -->
                        <div v-else class="space-y-2">
                            <div
                                v-for="conv in conversations"
                                :key="conv.id"
                                role="button"
                                tabindex="0"
                                class="group flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-3 py-2.5 hover:border-blue-200 hover:bg-blue-50/50"
                                @click="openConversation(conv.id)"
                                @keydown.enter="openConversation(conv.id)"
                            >
                                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-50">
                                    <svg class="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M8 10h.01M12 10h.01M16 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                        />
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ conv.title }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ conv.summary || 'Tanpa ringkasan' }}</p>
                                    <p class="mt-0.5 text-[10px] text-slate-400">{{ conv.last_message_at }}</p>
                                </div>
                                <button
                                    class="rounded-lg p-1.5 text-slate-300 opacity-0 transition group-hover:opacity-100 hover:bg-red-50 hover:text-red-500"
                                    title="Hapus percakapan"
                                    @click.stop="deleteConversationFromHistory(conv.id)"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div ref="messagesContainer" class="chat-wallpaper flex-1 space-y-3 overflow-y-auto px-4 py-3">
                    <!-- FAQ awal -->
                    <div v-if="messages.length === 0" class="py-2">
                        <div class="mb-3 rounded-xl bg-white/85 p-3 text-left shadow-sm ring-1 ring-slate-200/80">
                            <p class="text-sm font-semibold text-slate-800">Halo! Apa yang ingin Anda cek?</p>
                            <p class="mt-1 text-xs leading-relaxed text-slate-500">Pilih pertanyaan untuk meminta bantuan AI secara langsung.</p>
                        </div>
                        <div v-if="!selectedFaqCategory" class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="category in faqCategories"
                                :key="category.label"
                                class="min-w-0 rounded-xl border border-white/80 bg-white/90 p-3 text-left text-xs font-semibold text-slate-700 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 focus:ring-2 focus:ring-blue-300 focus:outline-none"
                                @click="selectedFaqCategory = category"
                            >
                                <component :is="category.component" class="mb-2 h-5 w-5 text-slate-500" />
                                <span class="block">{{ category.label }}</span>
                            </button>
                        </div>
                        <div v-else>
                            <div class="mb-2 flex items-center gap-2">
                                <button
                                    class="rounded-lg p-1.5 text-slate-500 hover:bg-white/80 hover:text-slate-700 focus:ring-2 focus:ring-blue-300 focus:outline-none"
                                    title="Kembali ke kategori"
                                    @click="selectedFaqCategory = null"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>
                                <div class="flex items-center gap-2">
                                    <component :is="selectedFaqCategory.component" class="h-4 w-4 text-slate-500" />
                                    <p class="text-xs font-semibold text-slate-700">{{ selectedFaqCategory.label }}</p>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <button
                                    v-for="question in selectedFaqCategory.questions"
                                    :key="question.prompt"
                                    class="group flex w-full min-w-0 items-start gap-2 rounded-xl border border-white/80 bg-white/90 p-3 text-left shadow-sm transition hover:border-blue-200 hover:bg-blue-50 focus:ring-2 focus:ring-blue-300 focus:outline-none"
                                    @click="sendQuickAction(question.prompt)"
                                >
                                    <span
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-sm group-hover:bg-blue-100"
                                    >
                                        {{ question.icon }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-xs font-semibold text-slate-700">{{ question.label }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-slate-500">{{ question.description }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Message list -->
                    <AiChatMessage
                        v-for="msg in messages"
                        :key="msg.id"
                        :message="msg"
                        @confirm="confirmProduct"
                        @reject="rejectProduct"
                        @feedback="handleFeedback"
                    />

                    <!-- Loading indicator -->
                    <div v-if="isLoading" class="flex items-start gap-2">
                        <img src="/mas.webp" alt="AI Assistant" class="h-8 w-8 shrink-0 rounded-full object-contain" />
                        <div class="rounded-2xl rounded-tl-sm bg-slate-100 px-3 py-2">
                            <div class="flex gap-1">
                                <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400 [animation-delay:0ms]" />
                                <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400 [animation-delay:150ms]" />
                                <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400 [animation-delay:300ms]" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input -->
                <div class="border-t border-slate-200 px-4 py-3">
                    <form @submit.prevent="sendMessage" class="flex gap-2">
                        <input
                            ref="inputRef"
                            v-model="input"
                            type="text"
                            placeholder="Ketik pesan Anda..."
                            class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-blue-300 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                            :disabled="isLoading"
                        />
                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-3 py-2 text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="!input.trim() || isLoading"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                            </svg>
                        </button>
                    </form>
                    <p class="mt-1 text-center text-[10px] text-slate-400">Tekan Enter untuk mengirim</p>
                </div>
            </div>
        </div>
    </Transition>
</template>

<script setup lang="ts">
import { ChartNoAxesCombined, Package, Settings2, ShieldCheck, Tags, Users } from 'lucide-vue-next';
import { nextTick, ref, watch, type Component } from 'vue';
import AiChatMessage from './AiChatMessage.vue';

interface Message {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    feedback?: 'positive' | 'negative' | null;
    timestamp: string;
    confirmation?: {
        id: string;
        tool: string;
        arguments: Record<string, string | number | string[] | undefined>;
    };
}

interface ChatResult {
    success?: boolean;
    error?: string;
    conversation_id?: number;
    message?: { id: number; role: 'assistant'; content: string; timestamp: string };
    confirmation?: Message['confirmation'];
}

interface Conversation {
    id: number;
    title: string;
    summary: string | null;
    last_message_at: string;
}

const props = defineProps<{
    isOpen: boolean;
    fullscreen?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    expand: [];
}>();

const input = ref('');
const inputRef = ref<HTMLInputElement>();
const messagesContainer = ref<HTMLDivElement>();
const messages = ref<Message[]>([]);
const isLoading = ref(false);
const conversationId = ref<number | null>(null);
const showHistory = ref(false);
const conversations = ref<Conversation[]>([]);
const isLoadingHistory = ref(false);

interface FaqQuestion {
    icon: string;
    label: string;
    description: string;
    prompt: string;
}

interface FaqCategory {
    component: Component;
    label: string;
    questions: FaqQuestion[];
}

const faqCategories: FaqCategory[] = [
    {
        component: Package,
        label: 'Stok & Pembelian',
        questions: [
            {
                icon: '⚠️',
                label: 'Stok rendah',
                description: 'Lihat produk yang perlu diperhatikan.',
                prompt: 'Tampilkan produk yang stoknya rendah',
            },
            {
                icon: '🔮',
                label: 'Prediksi stok habis',
                description: 'Perkirakan stok yang segera habis.',
                prompt: 'Prediksi stok produk yang akan habis',
            },
            {
                icon: '🛒',
                label: 'Saran restok',
                description: 'Dapatkan prioritas barang untuk dibeli.',
                prompt: 'Berikan rekomendasi restok produk',
            },
            {
                icon: '🧾',
                label: 'Buat purchase order',
                description: 'Siapkan pembelian dari supplier.',
                prompt: 'Buatkan purchase order untuk restock dari supplier',
            },
        ],
    },
    {
        component: ChartNoAxesCombined,
        label: 'Penjualan & Laporan',
        questions: [
            { icon: '💰', label: 'Penjualan hari ini', description: 'Lihat total transaksi hari ini.', prompt: 'Berapa total penjualan hari ini?' },
            { icon: '📈', label: 'Insight penjualan', description: 'Analisis performa toko minggu ini.', prompt: 'Analisa toko saya minggu ini' },
            { icon: '🔭', label: 'Forecast penjualan', description: 'Prediksi omzet periode berikutnya.', prompt: 'Prediksi penjualan minggu depan' },
            { icon: '🧮', label: 'Laba rugi', description: 'Periksa laba bersih dan beban usaha.', prompt: 'Laporan laba rugi bulan ini' },
        ],
    },
    {
        component: Tags,
        label: 'Produk & Harga',
        questions: [
            { icon: '🔍', label: 'Cari produk', description: 'Temukan produk berdasarkan nama.', prompt: 'Cari produk' },
            {
                icon: '💡',
                label: 'Rekomendasi harga',
                description: 'Dapatkan saran harga jual dan margin.',
                prompt: 'Berikan rekomendasi harga untuk produk saya',
            },
            {
                icon: '📉',
                label: 'Insight profit produk',
                description: 'Temukan produk rugi, bocor, atau peluang bundling.',
                prompt: 'Insight laba per produk',
            },
            { icon: '✏️', label: 'Ubah produk', description: 'Perbarui nama, harga, atau detail produk.', prompt: 'Saya ingin mengubah data produk' },
        ],
    },
    {
        component: Users,
        label: 'Pelanggan',
        questions: [
            { icon: '🔎', label: 'Cari pelanggan', description: 'Temukan data pelanggan.', prompt: 'Cari data pelanggan' },
            {
                icon: '💤',
                label: 'Pelanggan pasif',
                description: 'Lihat pelanggan yang mulai jarang belanja.',
                prompt: 'Pelanggan mana yang mulai jarang belanja?',
            },
            { icon: '⭐', label: 'Pelanggan terbaik', description: 'Kenali pelanggan paling bernilai.', prompt: 'Siapa pelanggan terbaik saya?' },
        ],
    },
    {
        component: ShieldCheck,
        label: 'Audit & Kesalahan Data',
        questions: [
            {
                icon: '🚨',
                label: 'Cek kesalahan data/transaksi',
                description: 'Audit transaksi mencurigakan, diskon aneh, void, dan selisih kas.',
                prompt: 'Cek anomali transaksi dan kesalahan data bulan ini',
            },
            {
                icon: '💸',
                label: 'Audit diskon dan void',
                description: 'Temukan diskon tidak wajar atau void berulang.',
                prompt: 'Cek diskon aneh dan void berulang minggu ini',
            },
            {
                icon: '📜',
                label: 'Riwayat aksi AI',
                description: 'Lihat perubahan yang pernah dijalankan AI.',
                prompt: 'Lihat log aksi yang sudah dilakukan',
            },
        ],
    },
    {
        component: Settings2,
        label: 'Pengaturan Data',
        questions: [
            { icon: '➕', label: 'Tambah produk', description: 'Buat data produk baru.', prompt: 'Saya ingin menambah produk baru' },
            {
                icon: '📦',
                label: 'Ubah stok produk',
                description: 'Tambah atau kurangi stok satu produk.',
                prompt: 'Saya ingin mengubah stok produk',
            },
            {
                icon: '📚',
                label: 'Ubah stok massal',
                description: 'Setel stok beberapa produk dari hasil cek stok.',
                prompt: 'Cek stok yang habis, lalu saya ingin setel semua stok produk ini jadi 100',
            },
            {
                icon: '💈',
                label: 'Komisi barber',
                description: 'Khusus barbershop: cek rekap komisi teknisi.',
                prompt: 'Cek komisi barber bulan ini',
            },
        ],
    },
];

const selectedFaqCategory = ref<FaqCategory | null>(null);

watch(
    () => props.isOpen,
    (val) => {
        if (val) {
            nextTick(() => inputRef.value?.focus());
        }
    },
);

async function scrollToBottom() {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

function addMessage(role: 'user' | 'assistant', content: string) {
    messages.value.push({
        id: Date.now(),
        role,
        content,
        timestamp: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
    });
    scrollToBottom();
}

function addMessageWithFeedback(msg: { id: number; role: 'user' | 'assistant'; content: string; feedback?: string | null; timestamp: string }) {
    messages.value.push({
        id: msg.id,
        role: msg.role,
        content: msg.content,
        feedback: msg.feedback as 'positive' | 'negative' | null | undefined,
        timestamp: msg.timestamp,
    });
    scrollToBottom();
}

function csrfHeaders(): Record<string, string> {
    // Cookie XSRF-TOKEN selalu diperbarui oleh setiap response (termasuk
    // saat user ganti/login ulang), sedangkan meta tag hanya dirender
    // saat halaman pertama dimuat. Cookie berisi nilai ter-encrypt, jadi
    // harus dikirim via header X-XSRF-TOKEN (didecrypt VerifyCsrfToken).
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) {
        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) };
        } catch {
            // lanjut ke fallback meta
        }
    }
    return {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    };
}

function nowTime(): string {
    return new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

function removeMessage(id: number | string) {
    messages.value = messages.value.filter((message) => message.id !== id);
}

function applyChatResult(data: ChatResult, liveId?: number | string) {
    const live = liveId !== undefined ? messages.value.find((message) => message.id === liveId) : undefined;

    if (data.success && data.conversation_id) {
        conversationId.value = data.conversation_id;

        if (data.confirmation) {
            if (live) removeMessage(live.id);
            messages.value.push({
                id: `confirmation-${data.confirmation.id}`,
                role: 'assistant',
                content: '',
                timestamp: nowTime(),
                confirmation: data.confirmation,
            });
            scrollToBottom();

            return;
        }

        if (data.message) {
            if (live) {
                live.id = data.message.id;
                live.content = data.message.content;
                live.feedback = null;
                live.timestamp = data.message.timestamp;
            } else {
                addMessageWithFeedback({ ...data.message, feedback: null });
            }
            scrollToBottom();

            return;
        }

        if (live && !live.content) {
            removeMessage(live.id);
        }

        return;
    }

    if (live) removeMessage(live.id);
    addMessage('assistant', data.error || 'Maaf, terjadi kesalahan. Silakan coba lagi.');
}

async function sendMessage() {
    const text = input.value.trim();
    if (!text || isLoading.value) return;

    addMessage('user', text);
    input.value = '';
    isLoading.value = true;

    // Bubble live yang diisi token selama streaming
    const liveId = `stream-${Date.now()}`;
    messages.value.push({
        id: liveId,
        role: 'assistant',
        content: '',
        timestamp: nowTime(),
    });
    scrollToBottom();

    let streamed = false;
    let finalResult: ChatResult | null = null;

    try {
        const response = await fetch('/ai/chat/stream', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId.value,
            }),
        });

        if (!response.ok || !response.body) {
            throw new Error(`Stream gagal: HTTP ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });

            const events = buffer.split('\n\n');
            buffer = events.pop() ?? '';

            for (const event of events) {
                const line = event.trim();
                if (!line.startsWith('data:')) continue;

                const payload = JSON.parse(line.slice(5).trim());

                if (payload.type === 'delta') {
                    streamed = true;
                    const live = messages.value.find((message) => message.id === liveId);
                    if (live) {
                        live.content += payload.content;
                        scrollToBottom();
                    }
                } else if (payload.type === 'result') {
                    finalResult = payload.data;
                } else if (payload.type === 'error') {
                    finalResult = { success: false, error: payload.message };
                }
            }
        }
    } catch {
        finalResult = null;
    }

    if (finalResult) {
        applyChatResult(finalResult, liveId);
    } else if (streamed) {
        // Stream terputus setelah token masuk — tampilkan teks yang sudah ada
        const live = messages.value.find((message) => message.id === liveId);
        if (live && !live.content) {
            removeMessage(liveId);
        }
    } else {
        // Belum ada token sama sekali — fallback ke chat biasa
        removeMessage(liveId);
        await sendWithoutStream(text);
    }

    isLoading.value = false;
}

async function sendWithoutStream(text: string) {
    try {
        const response = await fetch('/ai/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId.value,
            }),
        });

        const data: ChatResult = await response.json();
        applyChatResult(data);
    } catch {
        addMessage('assistant', 'Gagal mengirim pesan. Periksa koneksi internet Anda.');
    }
}

async function confirmProduct(confirmationId: string) {
    const response = await fetch('/ai/chat/confirm', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            ...csrfHeaders(),
            Accept: 'application/json',
        },
        body: JSON.stringify({ confirmation_id: confirmationId }),
    });
    const data = await response.json();

    if (data.success && data.message) {
        messages.value = messages.value.filter((message) => message.confirmation?.id !== confirmationId);
        addMessageWithFeedback({
            id: data.message.id,
            role: 'assistant',
            content: data.message.content,
            feedback: null,
            timestamp: data.message.timestamp,
        });
    }
}

async function rejectProduct(confirmationId: string) {
    await fetch('/ai/chat/reject', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            ...csrfHeaders(),
        },
        body: JSON.stringify({ confirmation_id: confirmationId }),
    });
    messages.value = messages.value.filter((message) => message.confirmation?.id !== confirmationId);
    addMessage('assistant', 'Perubahan dibatalkan.');
}

function sendQuickAction(prompt: string) {
    input.value = prompt;
    sendMessage();
}

async function handleFeedback(messageId: number, feedback: 'positive' | 'negative') {
    // Update UI immediately
    const msg = messages.value.find((m) => m.id === messageId);
    if (msg) {
        msg.feedback = feedback;
    }

    // Send to backend
    try {
        await fetch(`/ai/messages/${messageId}/feedback`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({ feedback }),
        });
    } catch (e) {
        // ignore
    }
}

function toggleHistory() {
    showHistory.value = !showHistory.value;
    if (showHistory.value) {
        loadConversations();
    }
}

async function loadConversations() {
    isLoadingHistory.value = true;

    try {
        const res = await fetch('/ai/conversations', {
            credentials: 'same-origin',
            headers: { ...csrfHeaders() },
        });
        const data = await res.json();

        if (data.success) {
            conversations.value = data.conversations;
        }
    } catch {
        // ignore
    }

    isLoadingHistory.value = false;
}

async function openConversation(id: number) {
    try {
        const res = await fetch(`/ai/conversations/${id}`, {
            credentials: 'same-origin',
            headers: { ...csrfHeaders() },
        });
        const data = await res.json();

        if (!data.success) return;

        conversationId.value = data.conversation.id;
        messages.value = data.messages
            .filter((msg: { role: string; content: string | null }) => (msg.role === 'user' || msg.role === 'assistant') && !!msg.content)
            .map(
                (msg: {
                    id: number;
                    role: 'user' | 'assistant';
                    content: string;
                    feedback?: 'positive' | 'negative' | null;
                    timestamp: string;
                }): Message => ({
                    id: msg.id,
                    role: msg.role,
                    content: msg.content,
                    feedback: msg.feedback ?? null,
                    timestamp: msg.timestamp,
                }),
            );
        showHistory.value = false;
        scrollToBottom();
    } catch {
        // ignore
    }
}

async function deleteConversationFromHistory(id: number) {
    try {
        await fetch(`/ai/conversations/${id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { ...csrfHeaders() },
        });
        conversations.value = conversations.value.filter((conv) => conv.id !== id);

        // Jika percakapan yang dihapus sedang terbuka, reset chat
        if (conversationId.value === id) {
            conversationId.value = null;
            messages.value = [];
        }
    } catch {
        // ignore
    }
}

async function startNewConversation() {
    messages.value = [];
    conversationId.value = null;
    showHistory.value = false;

    try {
        const res = await fetch('/ai/conversations/new', {
            credentials: 'same-origin',
            headers: {
                ...csrfHeaders(),
            },
        });
        const data = await res.json();

        if (data.success) {
            conversationId.value = data.conversation.id;
        }
    } catch (e) {
        // ignore
    }
}
</script>

<style scoped>
/* Wallpaper chat krem khas WhatsApp iOS dengan pola doodle tipis */
.chat-wallpaper {
    background-color: #ece5dd;
    background-image: url("data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 96 96' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23d9d0c4' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 18c2-4.5 8-4.5 10 0 2-4.5 8-4.5 10 0 0 7-10.5 12.5-10 12.5S14 25 14 18z'/%3E%3Ccircle cx='70' cy='16' r='6'/%3E%3Cpath d='M64 16h12M70 10v12'/%3E%3Crect x='54' y='54' width='18' height='13' rx='4'/%3E%3Cpath d='M59 67l-3 6 8-6'/%3E%3Cpath d='M20 60l4 4-4 4-4-4z'/%3E%3Cpath d='M34 40c3-2 7-2 10 0M36 46c2-1.5 5-1.5 7 0'/%3E%3Cpath d='M86 74c0-3.5 5-3.5 5 0s-5 6-5 6-5-2.5-5-6 5-3.5 5 3.5z'/%3E%3Cpath d='M44 82h12M50 76v12'/%3E%3C/g%3E%3C/svg%3E");
    background-size: 96px 96px;
    background-repeat: repeat;
}

.slide-right-enter-active,
.slide-right-leave-active {
    transition: transform 0.3s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
    transform: translateX(100%);
}
</style>
