<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { toastSuccess, toastError } from '@/lib/swal';
import { usePrinter } from '@/composables/usePrinter';
import { buildKotPlainLines } from '@/lib/receiptPlainText';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';
import { ChefHat, RefreshCw, Utensils, Hand, Printer, Wifi, WifiOff, CheckCheck, Undo2, ListChecks, ChevronDown, ChevronUp, Maximize2, Minimize2, Layers, Check, Volume2, VolumeX } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

// Modul Antrian Dapur aktif? Bila mati, tidak ada pesanan yang dikirim ke papan ini.
const kitchenModuleOn = computed(() => Boolean((usePage().props as { kitchen_queue_enabled?: boolean }).kitchen_queue_enabled));

type Status = 'waiting' | 'serving' | 'ready' | 'completed' | 'cancelled';
interface EntryItem { product_name: string; quantity: number | string; variant?: string | null; modifiers?: Array<{ label: string }> | null; category_id?: number | null; category?: string | null; done?: boolean; }
interface Entry {
    id: number;
    number: number;
    status: Status;
    order_type?: string | null;
    table_reference: string | null;
    transaction_number: string | null;
    created_at: string | null;
    called_at: string | null;
    ready_at?: string | null;
    completed_at?: string | null;
    notes: string | null;
    items: EntryItem[];
}
interface BroadcastPayload { action: 'upsert' | 'delete'; entry: Entry; entryId: number; }
interface Summary { completed_today: number; avg_prep_minutes: number | null; }

const props = withDefaults(
    defineProps<{
        store: { id: number; name: string };
        entries: Entry[];
        history?: Entry[];
        summary?: Summary;
        undoMinutes?: number;
        categories?: Array<{ id: number; name: string }>;
    }>(),
    { history: () => [], summary: () => ({ completed_today: 0, avg_prep_minutes: null }), undoMinutes: 10, categories: () => [] },
);

