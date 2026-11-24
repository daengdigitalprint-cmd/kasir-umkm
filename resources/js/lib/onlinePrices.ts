/**
 * Harga toko online per aplikasi (GoFood/GrabFood/ShopeeFood).
 * Cermin dari App\Support\OnlinePrices di server — server tetap penentu harga akhir.
 */

export const ONLINE_PRICE_CHANNELS = [
    { value: 'gofood', label: 'GoFood' },
    { value: 'grabfood', label: 'GrabFood' },
    { value: 'shopeefood', label: 'ShopeeFood' },
] as const;

export type OnlinePriceChannel = (typeof ONLINE_PRICE_CHANNELS)[number]['value'];

/** Isian form: angka, atau kosong ('') = pakai harga toko. */
export type OnlinePriceForm = Record<OnlinePriceChannel, number | ''>;

type RawOnlinePrices = Partial<Record<string, number | string | null>> | unknown[] | null | undefined;

function positiveNumber(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
}

export function isOnlinePriceChannel(channel: string): channel is OnlinePriceChannel {
    return ONLINE_PRICE_CHANNELS.some((c) => c.value === channel);
}

/** Isian form dari data server (kolom JSON `online_prices`; kosong bisa berupa [] atau null). */
export function onlinePricesToForm(raw: RawOnlinePrices): OnlinePriceForm {
    const source = raw && !Array.isArray(raw) ? raw : {};
    const form = {} as OnlinePriceForm;
    for (const { value } of ONLINE_PRICE_CHANNELS) {
        form[value] = positiveNumber(source[value]) ?? '';
    }
    return form;
}

/** Payload kirim: kanal kosong dikirim null supaya server menghapus harga online-nya. */
export function onlinePricesToPayload(form: OnlinePriceForm): Record<OnlinePriceChannel, number | null> {
    const payload = {} as Record<OnlinePriceChannel, number | null>;
    for (const { value } of ONLINE_PRICE_CHANNELS) {
        payload[value] = positiveNumber(form[value]);
    }
    return payload;
}

interface PricedVariant {
    price?: number | string | null;
    online_prices?: RawOnlinePrices;
}

interface PricedProduct {
    online_prices?: RawOnlinePrices;
}

function channelPrice(raw: RawOnlinePrices, channel: OnlinePriceChannel): number | null {
    if (!raw || Array.isArray(raw)) return null;
    return positiveNumber(raw[channel]);
}

/**
 * Harga online yang berlaku untuk satu baris, atau null bila baris itu memakai harga toko.
 * Varian dengan harga sendiri tidak mewarisi harga online induk (lihat OnlinePrices::resolve).
 */
export function resolveOnlinePrice(product: PricedProduct, variant: PricedVariant | null, channel: string): number | null {
    if (!isOnlinePriceChannel(channel)) return null;

    if (variant) {
        const own = channelPrice(variant.online_prices, channel);
        if (own !== null) return own;
        if (variant.price !== null && variant.price !== undefined && variant.price !== '') return null;
    }

    return channelPrice(product.online_prices, channel);
}
