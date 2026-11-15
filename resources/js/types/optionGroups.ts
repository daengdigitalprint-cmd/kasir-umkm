export interface OptionGroupOption {
    key: string;
    label: string;
    price: number;
}

/** Grup pilihan tambahan (gula, topping, …) yang ditempel ke produk. */
export interface OptionGroup {
    id: number;
    name: string;
    selection: 'single' | 'multiple';
    is_required: boolean;
    options: OptionGroupOption[];
    products_count?: number | null;
}
