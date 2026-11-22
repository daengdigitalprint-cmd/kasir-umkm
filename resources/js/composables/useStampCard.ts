import { waNumber } from '@/composables/useReceivables';

/** Data kartu stempel pelanggan dari server (StampCardService::cardPayload). */
export interface StampCardCustomer {
    id: number;
    name: string;
    phone: string | null;
    card_url: string;
    added: number;
    count: number;
    target: number;
    rewards_ready: number;
    new_rewards: number;
    reward: string;
}

/** Ringkasan stempel transaksi (payment_details.stamp_card) untuk struk & notifikasi. */
export interface StampReceiptSummary {
    added: number;
    count: number;
    target: number;
    rewards_ready: number;
    new_rewards: number;
    reward: string;
}

/** Tautan WhatsApp berisi link kartu stempel (dibuka di WA, dikirim sendiri oleh kasir). */
export function stampCardShareLink(customer: Pick<StampCardCustomer, 'name' | 'phone' | 'card_url' | 'count' | 'target' | 'rewards_ready' | 'reward'>, storeName: string): string | null {
    const number = waNumber(customer.phone);
    if (!number) return null;
    const firstName = customer.name.trim().split(/\s+/)[0] ?? '';
    const status =
        customer.rewards_ready > 0
            ? `Kamu punya ${customer.rewards_ready} hadiah siap ditukar (${customer.reward}).`
            : `Stempel kamu sekarang ${customer.count} dari ${customer.target}. Kurang ${Math.max(0, customer.target - customer.count)} lagi untuk dapat ${customer.reward}.`;
    const message = `Halo ${firstName}, ini kartu stempel kamu di ${storeName}. ${status}\n\nBuka kartunya di sini: ${customer.card_url}`;
    return `https://wa.me/${number}?text=${encodeURIComponent(message)}`;
}