// ── Pengaturan per perangkat (tablet dapur / bar) ──
const storageKey = (name: string) => `kitchen.${name}.${props.store.id}`;
const readStore = <T,>(name: string, fallback: T): T => {
    try {
        const raw = localStorage.getItem(storageKey(name));
        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
};
const writeStore = (name: string, value: unknown) => {
    try {
        localStorage.setItem(storageKey(name), JSON.stringify(value));
    } catch {
        /* penyimpanan tidak tersedia: pengaturan berlaku sampai halaman ditutup */
    }
};

// Mode layar dapur: tanpa menu samping, huruf besar, layar tidak mati.
const kiosk = ref<boolean>(readStore('kiosk', false));
let wakeLock: { release: () => Promise<void> } | null = null;
const requestWakeLock = async () => {
    try {
        const nav = navigator as Navigator & { wakeLock?: { request: (t: 'screen') => Promise<{ release: () => Promise<void> }> } };
        if (kiosk.value && nav.wakeLock && document.visibilityState === 'visible') wakeLock = await nav.wakeLock.request('screen');
    } catch {
        /* perangkat tidak mendukung: layar mengikuti pengaturan perangkat */
    }
};
const releaseWakeLock = () => {
    wakeLock?.release().catch(() => {});
    wakeLock = null;
};
const toggleKiosk = async () => {
    kiosk.value = !kiosk.value;
    writeStore('kiosk', kiosk.value);
    if (kiosk.value) {
        try { await document.documentElement.requestFullscreen?.(); } catch { /* tidak semua perangkat mengizinkan */ }
        requestWakeLock();
    } else {
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
        releaseWakeLock();
    }
};
const onVisibility = () => { if (document.visibilityState === 'visible') requestWakeLock(); };

// Stasiun: kategori yang ditampilkan di perangkat ini (kosong = semua). 0 = tanpa kategori.
const station = ref<number[]>(readStore('station', [] as number[]));
const showStationPicker = ref(false);
const toggleStation = (id: number) => {
    station.value = station.value.includes(id) ? station.value.filter((x) => x !== id) : [...station.value, id];
    writeStore('station', station.value);
};
const clearStation = () => { station.value = []; writeStore('station', []); };
const stationLabel = computed(() => {
    if (station.value.length === 0) return 'Semua';
    const names = station.value.map((id) => (id === 0 ? 'Tanpa kategori' : props.categories.find((c) => c.id === id)?.name ?? '?'));
    return names.length > 2 ? `${names.slice(0, 2).join(', ')} +${names.length - 2}` : names.join(', ');
});
/** Item lama (sebelum ada kategori) tampil di semua stasiun. */
const itemInStation = (item: EntryItem) =>
    station.value.length === 0 || !('category_id' in item) || station.value.includes(item.category_id ?? 0);
const stationItems = (entry: Entry) =>
    entry.items.map((item, index) => ({ item, index })).filter(({ item }) => itemInStation(item));

const ACTIVE_STATUSES: Status[] = ['waiting', 'serving', 'ready'];
const entries = ref<Entry[]>([...props.entries]);
const history = ref<Entry[]>([...props.history]);
const completedToday = ref(props.summary.completed_today);
const avgPrep = ref<number | null>(props.summary.avg_prep_minutes);

watch(() => props.entries, (next) => { entries.value = [...next]; noticeNewOrders(next); }, { deep: true });
watch(() => props.history, (next) => { history.value = [...next]; }, { deep: true });
watch(() => props.summary, (next) => { completedToday.value = next.completed_today; avgPrep.value = next.avg_prep_minutes; }, { deep: true });

const tab = ref<'active' | 'done'>('active');
const showTodo = ref(true);

// ── Bunyi pesanan baru ──
// Bunyi "ding-dong" keras, diulang tiap 20 detik (maks. 3x) sampai layar disentuh
// atau pesanan mulai dibuat. Nyala/mati diingat per perangkat.
const soundEnabled = ref<boolean>(readStore('sound', true));
const audioContext = ref<AudioContext | null>(null);
const soundReady = ref(false);
// Bunyi yang tertahan karena suara belum diizinkan browser; dimainkan saat layar disentuh.
let pendingChime = false;
const unlockNotificationSound = () => {
    try {
        audioContext.value ??= new AudioContext();
        if (audioContext.value.state === 'suspended') audioContext.value.resume();
        soundReady.value = true;
        if (pendingChime) {
            pendingChime = false;
            setTimeout(() => playNewOrderSound(), 50);
        }
    } catch {
        /* perangkat tanpa audio */
    }
};

/**
 * Coba nyalakan suara tanpa sentuhan (diizinkan sebagian browser bila situs sering dipakai).
 * Bila ditolak, layar "Ketuk untuk mulai" tampil.
 */
const tryAutoUnlock = async () => {
    try {
        audioContext.value ??= new AudioContext();
        if (audioContext.value.state === 'suspended') await audioContext.value.resume().catch(() => {});
        if (audioContext.value.state === 'running') soundReady.value = true;
    } catch {
        /* perangkat tanpa audio */
    }
};

const tone = (freq: number, start: number, duration: number, volume: number) => {
    const ctx = audioContext.value;
    if (!ctx) return;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'triangle';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, ctx.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(volume, ctx.currentTime + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + duration);
    osc.connect(gain).connect(ctx.destination);
    osc.start(ctx.currentTime + start);
    osc.stop(ctx.currentTime + start + duration + 0.02);
};

/** Ding-dong-ding untuk pesanan baru. */
const playNewOrderSound = () => {
    if (!soundEnabled.value) return;
    if (!soundReady.value || !audioContext.value || audioContext.value.state !== 'running') {
        // Belum diizinkan browser: mainkan begitu layar disentuh.
        pendingChime = true;
        if (audioContext.value?.state === 'suspended') audioContext.value.resume().catch(() => {});
        if (audioContext.value?.state !== 'running') return;
    }
    tone(1318.5, 0, 0.45, 0.45);
    tone(1046.5, 0.32, 0.55, 0.45);
    tone(1318.5, 0.7, 0.6, 0.4);
    try { navigator.vibrate?.([200, 100, 200]); } catch { /* tidak didukung */ }
};

const toggleSound = () => {
    soundEnabled.value = !soundEnabled.value;
    writeStore('sound', soundEnabled.value);
    if (soundEnabled.value) {
        unlockNotificationSound();
        playNewOrderSound();
    }
};

// Pesanan baru yang belum "dilihat": disorot, bunyi diulang, judul tab berkedip.
// Pesanan yang masuk < 3 menit sebelum halaman dibuka (mis. halaman dimuat ulang setelah
// pembaruan aplikasi) tetap dianggap baru, agar tidak terlewat tanpa bunyi.
const RECENT_MS = 3 * 60 * 1000;
const isRecentWaiting = (e: Entry) => e.status === 'waiting' && !!e.created_at && Date.now() - new Date(e.created_at).getTime() < RECENT_MS;
const knownIds = new Set<number>([...props.entries, ...props.history].map((e) => e.id));
const unseenIds = ref<Set<number>>(new Set());
let repeatTimer: ReturnType<typeof setInterval> | null = null;
let repeatCount = 0;

const stopRepeat = () => {
    if (repeatTimer) clearInterval(repeatTimer);
    repeatTimer = null;
    repeatCount = 0;
};

const acknowledgeNewOrders = () => {
    if (unseenIds.value.size === 0) return;
    unseenIds.value = new Set();
    stopRepeat();
};

const alertNewOrders = (ids: number[]) => {
    unseenIds.value = new Set([...unseenIds.value, ...ids]);
    playNewOrderSound();
    stopRepeat();
    repeatTimer = setInterval(() => {
        const stillWaiting = entries.value.some((e) => unseenIds.value.has(e.id) && e.status === 'waiting');
        repeatCount += 1;
        if (!stillWaiting || repeatCount > 3) {
            stopRepeat();
            return;
        }
        playNewOrderSound();
    }, 20000);
};

/** Bandingkan daftar pesanan dengan yang sudah dikenal; bunyikan untuk yang baru. */
function noticeNewOrders(list: Entry[]) {
    const fresh = list.filter((e) => e.status === 'waiting' && !knownIds.has(e.id)).map((e) => e.id);
    list.forEach((e) => knownIds.add(e.id));
    if (fresh.length > 0) alertNewOrders(fresh);
}

// Jam berjalan untuk timer pesanan.
const now = ref(Date.now());
let clockId: ReturnType<typeof setInterval> | null = null;

// ── Sinkronisasi ──
const isWsConnected = ref(false);
const transportMode = ref<'idle' | 'ws' | 'polling'>('idle');
// Pesanan baru dari cek berkala dideteksi di watcher props.entries (noticeNewOrders).
const refresh = () => router.reload({ only: ['entries', 'history', 'summary'] });

const isToday = (iso?: string | null) => !!iso && new Date(iso).toDateString() === new Date().toDateString();

/** Terapkan perubahan satu pesanan ke papan & riwayat. */
const applyUpsert = (payload: Entry) => {
    const idx = entries.value.findIndex((e) => e.id === payload.id);
    const previous = idx >= 0 ? entries.value[idx] : history.value.find((h) => h.id === payload.id);
    const merged: Entry = { ...(previous ?? {}), ...payload } as Entry;

    if (merged.status === 'completed') {
        entries.value = entries.value.filter((e) => e.id !== merged.id);
        const wasInHistory = history.value.some((h) => h.id === merged.id);
        history.value = [merged, ...history.value.filter((h) => h.id !== merged.id)];
        if (!wasInHistory && isToday(merged.completed_at ?? new Date().toISOString())) completedToday.value += 1;
        return;
    }

    if (merged.status === 'cancelled') {
        applyDelete(merged.id);
        return;
    }

    // Kembali aktif (mis. dikembalikan dari riwayat).
    if (history.value.some((h) => h.id === merged.id)) {
        history.value = history.value.filter((h) => h.id !== merged.id);
        completedToday.value = Math.max(0, completedToday.value - 1);
    }

    const wasWaiting = previous?.status === 'waiting';
    if (idx >= 0) entries.value[idx] = merged;
    else entries.value.push(merged);

    if (!wasWaiting && merged.status === 'waiting' && !previous && !knownIds.has(merged.id)) {
        knownIds.add(merged.id);
        alertNewOrders([merged.id]);
    }
    knownIds.add(merged.id);
};

const applyDelete = (entryId: number) => {
    entries.value = entries.value.filter((e) => e.id !== entryId);
};

let echoUnsubscribe: (() => void) | null = null;
let pollId: ReturnType<typeof setInterval> | null = null;

const setupFallbackPolling = () => {
    if (pollId) return;
    transportMode.value = 'polling';
    pollId = setInterval(refresh, 5000);
};

const setupEcho = () => {
    const echo = (typeof window !== 'undefined' && window.Echo) || null;
    if (!echo) { setupFallbackPolling(); return; }

    const channelName = `kitchen-store.${props.store.id}`;
    const ch = echo.channel(channelName);

    const markConnected = () => { isWsConnected.value = true; transportMode.value = 'ws'; if (pollId) { clearInterval(pollId); pollId = null; } };
    const markDisconnected = () => {
        if (isWsConnected.value) {
            isWsConnected.value = false;
            setupFallbackPolling();
        }
    };

    const pusherChannel = (ch as unknown as { subscription?: { on?: (evt: string, cb: () => void) => void; pusher?: { connection?: { bind?: (evt: string, cb: () => void) => void } } } }).subscription;
    if (pusherChannel) {
        pusherChannel.on?.('pusher:subscription_succeeded', markConnected);
        pusherChannel.on?.('pusher:subscription_error', markDisconnected);
        pusherChannel.pusher?.connection?.bind?.('connected', markConnected);
        pusherChannel.pusher?.connection?.bind?.('disconnected', markDisconnected);
        pusherChannel.pusher?.connection?.bind?.('unavailable', markDisconnected);
    } else {
        setTimeout(markConnected, 300);
    }

    ch.listen('.kitchen.queue.updated', (payload: BroadcastPayload) => {
        if (!payload || !payload.entry) return;
        if (payload.action === 'delete') applyDelete(payload.entryId ?? payload.entry.id);
        else applyUpsert(payload.entry);
    });

    setTimeout(() => { if (!isWsConnected.value) setupFallbackPolling(); }, 2500);

    echoUnsubscribe = () => {
        try { echo.leave(channelName); } catch { /* noop */ }
    };
};

// ── Kolom papan ──
const columns = computed(() => [
    { key: 'waiting' as const, label: 'Antrian Baru', accent: 'border-t-amber-500', badge: 'bg-amber-100 text-amber-700', action: 'Mulai Buat', next: 'serving' as Status },
    { key: 'serving' as const, label: 'Sedang Dibuat', accent: 'border-t-blue-500', badge: 'bg-blue-100 text-blue-700', action: 'Siap', next: 'ready' as Status },
    { key: 'ready' as const, label: 'Siap Diserahkan', accent: 'border-t-emerald-500', badge: 'bg-emerald-100 text-emerald-700', action: 'Diserahkan', next: 'completed' as Status },
].map((column) => ({ ...column, entries: entries.value.filter((entry) => entry.status === column.key && stationItems(entry).length > 0) })));

const formatMinutes = (m: number | null) => (m === null ? '—' : m < 1 ? '< 1 mnt' : `${Math.round(m)} mnt`);

const stats = computed(() => [
    { label: 'Antrian Baru', value: String(entries.value.filter((e) => e.status === 'waiting').length), subtitle: 'menunggu dibuat', icon: ChefHat, color: 'text-amber-600', bg: 'bg-amber-100' },
    { label: 'Sedang Dibuat', value: String(entries.value.filter((e) => e.status === 'serving').length), subtitle: 'sedang diproses', icon: Utensils, color: 'text-blue-600', bg: 'bg-blue-100' },
    { label: 'Siap Diserahkan', value: String(entries.value.filter((e) => e.status === 'ready').length), subtitle: 'tunggu diambil', icon: Hand, color: 'text-emerald-600', bg: 'bg-emerald-100' },
    { label: 'Selesai Hari Ini', value: String(completedToday.value), subtitle: `rata-rata masak ${formatMinutes(avgPrep.value)}`, icon: CheckCheck, color: 'text-violet-600', bg: 'bg-violet-100' },
]);

// ── Yang harus dibuat: total item dari pesanan yang belum siap ──
const todo = computed(() => {
    const map = new Map<string, { name: string; qty: number }>();
    for (const entry of entries.value) {
        if (entry.status !== 'waiting' && entry.status !== 'serving') continue;
        for (const item of entry.items) {
            if (!itemInStation(item) || item.done) continue;
            const name = item.variant ? `${item.product_name} (${item.variant})` : item.product_name;
            const row = map.get(name) ?? { name, qty: 0 };
            row.qty += Number(item.quantity) || 0;
            map.set(name, row);
        }
    }
    return [...map.values()].sort((a, b) => b.qty - a.qty || a.name.localeCompare(b.name, 'id'));
});

// ── Waktu ──
const elapsedSeconds = (date: string | null | undefined) => (date ? Math.max(0, Math.floor((now.value - new Date(date).getTime()) / 1000)) : 0);
const elapsed = (date: string | null) => { const s = elapsedSeconds(date); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
/** Kuning setelah 10 menit, merah setelah 20 menit (pesanan yang belum siap). */
const lateClass = (entry: Entry) => {
    if (entry.status === 'ready') return 'text-slate-400';
    const minutes = elapsedSeconds(entry.created_at) / 60;
    if (minutes >= 20) return 'rounded bg-red-100 px-1.5 font-bold text-red-700';
    if (minutes >= 10) return 'rounded bg-amber-100 px-1.5 font-bold text-amber-700';
    return 'text-slate-400';
};
const isVeryLate = (entry: Entry) => entry.status !== 'ready' && elapsedSeconds(entry.created_at) >= 20 * 60;

// Pengingat bunyi sekali untuk pesanan yang lewat 20 menit dan belum siap.
const remindedIds = new Set<number>();
const playReminder = () => {
    if (!soundEnabled.value || !audioContext.value) return;
    tone(880, 0, 0.3, 0.35);
    tone(880, 0.4, 0.3, 0.35);
};
watch(now, () => {
    const late = entries.value.filter((e) => isVeryLate(e) && stationItems(e).length > 0 && !remindedIds.has(e.id));
    if (late.length > 0) {
        late.forEach((e) => remindedIds.add(e.id));
        playReminder();
    }
});

const clock = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '—');
const durationMinutes = (from?: string | null, to?: string | null) =>
    from && to ? Math.max(0, (new Date(to).getTime() - new Date(from).getTime()) / 60000) : null;
const orderTypeLabel: Record<string, string> = { dine_in: 'Makan di tempat', takeaway: 'Bungkus', delivery: 'Antar', online: 'Online' };

// ── Kembalikan (salah tekan) ──
const previousStatus: Partial<Record<Status, Status>> = { serving: 'waiting', ready: 'serving', completed: 'ready' };
const changedAt = (entry: Entry) =>
    entry.status === 'serving' ? entry.called_at : entry.status === 'ready' ? entry.ready_at : entry.status === 'completed' ? entry.completed_at : null;
const canUndo = (entry: Entry) => {
    if (!previousStatus[entry.status]) return false;
    const at = changedAt(entry);
    return !at || elapsedSeconds(at) < props.undoMinutes * 60;
};

// ── Cetak tiket dapur ──
const { isPrinting: isPrinterPrinting, initialize: initializePrinter, printEscPosBytes } = usePrinter();
const printEntry = async (entry: Entry) => {
    const lines = buildKotPlainLines({
        storeName: props.store.name,
        transactionNumber: entry.transaction_number || `#${entry.number}`,
        tableReference: entry.table_reference,
        orderType: entry.order_type ?? null,
        items: entry.items.map((item) => ({
            product_name: item.variant ? `${item.product_name} (${item.variant})` : item.product_name,
            quantity: item.quantity,
            modifiers: item.modifiers ?? null,
        })),
        paperWidthMm: 58,
    });
    const result = await printEscPosBytes(receiptLinesToEscPos(lines), { role: 'kitchen' });
    if (result.success) toastSuccess('KOT berhasil dicetak ke printer thermal.', 2000);
    else toastError(result.error || 'KOT gagal dicetak.');
};

initializePrinter();

// ── Ubah status ──
const busy = ref<Set<number>>(new Set());

const setStatus = async (entry: Entry, status: Status, undo = false) => {
    if (busy.value.has(entry.id)) return;
    busy.value = new Set([...busy.value, entry.id]);
    try {
        const response = await fetch(`/queue-entries/${entry.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ status }),
        });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Gagal memperbarui status');
        }

        const stamp = new Date().toISOString();
        const patch: Partial<Entry> = { status };
        if (undo) {
            if (entry.status === 'serving') patch.called_at = null;
            if (entry.status === 'ready') patch.ready_at = null;
            if (entry.status === 'completed') patch.completed_at = null;
        } else {
            if (status === 'serving') patch.called_at = stamp;
            if (status === 'ready') patch.ready_at = stamp;
            if (status === 'completed') patch.completed_at = stamp;
        }
        applyUpsert({ ...entry, ...patch } as Entry);

        const name = entry.table_reference ? `Meja ${entry.table_reference}` : `#${entry.number}`;
        toastSuccess(undo ? `${name} dikembalikan` : status === 'completed' ? `${name} diserahkan` : `${name} diperbarui`, 1500);
    } catch (e) {
        toastError(e instanceof Error ? e.message : 'Gagal memperbarui status');
        refresh();
    } finally {
        const next = new Set(busy.value);
        next.delete(entry.id);
        busy.value = next;
    }
};

/** Centang/buka satu item. Semua item tercentang → pesanan otomatis Siap. */
const toggleItem = async (entry: Entry, index: number) => {
    if (busy.value.has(entry.id)) return;
    const item = entry.items[index];
    if (!item) return;
    busy.value = new Set([...busy.value, entry.id]);
    const done = !item.done;
    // Tampilkan langsung, lalu sesuaikan dengan jawaban server.
    entry.items[index] = { ...item, done };
    try {
        const response = await fetch(`/kitchen/entries/${entry.id}/items/${index}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ done }),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.message || 'Gagal mencentang item');
        if (body.entry) applyUpsert(body.entry as Entry);
    } catch (e) {
        entry.items[index] = { ...item };
        toastError(e instanceof Error ? e.message : 'Gagal mencentang item');
    } finally {
        const next = new Set(busy.value);
        next.delete(entry.id);
        busy.value = next;
    }
};
const doneCount = (entry: Entry) => entry.items.filter((i) => i.done).length;

const undoStatus = (entry: Entry) => {
    const back = previousStatus[entry.status];
    if (back) setStatus(entry, back, true);
};

onMounted(() => {
    window.addEventListener('pointerdown', unlockNotificationSound, { once: true });
    tryAutoUnlock().then(() => {
        const recent = entries.value.filter(isRecentWaiting).map((e) => e.id);
        if (recent.length > 0) alertNewOrders(recent);
    });
    // Sentuhan apa pun di layar = pesanan baru sudah dilihat.
    window.addEventListener('pointerdown', acknowledgeNewOrders);
    clockId = setInterval(() => (now.value = Date.now()), 1000);
    document.addEventListener('visibilitychange', onVisibility);
    requestWakeLock();
    setupEcho();
});
onUnmounted(() => {
    window.removeEventListener('pointerdown', unlockNotificationSound);
    window.removeEventListener('pointerdown', acknowledgeNewOrders);
    stopRepeat();
    if (pollId) clearInterval(pollId);
    if (clockId) clearInterval(clockId);
    if (echoUnsubscribe) echoUnsubscribe();
    document.removeEventListener('visibilitychange', onVisibility);
    releaseWakeLock();
    audioContext.value?.close();
});
</script>

<template>
    <AdminLayout title="Papan Dapur" :fullscreen="kiosk"><Head :title="unseenIds.size > 0 ? `(${unseenIds.size}) Pesanan baru` : 'Papan Dapur'" />
        <div class="flex flex-col pb-4" :class="kiosk ? 'h-screen' : 'h-[calc(100vh-4rem)]'">
            <!-- Modul Antrian Dapur mati: pesanan kasir & scan meja tidak akan dikirim ke papan ini -->
            <div v-if="!kitchenModuleOn" class="mx-4 mt-4 shrink-0 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <b>Antrian Dapur belum aktif.</b> Pesanan dari kasir dan scan meja tidak akan masuk ke papan ini. Minta owner/admin
                mengaktifkan <b>Antrian Dapur</b> di Pengaturan → Toko → Modul Fitur.
            </div>
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-black text-slate-900">Papan Dapur</h1>
                    <span v-if="transportMode === 'ws'" class="flex items-center gap-1.5 text-xs font-bold text-emerald-600">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
                        <Wifi class="h-3 w-3" /> Live
                    </span>
                    <span v-else-if="transportMode === 'polling'" class="flex items-center gap-1.5 text-xs font-bold text-amber-600">
                        <span class="h-2 w-2 rounded-full bg-amber-400" />
                        <WifiOff class="h-3 w-3" /> Cek tiap 5 dtk
                    </span>
                    <span v-else class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-300" /> Menghubungkan...
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                            @click="showStationPicker = !showStationPicker"
                        >
                            <Layers class="h-4 w-4 text-slate-500" /> Stasiun: {{ stationLabel }}
                        </button>
                        <div
                            v-if="showStationPicker"
                            class="absolute right-0 z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-lg"
                        >
                            <p class="px-2 pb-1 text-xs text-slate-500">Tampilkan kategori ini saja di perangkat ini (mis. tablet bar = minuman).</p>
                            <button type="button" class="w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50" @click="clearStation">
                                <span :class="station.length === 0 ? 'font-bold text-slate-900' : 'text-slate-600'">Semua kategori</span>
                            </button>
                            <label
                                v-for="c in [...props.categories, { id: 0, name: 'Tanpa kategori' }]"
                                :key="c.id"
                                class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50"
                            >
                                <input type="checkbox" :checked="station.includes(c.id)" class="h-4 w-4 rounded" @change="toggleStation(c.id)" />
                                {{ c.name }}
                            </label>
                            <button type="button" class="mt-1 w-full rounded-lg bg-slate-900 px-2 py-1.5 text-sm font-semibold text-white" @click="showStationPicker = false">
                                Selesai
                            </button>
                        </div>
                    </div>
                    <div class="flex rounded-xl border border-slate-200 bg-white p-1 text-sm font-semibold">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5"
                            :class="tab === 'active' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                            @click="tab = 'active'"
                        >
                            Berjalan ({{ entries.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5"
                            :class="tab === 'done' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                            @click="tab = 'done'"
                        >
                            Selesai hari ini ({{ completedToday }})
                        </button>
                    </div>
                    <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="refresh">
                        <RefreshCw class="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1.5 rounded-xl"
                        :class="!soundEnabled ? 'text-slate-400' : !soundReady ? 'border-amber-300 bg-amber-50 text-amber-800' : ''"
                        :title="!soundEnabled ? 'Nyalakan suara pesanan masuk' : !soundReady ? 'Sentuh layar sekali agar suara aktif' : 'Matikan suara pesanan masuk'"
                        @click="!soundReady && soundEnabled ? (unlockNotificationSound(), playNewOrderSound()) : toggleSound()"
                    >
                        <component :is="soundEnabled ? Volume2 : VolumeX" class="h-3.5 w-3.5" />
                        {{ !soundEnabled ? 'Suara mati' : !soundReady ? 'Aktifkan suara' : 'Suara nyala' }}
                    </Button>
                    <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="toggleKiosk">
                        <component :is="kiosk ? Minimize2 : Maximize2" class="h-3.5 w-3.5" />
                        {{ kiosk ? 'Keluar mode layar' : 'Mode layar dapur' }}
                    </Button>
                </div>
            </div>

            <div
                v-if="unseenIds.size > 0"
                class="mx-4 mt-3 shrink-0 animate-pulse rounded-xl bg-amber-500 px-4 py-2.5 text-base font-black text-white"
            >
                🔔 {{ unseenIds.size }} pesanan baru masuk — ketuk layar untuk menandai sudah dilihat
            </div>

            <div class="mt-3 grid shrink-0 grid-cols-2 gap-3 px-4 md:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3">
                    <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', stat.bg]">
                        <component :is="stat.icon" :class="['h-5 w-5', stat.color]" />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-[11px] font-bold text-slate-500">{{ stat.label }}</p>
                        <p :class="['text-xl font-black tabular-nums', stat.color]">{{ stat.value }}</p>
                        <p class="truncate text-[10px] text-slate-400">{{ stat.subtitle }}</p>
                    </div>
                </div>
            </div>

            <!-- ── Berjalan ── -->
            <template v-if="tab === 'active'">
                <!-- Yang harus dibuat -->
                <div v-if="todo.length > 0" class="mx-4 mt-3 shrink-0 rounded-2xl border border-slate-200 bg-white">
                    <button type="button" class="flex w-full items-center justify-between px-4 py-2.5" @click="showTodo = !showTodo">
                        <span class="flex items-center gap-2 text-sm font-bold text-slate-800">
                            <ListChecks class="h-4 w-4 text-slate-500" /> Yang harus dibuat
                            <span class="font-normal text-slate-400">· dari pesanan yang belum siap</span>
                        </span>
                        <component :is="showTodo ? ChevronUp : ChevronDown" class="h-4 w-4 text-slate-400" />
                    </button>
                    <div v-if="showTodo" class="flex max-h-28 flex-wrap gap-2 overflow-y-auto px-4 pb-3">
                        <span v-for="t in todo" :key="t.name" class="rounded-lg bg-slate-100 px-2.5 py-1 text-sm text-slate-700">
                            <b class="text-slate-900">{{ t.qty }}×</b> {{ t.name }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 min-h-0 flex-1 overflow-x-auto px-4">
                    <div class="flex h-full min-w-max gap-3">
                        <div v-for="column in columns" :key="column.key" :class="['flex min-w-[270px] max-w-[360px] flex-1 flex-col rounded-2xl border-t-[3px] bg-slate-50/80', column.accent]">
                            <div class="flex items-center gap-2 px-4 py-3">
                                <span class="text-sm font-bold text-slate-700">{{ column.label }}</span>
                                <span :class="['rounded-full px-2 py-0.5 text-[11px] font-black', column.badge]">{{ column.entries.length }}</span>
                            </div>
                            <div class="flex-1 space-y-2 overflow-y-auto px-3 pb-3">
                                <div v-if="!column.entries.length" class="py-8 text-center text-xs text-slate-400">Kosong</div>
                                <div
                                    v-for="entry in column.entries"
                                    :key="entry.id"
                                    class="overflow-hidden rounded-xl border bg-white shadow-sm transition"
                                    :class="[
                                        busy.has(entry.id) ? 'opacity-60' : '',
                                        isVeryLate(entry) ? 'border-red-300 ring-2 ring-red-200' : unseenIds.has(entry.id) ? 'border-amber-400 ring-2 ring-amber-300' : 'border-slate-200',
                                    ]"
                                >
                                    <!-- Ketuk kartu untuk lanjut ke status berikutnya -->
                                    <button
                                        type="button"
                                        class="block w-full px-4 pb-2 pt-3 text-left hover:bg-slate-50 active:bg-slate-100"
                                        :disabled="busy.has(entry.id)"
                                        @click="setStatus(entry, column.next)"
                                    >
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <p class="font-mono text-xs text-slate-400">
                                                    #{{ entry.number }}
                                                    <span v-if="unseenIds.has(entry.id)" class="ml-1 rounded bg-amber-500 px-1.5 py-0.5 font-sans text-[10px] font-black text-white">BARU</span>
                                                    <span v-if="entry.order_type && orderTypeLabel[entry.order_type]" class="ml-1 font-sans font-semibold text-slate-500">· {{ orderTypeLabel[entry.order_type] }}</span>
                                                </p>
                                                <p class="text-base font-bold text-slate-900">{{ entry.table_reference ? `Meja ${entry.table_reference}` : entry.transaction_number }}</p>
                                            </div>
                                            <span class="text-right">
                                                <span class="block font-mono text-xs" :class="lateClass(entry)">{{ elapsed(entry.created_at) }}</span>
                                                <span v-if="doneCount(entry) > 0" class="text-[11px] font-semibold text-emerald-600">{{ doneCount(entry) }}/{{ entry.items.length }} item</span>
                                            </span>
                                        </div>
                                        <div class="mt-1.5 space-y-1">
                                            <div
                                                v-for="{ item, index } in stationItems(entry)"
                                                :key="index"
                                                :class="kiosk ? 'text-base' : 'text-sm'"
                                                @click.stop="toggleItem(entry, index)"
                                            >
                                                <div class="flex items-start gap-1.5 rounded-md px-1 py-0.5 hover:bg-slate-100">
                                                    <span
                                                        class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                                                        :class="item.done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 bg-white'"
                                                    >
                                                        <Check v-if="item.done" class="h-3 w-3" />
                                                    </span>
                                                    <b :class="item.done ? 'text-slate-400' : ''">{{ item.quantity }}×</b>
                                                    <span :class="item.done ? 'text-slate-400 line-through' : 'text-slate-700'">
                                                        {{ item.product_name }}<span v-if="item.variant" class="font-semibold" :class="item.done ? '' : 'text-slate-900'"> ({{ item.variant }})</span>
                                                    </span>
                                                </div>
                                                <p
                                                    v-for="(m, mi) in item.modifiers ?? []"
                                                    :key="mi"
                                                    class="ml-5 text-xs font-semibold"
                                                    :class="m.label.startsWith('Catatan') ? 'text-amber-700' : 'text-blue-700'"
                                                >
                                                    + {{ m.label }}
                                                </p>
                                            </div>
                                            <p v-if="stationItems(entry).length < entry.items.length" class="text-[11px] italic text-slate-400">
                                                + {{ entry.items.length - stationItems(entry).length }} item di stasiun lain
                                            </p>
                                        </div>
                                        <p v-if="entry.notes" class="mt-2 rounded bg-yellow-50 p-2 text-xs text-yellow-800">{{ entry.notes }}</p>
                                    </button>
                                    <div class="flex border-t border-slate-100">
                                        <button
                                            v-if="canUndo(entry)"
                                            class="border-r border-slate-100 px-3 text-slate-500 hover:bg-slate-50"
                                            title="Kembalikan ke status sebelumnya"
                                            :disabled="busy.has(entry.id)"
                                            @click="undoStatus(entry)"
                                        >
                                            <Undo2 class="h-4 w-4" />
                                        </button>
                                        <button
                                            class="flex-1 px-4 py-3 text-left text-sm font-bold text-slate-800 hover:bg-slate-50"
                                            :disabled="busy.has(entry.id)"
                                            @click="setStatus(entry, column.next)"
                                        >
                                            {{ column.action }}
                                            <span class="float-right">›</span>
                                        </button>
                                        <button
                                            class="border-l border-slate-100 px-3 text-slate-500 hover:bg-slate-50"
                                            title="Cetak Ulang KOT"
                                            :disabled="isPrinterPrinting"
                                            @click="printEntry(entry)"
                                        >
                                            <Printer class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Selesai hari ini ── -->
            <div v-else class="mt-3 min-h-0 flex-1 overflow-y-auto px-4">
                <div v-if="history.length === 0" class="rounded-2xl border border-dashed border-slate-300 py-12 text-center text-sm text-slate-500">
                    Belum ada pesanan yang diserahkan hari ini.
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div
                        v-for="h in history"
                        :key="h.id"
                        class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0"
                    >
                        <div class="w-28 shrink-0">
                            <p class="font-mono text-xs text-slate-400">#{{ h.number }}</p>
                            <p class="text-sm font-bold text-slate-900">{{ h.table_reference ? `Meja ${h.table_reference}` : h.transaction_number }}</p>
                        </div>
                        <p class="min-w-0 flex-1 truncate text-sm text-slate-600">
                            <template v-for="(item, i) in h.items" :key="i">
                                <span v-if="i > 0">, </span><b>{{ item.quantity }}×</b> {{ item.product_name }}<span v-if="item.variant"> ({{ item.variant }})</span>
                            </template>
                        </p>
                        <div class="text-right text-xs text-slate-500">
                            <p>masuk {{ clock(h.created_at) }} · diserahkan {{ clock(h.completed_at) }}</p>
                            <p class="font-semibold text-slate-700">
                                masak {{ formatMinutes(durationMinutes(h.created_at, h.ready_at ?? h.completed_at)) }}
                            </p>
                        </div>
                        <button
                            v-if="canUndo(h)"
                            type="button"
                            class="flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                            :disabled="busy.has(h.id)"
                            @click="undoStatus(h)"
                        >
                            <Undo2 class="h-3.5 w-3.5" /> Kembalikan
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-center text-xs text-slate-400">
                    Pesanan yang salah diserahkan bisa dikembalikan dalam {{ props.undoMinutes }} menit.
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
