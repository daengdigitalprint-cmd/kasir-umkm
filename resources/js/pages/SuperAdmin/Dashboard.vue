<template>
    <SuperAdminLayout title="Super Admin Dashboard" :active-tab="activeTab" :pending-payments-count="pendingCount">
        <!-- ===================== TOKO (RINGKASAN) ===================== -->
        <section v-if="activeTab === 'stores'" class="space-y-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Ringkasan & Toko</h1>
                <p class="text-sm text-slate-500">Pantau status langganan seluruh toko dan kelola masa aktifnya.</p>
            </header>

            <!-- KPI -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total toko</p>
                        <Building2 class="h-4 w-4 text-slate-400" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ formatCurrency(safeStats.total_stores) }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">terdaftar</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Toko aktif</p>
                        <CheckCircle class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ formatCurrency(safeStats.active_stores) }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ activeRatio }}% dari total</p>
                </div>
                <div :class="['rounded-xl border bg-white p-4', safeStats.expired_stores > 0 ? 'border-red-200' : 'border-slate-200']">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Langganan habis</p>
                        <XCircle class="h-4 w-4 text-red-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ formatCurrency(safeStats.expired_stores) }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">paket berbayar</p>
                </div>
                <div :class="['rounded-xl border bg-white p-4', safeStats.expiring_soon > 0 ? 'border-amber-200' : 'border-slate-200']">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Habis ≤ 7 hari</p>
                        <AlertTriangle class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ formatCurrency(safeStats.expiring_soon) }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">perlu diingatkan</p>
                </div>
                <Link
                    href="/superadmin?tab=payments"
                    :class="[
                        'rounded-xl border bg-white p-4 transition hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                        safeStats.pending_payments > 0 ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200',
                    ]"
                >
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Menunggu konfirmasi</p>
                        <Clock class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ formatCurrency(safeStats.pending_payments) }}</p>
                    <p class="mt-0.5 text-xs font-medium text-primary">Lihat pembayaran →</p>
                </Link>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">Total pendapatan</p>
                        <DollarSign class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums" :title="`Rp ${formatCurrency(safeStats.total_revenue)}`">
                        Rp {{ formatCurrencyShort(safeStats.total_revenue) }}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-400">pembayaran terkonfirmasi</p>
                </div>
            </div>

            <!-- Daftar toko -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Daftar toko</h2>
                        <p class="text-xs text-slate-500">Diurutkan dari yang terbaru</p>
                    </div>
                    <p v-if="storesRange" class="text-xs text-slate-500">{{ storesRange }}</p>
                </div>

                <div v-if="safeStores.data.length === 0" class="px-5 py-16 text-center">
                    <Building2 class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada toko terdaftar.</p>
                </div>

                <!-- Tabel (desktop) -->
                <div v-else class="hidden md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-medium tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3">Toko</th>
                                <th scope="col" class="px-3 py-3">Paket</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-3 py-3">Masa aktif</th>
                                <th scope="col" class="px-3 py-3">Terdaftar</th>
                                <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="store in safeStores.data" :key="store.id" class="align-middle hover:bg-slate-50/60">
                                <td class="max-w-[260px] px-5 py-3.5">
                                    <p class="truncate font-semibold text-slate-900">{{ store.name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ store.address || 'Alamat belum diatur' }}</p>
                                    <div v-if="store.owner" class="mt-1 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                        <span class="truncate" :title="store.owner.email">{{ store.owner.name }}</span>
                                        <a
                                            v-if="waLink(store.owner.phone)"
                                            :href="waLink(store.owner.phone)!"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 ring-1 ring-emerald-200 ring-inset hover:bg-emerald-100"
                                            :aria-label="`Chat WhatsApp ${store.owner.name}`"
                                        >
                                            <MessageCircle class="h-3 w-3" />
                                            {{ waDisplay(store.owner.phone) }}
                                        </a>
                                        <span v-else class="shrink-0 text-slate-400">· WA belum ada</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3.5">
                                    <span class="text-sm whitespace-nowrap text-slate-700">{{ planLabel(store) }}</span>
                                </td>
                                <td class="px-3 py-3.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span :class="statusPillClass(store)">
                                            <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
                                            {{ getStoreStatus(store) }}
                                        </span>
                                        <span
                                            v-if="store.pro_trial_active"
                                            class="inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-200 ring-inset"
                                            :title="store.pro_trial_end ? `Trial berakhir ${formatDate(store.pro_trial_end)}` : undefined"
                                        >
                                            Trial Pro
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-3.5">
                                    <template v-if="store.subscription_end && !isStarterFreeStore(store)">
                                        <p class="text-sm whitespace-nowrap text-slate-700">{{ formatDate(store.subscription_end) }}</p>
                                        <p :class="['text-xs', getDaysRemainingClass(store.subscription_end)]">
                                            {{ remainingLabel(store.subscription_end) }}
                                        </p>
                                    </template>
                                    <span v-else class="text-sm text-slate-400">Tanpa batas</span>
                                </td>
                                <td class="px-3 py-3.5 text-sm whitespace-nowrap text-slate-500">{{ formatDate(store.created_at) }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-8 rounded-lg border-slate-200 text-xs font-semibold"
                                            @click="openExtendModal(store)"
                                        >
                                            <Plus class="mr-1 h-3.5 w-3.5" />
                                            Perpanjang
                                        </Button>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    class="h-8 w-8 rounded-lg p-0 text-slate-500"
                                                    :aria-label="`Aksi lain untuk ${store.name}`"
                                                >
                                                    <MoreHorizontal class="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" class="w-56 rounded-xl">
                                                <DropdownMenuLabel class="truncate text-xs font-normal text-slate-500">{{
                                                    store.name
                                                }}</DropdownMenuLabel>
                                                <DropdownMenuItem class="cursor-pointer" @select="openReduceModal(store)">
                                                    <Minus class="mr-2 h-4 w-4" /> Kurangi masa aktif
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="isStarterFreeStore(store) && !store.pro_trial_used && store.is_active"
                                                    class="cursor-pointer"
                                                    @select="openTrialModal(store)"
                                                >
                                                    <FlaskConical class="mr-2 h-4 w-4" /> Aktifkan Trial Pro
                                                </DropdownMenuItem>
                                                <DropdownMenuItem v-if="store.pro_trial_active" class="cursor-pointer" @select="stopTrial(store)">
                                                    <Square class="mr-2 h-4 w-4" /> Hentikan trial
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem class="cursor-pointer" @select="toggleStoreStatus(store)">
                                                    <Power class="mr-2 h-4 w-4" /> {{ store.is_active ? 'Nonaktifkan toko' : 'Aktifkan toko' }}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    class="cursor-pointer text-red-600 focus:bg-red-50 focus:text-red-700"
                                                    @select="deleteStore(store)"
                                                >
                                                    <Trash2 class="mr-2 h-4 w-4" /> Hapus toko permanen
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Kartu (mobile) -->
                <ul v-if="safeStores.data.length" class="divide-y divide-slate-100 md:hidden">
                    <li v-for="store in safeStores.data" :key="store.id" class="px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900">{{ store.name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ store.address || 'Alamat belum diatur' }}</p>
                                <div v-if="store.owner" class="mt-1 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                    <span class="truncate" :title="store.owner.email">{{ store.owner.name }}</span>
                                    <a
                                        v-if="waLink(store.owner.phone)"
                                        :href="waLink(store.owner.phone)!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 ring-1 ring-emerald-200 ring-inset hover:bg-emerald-100"
                                        :aria-label="`Chat WhatsApp ${store.owner.name}`"
                                    >
                                        <MessageCircle class="h-3 w-3" />
                                        {{ waDisplay(store.owner.phone) }}
                                    </a>
                                    <span v-else class="shrink-0 text-slate-400">· WA belum ada</span>
                                </div>
                            </div>
                            <span :class="statusPillClass(store)">
                                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
                                {{ getStoreStatus(store) }}
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div>
                                <dt class="text-slate-400">Paket</dt>
                                <dd class="font-medium text-slate-700">
                                    {{ planLabel(store) }}<span v-if="store.pro_trial_active" class="ml-1 text-violet-700">· Trial Pro</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">Masa aktif</dt>
                                <dd v-if="store.subscription_end && !isStarterFreeStore(store)" class="font-medium text-slate-700">
                                    {{ formatDate(store.subscription_end) }}
                                    <span :class="['block', getDaysRemainingClass(store.subscription_end)]">{{
                                        remainingLabel(store.subscription_end)
                                    }}</span>
                                </dd>
                                <dd v-else class="font-medium text-slate-400">Tanpa batas</dd>
                            </div>
                        </dl>
                        <div class="mt-3 flex items-center gap-2">
                            <Button size="sm" variant="outline" class="h-9 flex-1 rounded-lg text-xs font-semibold" @click="openExtendModal(store)">
                                <Plus class="mr-1 h-3.5 w-3.5" /> Perpanjang
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-9 rounded-lg px-3 text-xs font-semibold"
                                        :aria-label="`Aksi lain untuk ${store.name}`"
                                    >
                                        Lainnya
                                        <ChevronDown class="ml-1 h-3.5 w-3.5" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-56 rounded-xl">
                                    <DropdownMenuItem class="cursor-pointer" @select="openReduceModal(store)">
                                        <Minus class="mr-2 h-4 w-4" /> Kurangi masa aktif
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="isStarterFreeStore(store) && !store.pro_trial_used && store.is_active"
                                        class="cursor-pointer"
                                        @select="openTrialModal(store)"
                                    >
                                        <FlaskConical class="mr-2 h-4 w-4" /> Aktifkan Trial Pro
                                    </DropdownMenuItem>
                                    <DropdownMenuItem v-if="store.pro_trial_active" class="cursor-pointer" @select="stopTrial(store)">
                                        <Square class="mr-2 h-4 w-4" /> Hentikan trial
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem class="cursor-pointer" @select="toggleStoreStatus(store)">
                                        <Power class="mr-2 h-4 w-4" /> {{ store.is_active ? 'Nonaktifkan toko' : 'Aktifkan toko' }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="cursor-pointer text-red-600 focus:bg-red-50 focus:text-red-700"
                                        @select="deleteStore(store)"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" /> Hapus toko permanen
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </li>
                </ul>

                <!-- Pagination -->
                <div
                    v-if="safeStores.links && safeStores.links.length > 3"
                    class="flex flex-col items-center gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:justify-between"
                >
                    <p v-if="storesRange" class="text-xs text-slate-500">{{ storesRange }}</p>
                    <nav class="flex flex-wrap items-center justify-center gap-1" aria-label="Navigasi halaman toko">
                        <Link
                            v-for="link in safeStores.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :aria-current="link.active ? 'page' : undefined"
                            :class="[
                                'min-w-8 rounded-lg px-2.5 py-1.5 text-center text-sm font-medium transition-colors',
                                link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
                                !link.url ? 'pointer-events-none opacity-40' : '',
                            ]"
                        >
                            <span v-html="paginationLabel(link.label)" />
                        </Link>
                    </nav>
                </div>
            </div>
        </section>

        <!-- ===================== PEMBAYARAN ===================== -->
        <section v-if="activeTab === 'payments'" class="space-y-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Pembayaran</h1>
                <p class="text-sm text-slate-500">Konfirmasi atau tolak pembayaran langganan dari pemilik toko.</p>
            </header>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Transaksi terbaru</h2>
                        <p class="text-xs text-slate-500">{{ safePayments.length }} pembayaran terakhir</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter status pembayaran">
                        <button
                            v-for="f in paymentFilters"
                            :key="f.value"
                            type="button"
                            role="tab"
                            :aria-selected="paymentFilter === f.value"
                            :class="[
                                'rounded-full px-3 py-1 text-xs font-semibold transition-colors',
                                paymentFilter === f.value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
                            ]"
                            @click="paymentFilter = f.value"
                        >
                            {{ f.label }}
                            <span class="ml-1 tabular-nums opacity-70">{{ f.count }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="filteredPayments.length === 0" class="px-5 py-16 text-center">
                    <CreditCard class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">
                        {{ safePayments.length === 0 ? 'Belum ada pembayaran.' : 'Tidak ada pembayaran dengan status ini.' }}
                    </p>
                </div>

                <ul v-else class="divide-y divide-slate-100">
                    <li
                        v-for="payment in filteredPayments"
                        :key="payment.id"
                        :class="['px-5 py-4', payment.status === 'pending' ? 'bg-amber-50/40' : '']"
                    >
                        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-slate-900">{{ payment.store?.name || '-' }}</p>
                                    <span :class="paymentPillClass(payment.status)">{{ getPaymentStatusLabel(payment.status) }}</span>
                                </div>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ payment.invoice_number }}</p>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                                    <span>{{ payment.plan?.name || 'Custom' }} · {{ payment.duration_days }} hari</span>
                                    <span>{{ getMethodLabel(payment.payment_method) }}</span>
                                    <span class="text-slate-400">{{ formatDateTime(payment.created_at) }}</span>
                                </div>
                                <div
                                    v-if="payment.type === 'outlet_addon' || payment.type === 'attendance_payroll_addon'"
                                    class="mt-2 flex flex-wrap gap-1.5"
                                >
                                    <span
                                        v-if="payment.type === 'outlet_addon'"
                                        class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-200 ring-inset"
                                    >
                                        Add-on +{{ payment.extra_outlets_count ?? 0 }} outlet
                                    </span>
                                    <span
                                        v-if="payment.type === 'attendance_payroll_addon'"
                                        class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 ring-inset"
                                    >
                                        Absensi + Payroll
                                    </span>
                                </div>
                                <p v-if="payment.notes" class="mt-2 text-xs text-slate-500 italic">“{{ payment.notes }}”</p>
                                <p v-if="payment.admin_notes" class="mt-1 text-xs text-slate-500">
                                    <span class="font-medium text-slate-600">Catatan admin:</span> {{ payment.admin_notes }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col gap-2 md:items-end">
                                <p class="text-lg font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(payment.amount) }}</p>
                                <a
                                    v-if="payment.payment_proof"
                                    :href="`/storage/${payment.payment_proof}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    <ExternalLink class="h-3.5 w-3.5" /> Lihat bukti bayar
                                </a>
                                <div v-if="payment.status === 'pending'" class="flex items-center gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="h-8 rounded-lg border-red-200 text-xs font-semibold text-red-600 hover:bg-red-50 hover:text-red-700"
                                        @click="openRejectModal(payment)"
                                    >
                                        Tolak
                                    </Button>
                                    <Button
                                        size="sm"
                                        class="h-8 rounded-lg bg-emerald-600 text-xs font-semibold text-white hover:bg-emerald-700"
                                        @click="confirmPayment(payment)"
                                    >
                                        <CheckCircle class="mr-1 h-3.5 w-3.5" /> Konfirmasi
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ===================== REKENING & QRIS ===================== -->
        <section v-if="activeTab === 'transfer'" class="space-y-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Rekening & QRIS</h1>
                <p class="text-sm text-slate-500">
                    Ditampilkan kepada pemilik toko di halaman checkout langganan
                    <code class="rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-600">/subscription/checkout</code>.
                </p>
            </header>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white lg:col-span-2">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <Landmark class="h-4 w-4 text-slate-400" /> Rekening tujuan transfer
                        </h2>
                    </div>
                    <div class="space-y-4 p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="ts-bank">Nama bank</Label>
                                <Input id="ts-bank" v-model="transferForm.bank_name" placeholder="contoh: BCA" class="rounded-lg" />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="ts-number">No. rekening</Label>
                                <Input
                                    id="ts-number"
                                    v-model="transferForm.account_number"
                                    placeholder="Nomor rekening"
                                    inputmode="numeric"
                                    class="rounded-lg font-mono"
                                />
                            </div>
                            <div class="space-y-1.5 sm:col-span-2">
                                <Label for="ts-holder">Atas nama</Label>
                                <Input id="ts-holder" v-model="transferForm.account_holder" placeholder="Nama pemilik rekening" class="rounded-lg" />
                            </div>
                        </div>

                        <div class="rounded-lg border border-dashed border-slate-300 p-4">
                            <p class="text-sm font-medium text-slate-800">Gambar QRIS</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                JPG, PNG, atau WebP · maks. 2 MB. Gunakan gambar yang jelas agar mudah dipindai.
                            </p>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/jpg"
                                aria-label="Unggah gambar QRIS"
                                class="mt-3 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800"
                                @change="onTransferQrisChange"
                            />
                            <p v-if="transferForm.qris_file" class="mt-2 text-xs font-medium text-emerald-700">
                                File terpilih: {{ transferForm.qris_file.name }}
                            </p>
                            <label v-if="safeTransferSettings.qris_url" class="mt-3 flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input v-model="transferForm.remove_qris" type="checkbox" class="rounded border-slate-300" />
                                Hapus QRIS yang ada (tanpa mengganti file baru)
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end border-t border-slate-100 px-5 py-3">
                        <Button
                            type="button"
                            class="rounded-lg bg-slate-900 px-5 font-semibold text-white hover:bg-slate-800"
                            :disabled="transferForm.processing"
                            @click="submitTransferSettings"
                        >
                            {{ transferForm.processing ? 'Menyimpan…' : 'Simpan pengaturan' }}
                        </Button>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-slate-900">QRIS aktif</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Pratinjau yang dilihat pemilik toko.</p>
                    <div class="mt-4 flex aspect-square items-center justify-center rounded-lg border border-slate-200 bg-slate-50">
                        <img
                            v-if="safeTransferSettings.qris_url && !qrisPreviewBroken"
                            :src="safeTransferSettings.qris_url"
                            alt="QRIS saat ini"
                            class="max-h-full max-w-full rounded-lg object-contain p-3"
                            @error="qrisPreviewBroken = true"
                        />
                        <div v-else class="px-6 text-center">
                            <QrCode class="mx-auto h-10 w-10 text-slate-300" />
                            <p class="mt-2 text-xs text-slate-500">
                                {{ safeTransferSettings.qris_url ? 'Gambar QRIS tidak dapat dimuat — unggah ulang file QRIS.' : 'Belum ada QRIS diunggah' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
                        <DollarSign class="h-4 w-4 text-slate-400" /> Harga add-on Absensi + Payroll
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500">Tampil di halaman langganan toko dan dipakai sebagai acuan saat checkout.</p>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="ap-monthly">Harga bulanan (Rp)</Label>
                        <Input
                            id="ap-monthly"
                            v-model.number="attendancePayrollPricingForm.monthly_price"
                            type="number"
                            min="0"
                            step="1000"
                            class="rounded-lg"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="ap-yearly">Harga tahunan (Rp)</Label>
                        <Input
                            id="ap-yearly"
                            v-model.number="attendancePayrollPricingForm.yearly_price"
                            type="number"
                            min="0"
                            step="1000"
                            class="rounded-lg"
                        />
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 px-5 py-3">
                    <Button
                        type="button"
                        class="rounded-lg bg-slate-900 px-5 font-semibold text-white hover:bg-slate-800"
                        :disabled="attendancePayrollPricingForm.processing"
                        @click="submitAttendancePayrollPricing"
                    >
                        {{ attendancePayrollPricingForm.processing ? 'Menyimpan…' : 'Simpan harga add-on' }}
                    </Button>
                </div>
            </div>
        </section>

        <!-- ===================== PAKET ===================== -->
        <section v-if="activeTab === 'plans'" class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Paket langganan</h1>
                    <p class="text-sm text-slate-500">Atur harga, durasi, dan batasan setiap paket.</p>
                </div>
                <Button class="rounded-lg bg-slate-900 font-semibold text-white hover:bg-slate-800" @click="openCreatePlanModal">
                    <Plus class="mr-1.5 h-4 w-4" /> Tambah paket
                </Button>
            </header>

            <div v-if="safePlans.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-16 text-center">
                <Crown class="mx-auto h-8 w-8 text-slate-300" />
                <p class="mt-3 text-sm text-slate-500">Belum ada paket langganan.</p>
                <Button class="mt-4 rounded-lg bg-slate-900 text-white" @click="openCreatePlanModal">
                    <Plus class="mr-1.5 h-4 w-4" /> Buat paket pertama
                </Button>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="plan in safePlans"
                    :key="plan.id"
                    :class="[
                        'relative flex flex-col rounded-xl border bg-white p-5',
                        plan.is_popular ? 'border-primary ring-1 ring-primary/30' : 'border-slate-200',
                    ]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <h3 class="font-semibold text-slate-900">{{ plan.name }}</h3>
                                <span
                                    v-if="plan.is_popular"
                                    class="rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold tracking-wide text-white uppercase"
                                    >Populer</span
                                >
                            </div>
                            <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ plan.description || 'Tanpa deskripsi' }}</p>
                        </div>
                        <span
                            :class="[
                                'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                plan.is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200',
                            ]"
                        >
                            {{ plan.is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </div>

                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-2xl font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(plan.price) }}</span>
                        <span class="text-xs text-slate-500">{{
                            plan.duration_days > 0 ? `/ ${plan.duration_days} hari` : '/ tanpa masa aktif'
                        }}</span>
                    </div>
                    <p class="mt-1 text-xs font-medium text-slate-500">
                        Penagihan {{ (plan.billing_cycle ?? 'monthly') === 'yearly' ? 'tahunan' : 'bulanan' }}
                    </p>

                    <dl class="mt-4 grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3 text-xs">
                        <div>
                            <dt class="text-slate-400">Maks. produk</dt>
                            <dd class="font-semibold text-slate-700">{{ plan.max_products || 'Tanpa batas' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Maks. pelanggan</dt>
                            <dd class="font-semibold text-slate-700">{{ plan.max_customers || 'Tanpa batas' }}</dd>
                        </div>
                    </dl>

                    <ul v-if="plan.features && plan.features.length" class="mt-4 space-y-1.5 text-xs text-slate-600">
                        <li v-for="feat in plan.features.slice(0, 3)" :key="feat" class="flex gap-2">
                            <Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />
                            <span>{{ feat }}</span>
                        </li>
                        <li v-if="plan.features.length > 3" class="pl-5 text-slate-400">+{{ plan.features.length - 3 }} fitur lainnya</li>
                    </ul>

                    <div class="mt-auto flex gap-2 pt-5">
                        <Button size="sm" variant="outline" class="h-9 flex-1 rounded-lg text-xs font-semibold" @click="openEditPlanModal(plan)">
                            <Edit class="mr-1.5 h-3.5 w-3.5" /> Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-9 rounded-lg border-slate-200 px-3 text-red-600 hover:bg-red-50 hover:text-red-700"
                            :aria-label="`Hapus paket ${plan.name}`"
                            @click="deletePlan(plan)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </article>
            </div>
        </section>

        <!-- ===================== VOUCHER ===================== -->
        <section v-if="activeTab === 'vouchers'" class="space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Voucher</h1>
                    <p class="text-sm text-slate-500">Kode yang bisa ditukar pemilik toko untuk menambah masa aktif.</p>
                </div>
                <Button class="rounded-lg bg-slate-900 font-semibold text-white hover:bg-slate-800" @click="openCreateVoucherModal">
                    <Plus class="mr-1.5 h-4 w-4" /> Buat voucher
                </Button>
            </header>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div v-if="safeVouchers.length === 0" class="px-5 py-16 text-center">
                    <Ticket class="mx-auto h-8 w-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Belum ada voucher.</p>
                </div>
                <ul v-else class="divide-y divide-slate-100">
                    <li
                        v-for="voucher in safeVouchers"
                        :key="voucher.id"
                        class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ voucher.name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ voucher.days }} hari · Rp {{ formatCurrency(voucher.price) }} · dipakai {{ voucher.used_count || 0 }}×
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-1.5 font-mono text-sm font-semibold text-slate-800 transition hover:border-slate-400"
                                :title="`Salin kode ${voucher.code}`"
                                @click="copyVoucherCode(voucher.code)"
                            >
                                {{ voucher.code }}
                                <Check v-if="copiedCode === voucher.code" class="h-3.5 w-3.5 text-emerald-600" />
                                <Copy v-else class="h-3.5 w-3.5 text-slate-400" />
                            </button>
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    voucher.is_active
                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                        : 'bg-slate-100 text-slate-500 ring-slate-200',
                                ]"
                            >
                                {{ voucher.is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ===================== LOG TRIAL ===================== -->
        <section v-if="activeTab === 'trial-logs'" class="space-y-6">
            <header class="flex flex-col gap-1">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Log trial Pro</h1>
                <p class="text-sm text-slate-500">Riwayat aktivasi, penghentian, dan kedaluwarsa trial Pro untuk semua toko.</p>
            </header>
            <div
                class="flex flex-col items-start gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-4">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50">
                        <History class="h-5 w-5 text-violet-600" />
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900">Riwayat lengkap tersedia di halaman tersendiri</p>
                        <p class="text-xs text-slate-500">Termasuk siapa yang mengaktifkan dan kapan trial berakhir.</p>
                    </div>
                </div>
                <Link href="/superadmin/trial-logs">
                    <Button class="rounded-lg bg-slate-900 font-semibold text-white hover:bg-slate-800">
                        Buka log trial <ArrowRight class="ml-1.5 h-4 w-4" />
                    </Button>
                </Link>
            </div>
        </section>

        <!-- ===================== MODALS ===================== -->

        <!-- Aktifkan Trial -->
        <div
            v-if="showTrialModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closeTrialModal"
        >
            <div role="dialog" aria-modal="true" aria-labelledby="dlg-trial-title" class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50">
                            <FlaskConical class="h-5 w-5 text-violet-600" />
                        </div>
                        <div>
                            <h3 id="dlg-trial-title" class="text-base font-semibold text-slate-900">Aktifkan Trial Pro</h3>
                            <p class="text-xs text-slate-500">{{ selectedTrialStore?.name }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closeTrialModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="space-y-4 p-5">
                    <p class="text-sm text-slate-600">
                        Toko akan mendapat akses <strong class="text-slate-900">7 hari</strong> paket
                        <strong class="text-violet-700">GrowUp (Pro)</strong>:
                    </p>
                    <ul class="space-y-1.5 rounded-lg bg-violet-50 p-4 text-sm text-violet-900">
                        <li class="flex gap-2"><Check class="mt-0.5 h-4 w-4 shrink-0" /> Akses fitur GrowUp tanpa batas selama 7 hari</li>
                        <li class="flex gap-2"><Check class="mt-0.5 h-4 w-4 shrink-0" /> Produk, pelanggan, transaksi, dan laporan unlimited</li>
                        <li class="flex gap-2"><Check class="mt-0.5 h-4 w-4 shrink-0" /> Multi-outlet, transfer stok, dan fitur premium lainnya</li>
                    </ul>
                    <p class="text-xs text-slate-500">Trial berakhir otomatis setelah 7 hari dan toko kembali ke paket Starter.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                    <Button type="button" variant="outline" class="rounded-lg" @click="closeTrialModal">Batal</Button>
                    <Button :disabled="trialForm.processing" class="rounded-lg bg-violet-600 text-white hover:bg-violet-700" @click="activateTrial">
                        {{ trialForm.processing ? 'Mengaktifkan...' : 'Ya, aktifkan trial' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Perpanjang -->
        <div
            v-if="showExtendModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closeExtendModal"
        >
            <div role="dialog" aria-modal="true" aria-labelledby="dlg-extend-title" class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <div>
                        <h3 id="dlg-extend-title" class="text-base font-semibold text-slate-900">Perpanjang masa aktif</h3>
                        <p class="text-xs text-slate-500">{{ selectedStore?.name }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closeExtendModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <form @submit.prevent="extendSubscription">
                    <div class="space-y-4 p-5">
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Durasi cepat</Label>
                            <div class="mt-1.5 grid grid-cols-5 gap-2">
                                <button
                                    v-for="m in extendMonthPresets"
                                    :key="m"
                                    type="button"
                                    :class="[
                                        'rounded-lg border px-2 py-2 text-sm font-medium transition',
                                        extendForm.days === m * 30
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-400',
                                    ]"
                                    @click="setExtendMonths(m)"
                                >
                                    {{ m }} bln
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="months" class="text-sm font-medium text-slate-700">Bulan (custom)</Label>
                                <Input
                                    id="months"
                                    v-model.number="extendCustomMonths"
                                    type="number"
                                    min="1"
                                    max="12"
                                    placeholder="mis. 5"
                                    class="mt-1.5 rounded-lg"
                                    @input="setExtendMonths(extendCustomMonths)"
                                />
                            </div>
                            <div>
                                <Label for="days" class="text-sm font-medium text-slate-700">Jumlah hari</Label>
                                <Input id="days" v-model="extendForm.days" type="number" min="1" max="365" required class="mt-1.5 rounded-lg" />
                            </div>
                        </div>
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Total: <strong class="text-slate-900">{{ extendForm.days }} hari</strong> ≈
                            {{ (extendForm.days / 30).toFixed(extendForm.days % 30 === 0 ? 0 : 1) }} bulan
                        </p>
                        <div>
                            <Label for="notes" class="text-sm font-medium text-slate-700"
                                >Catatan <span class="font-normal text-slate-400">(opsional)</span></Label
                            >
                            <textarea
                                id="notes"
                                v-model="extendForm.notes"
                                class="mt-1.5 w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                                rows="3"
                            ></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                        <Button type="button" variant="outline" class="rounded-lg" @click="closeExtendModal">Batal</Button>
                        <Button type="submit" :disabled="extendForm.processing" class="rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                            {{ extendForm.processing ? 'Memproses...' : 'Perpanjang' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Kurangi -->
        <div
            v-if="showReduceModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closeReduceModal"
        >
            <div role="dialog" aria-modal="true" aria-labelledby="dlg-reduce-title" class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <div>
                        <h3 id="dlg-reduce-title" class="text-base font-semibold text-slate-900">Kurangi masa aktif</h3>
                        <p class="text-xs text-slate-500">{{ selectedReduceStore?.name }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closeReduceModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <form @submit.prevent="reduceSubscription">
                    <div class="space-y-4 p-5">
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Durasi cepat</Label>
                            <div class="mt-1.5 grid grid-cols-5 gap-2">
                                <button
                                    v-for="m in reduceMonthPresets"
                                    :key="m"
                                    type="button"
                                    :class="[
                                        'rounded-lg border px-2 py-2 text-sm font-medium transition',
                                        reduceForm.days === m * 30
                                            ? 'border-orange-600 bg-orange-600 text-white'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-400',
                                    ]"
                                    @click="setReduceMonths(m)"
                                >
                                    {{ m }} bln
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="reduce-months" class="text-sm font-medium text-slate-700">Bulan (custom)</Label>
                                <Input
                                    id="reduce-months"
                                    v-model.number="reduceCustomMonths"
                                    type="number"
                                    min="1"
                                    max="12"
                                    placeholder="mis. 5"
                                    class="mt-1.5 rounded-lg"
                                    @input="setReduceMonths(reduceCustomMonths)"
                                />
                            </div>
                            <div>
                                <Label for="reduce-days" class="text-sm font-medium text-slate-700">Jumlah hari</Label>
                                <Input
                                    id="reduce-days"
                                    v-model="reduceForm.days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    required
                                    class="mt-1.5 rounded-lg"
                                />
                            </div>
                        </div>
                        <p class="rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-800">
                            Masa aktif akan dikurangi <strong>{{ reduceForm.days }} hari</strong> ≈
                            {{ (reduceForm.days / 30).toFixed(reduceForm.days % 30 === 0 ? 0 : 1) }} bulan
                        </p>
                        <div>
                            <Label for="reduce-notes" class="text-sm font-medium text-slate-700"
                                >Catatan <span class="font-normal text-slate-400">(opsional)</span></Label
                            >
                            <textarea
                                id="reduce-notes"
                                v-model="reduceForm.notes"
                                class="mt-1.5 w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                                rows="3"
                            ></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                        <Button type="button" variant="outline" class="rounded-lg" @click="closeReduceModal">Batal</Button>
                        <Button type="submit" :disabled="reduceForm.processing" class="rounded-lg bg-orange-600 text-white hover:bg-orange-700">
                            {{ reduceForm.processing ? 'Memproses...' : 'Kurangi' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tolak pembayaran -->
        <div
            v-if="showRejectModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closeRejectModal"
        >
            <div role="dialog" aria-modal="true" aria-labelledby="dlg-reject-title" class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <div>
                        <h3 id="dlg-reject-title" class="text-base font-semibold text-slate-900">Tolak pembayaran</h3>
                        <p class="font-mono text-xs text-slate-500">{{ selectedPayment?.invoice_number }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closeRejectModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <form @submit.prevent="rejectPayment">
                    <div class="p-5">
                        <Label for="reject_reason" class="text-sm font-medium text-slate-700"
                            >Alasan penolakan <span class="text-red-500">*</span></Label
                        >
                        <textarea
                            id="reject_reason"
                            v-model="rejectNotes"
                            class="mt-1.5 w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-red-400 focus:ring-2 focus:ring-red-200"
                            rows="3"
                            required
                            placeholder="Misal: Bukti pembayaran tidak valid"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-slate-500">Alasan ini akan terlihat oleh pemilik toko.</p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                        <Button type="button" variant="outline" class="rounded-lg" @click="closeRejectModal">Batal</Button>
                        <Button type="submit" class="rounded-lg bg-red-600 text-white hover:bg-red-700">Tolak pembayaran</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Buat/Edit paket -->
        <div
            v-if="showPlanModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closePlanModal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="dlg-plan-title"
                class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <h3 id="dlg-plan-title" class="text-base font-semibold text-slate-900">{{ editingPlan ? 'Edit paket' : 'Buat paket baru' }}</h3>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closePlanModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="savePlan">
                    <div class="space-y-4 overflow-y-auto p-5">
                        <div>
                            <Label for="plan-name" class="text-sm font-medium text-slate-700">Nama paket <span class="text-red-500">*</span></Label>
                            <Input id="plan-name" v-model="planForm.name" required class="mt-1.5 rounded-lg" placeholder="Contoh: Paket Premium" />
                        </div>
                        <div>
                            <Label for="plan-desc" class="text-sm font-medium text-slate-700">Deskripsi</Label>
                            <Input id="plan-desc" v-model="planForm.description" class="mt-1.5 rounded-lg" placeholder="Cocok untuk toko menengah" />
                        </div>
                        <div>
                            <Label for="plan-cycle" class="text-sm font-medium text-slate-700">Penagihan <span class="text-red-500">*</span></Label>
                            <select
                                id="plan-cycle"
                                v-model="planForm.billing_cycle"
                                class="mt-1.5 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            >
                                <option value="monthly">Bulanan</option>
                                <option value="yearly">Tahunan</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label for="plan-price" class="text-sm font-medium text-slate-700"
                                    >Harga (Rp) <span class="text-red-500">*</span></Label
                                >
                                <Input
                                    id="plan-price"
                                    v-model="planForm.price"
                                    type="number"
                                    min="0"
                                    step="1000"
                                    required
                                    class="mt-1.5 rounded-lg"
                                />
                            </div>
                            <div>
                                <Label for="plan-duration" class="text-sm font-medium text-slate-700"
                                    >Durasi (hari) <span class="text-red-500">*</span></Label
                                >
                                <Input
                                    id="plan-duration"
                                    v-model.number="planForm.duration_days"
                                    type="number"
                                    min="0"
                                    max="3650"
                                    required
                                    class="mt-1.5 rounded-lg"
                                />
                                <p class="mt-1 text-xs text-slate-500">0 = tanpa durasi tetap (paket gratis Starter).</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label for="plan-maxprod" class="text-sm font-medium text-slate-700">Maks. produk</Label>
                                <Input
                                    id="plan-maxprod"
                                    :model-value="planForm.max_products ?? ''"
                                    type="number"
                                    min="1"
                                    class="mt-1.5 rounded-lg"
                                    placeholder="Kosong = tanpa batas"
                                    @update:model-value="
                                        (v) => {
                                            planForm.max_products = toNullableInt(v);
                                        }
                                    "
                                />
                            </div>
                            <div>
                                <Label for="plan-maxcust" class="text-sm font-medium text-slate-700">Maks. pelanggan</Label>
                                <Input
                                    id="plan-maxcust"
                                    :model-value="planForm.max_customers ?? ''"
                                    type="number"
                                    min="1"
                                    class="mt-1.5 rounded-lg"
                                    placeholder="Kosong = tanpa batas"
                                    @update:model-value="
                                        (v) => {
                                            planForm.max_customers = toNullableInt(v);
                                        }
                                    "
                                />
                            </div>
                        </div>
                        <div>
                            <Label for="plan-features" class="text-sm font-medium text-slate-700"
                                >Fitur <span class="font-normal text-slate-400">(satu per baris)</span></Label
                            >
                            <textarea
                                id="plan-features"
                                v-model="planFeaturesText"
                                class="mt-1.5 w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                                rows="4"
                                placeholder="Laporan penjualan&#10;Export Excel/PDF&#10;Support prioritas"
                            ></textarea>
                        </div>
                        <div class="flex items-center gap-6">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input v-model="planForm.is_active" type="checkbox" class="rounded border-slate-300" />
                                <span class="text-sm font-medium text-slate-700">Aktif</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input v-model="planForm.is_popular" type="checkbox" class="rounded border-slate-300" />
                                <span class="text-sm font-medium text-slate-700">Tandai populer</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                        <Button type="button" variant="outline" class="rounded-lg" @click="closePlanModal">Batal</Button>
                        <Button type="submit" :disabled="planForm.processing" class="rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                            {{ planForm.processing ? 'Menyimpan...' : editingPlan ? 'Simpan perubahan' : 'Buat paket' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Buat voucher -->
        <div
            v-if="showCreateVoucherModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
            @click.self="closeCreateVoucherModal"
        >
            <div role="dialog" aria-modal="true" aria-labelledby="dlg-voucher-title" class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
                    <h3 id="dlg-voucher-title" class="text-base font-semibold text-slate-900">Buat voucher baru</h3>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup"
                        @click="closeCreateVoucherModal"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <form @submit.prevent="createVoucher">
                    <div class="space-y-4 p-5">
                        <div>
                            <Label for="v-code" class="text-sm font-medium text-slate-700">Kode voucher <span class="text-red-500">*</span></Label>
                            <Input id="v-code" v-model="voucherForm.code" required class="mt-1.5 rounded-lg font-mono" placeholder="PROMO2025" />
                        </div>
                        <div>
                            <Label for="v-name" class="text-sm font-medium text-slate-700">Nama voucher <span class="text-red-500">*</span></Label>
                            <Input id="v-name" v-model="voucherForm.name" required class="mt-1.5 rounded-lg" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label for="v-days" class="text-sm font-medium text-slate-700">Hari <span class="text-red-500">*</span></Label>
                                <Input id="v-days" v-model="voucherForm.days" type="number" min="1" max="365" required class="mt-1.5 rounded-lg" />
                            </div>
                            <div>
                                <Label for="v-price" class="text-sm font-medium text-slate-700">Harga (Rp) <span class="text-red-500">*</span></Label>
                                <Input id="v-price" v-model="voucherForm.price" type="number" min="0" required class="mt-1.5 rounded-lg" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                        <Button type="button" variant="outline" class="rounded-lg" @click="closeCreateVoucherModal">Batal</Button>
                        <Button type="submit" :disabled="voucherForm.processing" class="rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                            {{ voucherForm.processing ? 'Membuat...' : 'Buat voucher' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </SuperAdminLayout>
</template>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { confirmDelete, confirmDialog, toastError, toastSuccess, toastSuccessThenReload } from '@/lib/swal';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    Check,
    CheckCircle,
    ChevronDown,
    Clock,
    Copy,
    CreditCard,
    Crown,
    DollarSign,
    Edit,
    ExternalLink,
    FlaskConical,
    History,
    Landmark,
    Minus,
    MoreHorizontal,
    Plus,
    Power,
    QrCode,
    Square,
    Ticket,
    Trash2,
    X,
    XCircle,
    MessageCircle,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface Store {
    id: number;
    slug: string;
    name: string;
    address: string;
    is_active: boolean;
    subscription_end: string | null;
    created_at: string;
    pro_trial_used?: boolean;
    pro_trial_active?: boolean;
    pro_trial_start?: string | null;
    pro_trial_end?: string | null;
    owner?: { name: string; email: string; phone: string | null } | null;
    subscription_plan?: {
        slug?: string | null;
        price?: number | null;
    } | null;
}

interface Plan {
    id: number;
    name: string;
    slug: string;
    description: string;
    price: number;
    duration_days: number;
    max_products: number | null;
    max_customers: number | null;
    features: string[] | null;
    is_active: boolean;
    is_popular: boolean;
    sort_order: number;
    billing_cycle?: 'monthly' | 'yearly';
}

interface Payment {
    id: number;
    invoice_number: string;
    amount: number;
    duration_days: number;
    payment_method: string;
    payment_proof: string | null;
    status: string;
    type?: string;
    extra_outlets_count?: number | null;
    notes: string | null;
    admin_notes: string | null;
    created_at: string;
    store?: { name: string };
    user?: { name: string };
    plan?: { name: string };
}

interface Voucher {
    id: number;
    code: string;
    name: string;
    days: number;
    price: number;
    is_active: boolean;
    used_count: number;
}

interface TransferSettings {
    bank_name: string;
    account_holder: string;
    account_number: string;
    qris_url: string | null;
}

interface Props {
    stats: {
        total_stores: number;
        active_stores: number;
        expired_stores: number;
        expiring_soon: number;
        pending_payments: number;
        total_revenue: number;
    };
    stores: { data: Store[]; links: any[]; from?: number | null; to?: number | null; total?: number };
    vouchers: Voucher[];
    plans: Plan[];
    recentPayments: Payment[];
    transferSettings?: TransferSettings;
    attendancePayrollPricing?: { monthly_price: number; yearly_price: number };
    user: { name: string };
}

const props = defineProps<Props>();

// Tab system — diturunkan dari URL Inertia (reaktif saat pindah menu / tombol back),
// tanpa listener popstate global yang tidak pernah dilepas.
const page = usePage();
const activeTab = computed(() => {
    const query = page.url.split('?')[1] ?? '';
    return new URLSearchParams(query).get('tab') || 'stores';
});

// Safe computed props
const safeStats = computed(
    () => props.stats || { total_stores: 0, active_stores: 0, expired_stores: 0, expiring_soon: 0, pending_payments: 0, total_revenue: 0 },
);
const safeStores = computed(() => props.stores || { data: [], links: [] });

/** Link wa.me dari nomor pemilik (format 62… / 08…). */
function waLink(phone?: string | null): string | null {
    let digits = (phone || '').replace(/\D+/g, '');
    if (digits.startsWith('0')) digits = '62' + digits.slice(1);
    else if (digits.startsWith('8')) digits = '62' + digits;
    return digits.length >= 9 ? `https://wa.me/${digits}` : null;
}

function waDisplay(phone?: string | null): string {
    const digits = (phone || '').replace(/\D+/g, '');
    return digits.startsWith('62') ? '0' + digits.slice(2) : digits;
}
const safeVouchers = computed(() => props.vouchers || []);
const safePlans = computed(() => props.plans || []);
const safePayments = computed(() => props.recentPayments || []);
const safeTransferSettings = computed(
    () =>
        props.transferSettings ?? {
            bank_name: '',
            account_holder: '',
            account_number: '',
            qris_url: null,
        },
);
const safeAttendancePayrollPricing = computed(() => props.attendancePayrollPricing ?? { monthly_price: 49000, yearly_price: 490000 });
/** Jumlah pembayaran pending untuk badge menu (sebelumnya variabel ini tidak terdefinisi). */
const pendingCount = computed(() => safeStats.value.pending_payments);

const activeRatio = computed(() => {
    const total = safeStats.value.total_stores;
    return total > 0 ? Math.round((safeStats.value.active_stores / total) * 100) : 0;
});

const storesRange = computed(() => {
    const s = safeStores.value;
    if (!s.total) return '';
    return `Menampilkan ${s.from ?? 0}–${s.to ?? 0} dari ${formatCurrency(s.total)} toko`;
});

// Filter status pembayaran (hanya menyaring daftar yang sudah dimuat).
type PaymentFilter = 'all' | 'pending' | 'confirmed' | 'rejected';
const paymentFilter = ref<PaymentFilter>('all');
const paymentFilters = computed(() => {
    const list = safePayments.value;
    const count = (st: string) => list.filter((p) => p.status === st).length;
    return [
        { value: 'all' as const, label: 'Semua', count: list.length },
        { value: 'pending' as const, label: 'Menunggu', count: count('pending') },
        { value: 'confirmed' as const, label: 'Dikonfirmasi', count: count('confirmed') },
        { value: 'rejected' as const, label: 'Ditolak', count: count('rejected') },
    ];
});
const filteredPayments = computed(() =>
    paymentFilter.value === 'all' ? safePayments.value : safePayments.value.filter((p) => p.status === paymentFilter.value),
);

// Salin kode voucher
const copiedCode = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;
const copyVoucherCode = async (code: string) => {
    try {
        await navigator.clipboard.writeText(code);
        copiedCode.value = code;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copiedCode.value = null), 1500);
    } catch {
        void toastError('Gagal menyalin kode');
    }
};

const transferForm = ref({
    bank_name: '',
    account_holder: '',
    account_number: '',
    qris_file: null as File | null,
    remove_qris: false,
    processing: false,
});

watch(
    safeTransferSettings,
    (t) => {
        transferForm.value.bank_name = t.bank_name;
        transferForm.value.account_holder = t.account_holder;
        transferForm.value.account_number = t.account_number;
    },
    { immediate: true },
);

/** Pratinjau QRIS gagal dimuat (file hilang di storage) -> tampilkan placeholder. */
const qrisPreviewBroken = ref(false);
watch(
    () => safeTransferSettings.value.qris_url,
    () => (qrisPreviewBroken.value = false),
);

const onTransferQrisChange = (ev: Event) => {
    const el = ev.target as HTMLInputElement;
    transferForm.value.qris_file = el.files?.[0] ?? null;
};

const submitTransferSettings = () => {
    transferForm.value.processing = true;
    const fd = new FormData();
    fd.append('bank_name', transferForm.value.bank_name);
    fd.append('account_holder', transferForm.value.account_holder);
    fd.append('account_number', transferForm.value.account_number);
    if (transferForm.value.qris_file) {
        fd.append('qris_image', transferForm.value.qris_file);
    }
    if (transferForm.value.remove_qris) {
        fd.append('remove_qris', '1');
    }
    router.post('/superadmin/transfer-settings', fd, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            transferForm.value.qris_file = null;
            transferForm.value.remove_qris = false;
            void toastSuccess('Pengaturan rekening & QRIS disimpan.');
        },
        onError: () => {
            void toastError('Periksa isian atau ukuran file (maks. 2 MB).');
        },
        onFinish: () => {
            transferForm.value.processing = false;
        },
    });
};

const attendancePayrollPricingForm = ref({
    monthly_price: 49000,
    yearly_price: 490000,
    processing: false,
});

watch(
    safeAttendancePayrollPricing,
    (p) => {
        attendancePayrollPricingForm.value.monthly_price = p.monthly_price;
        attendancePayrollPricingForm.value.yearly_price = p.yearly_price;
    },
    { immediate: true },
);

const submitAttendancePayrollPricing = () => {
    attendancePayrollPricingForm.value.processing = true;
    router.post(
        '/superadmin/attendance-payroll-pricing',
        {
            monthly_price: attendancePayrollPricingForm.value.monthly_price,
            yearly_price: attendancePayrollPricingForm.value.yearly_price,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                void toastSuccess('Harga paket Absensi + Payroll disimpan.');
            },
            onError: () => {
                void toastError('Periksa isian harga.');
            },
            onFinish: () => {
                attendancePayrollPricingForm.value.processing = false;
            },
        },
    );
};

// Modals
const showExtendModal = ref(false);
const showRejectModal = ref(false);
const showPlanModal = ref(false);
const showCreateVoucherModal = ref(false);
const showTrialModal = ref(false);
const selectedStore = ref<Store | null>(null);
const selectedTrialStore = ref<Store | null>(null);
const selectedPayment = ref<Payment | null>(null);
const editingPlan = ref<Plan | null>(null);
const rejectNotes = ref('');

// Forms
const extendForm = ref({ days: 30, notes: '', processing: false });
const extendMonthPresets = [1, 2, 3, 6, 12];
const extendCustomMonths = ref<number | null>(null);
const setExtendMonths = (months: number | null) => {
    if (!months || months < 1) return;
    extendForm.value.days = months * 30;
};

const showReduceModal = ref(false);
const selectedReduceStore = ref<Store | null>(null);
const reduceForm = ref({ days: 30, notes: '', processing: false });
const reduceMonthPresets = [1, 2, 3, 6, 12];
const reduceCustomMonths = ref<number | null>(null);
const setReduceMonths = (months: number | null) => {
    if (!months || months < 1) return;
    reduceForm.value.days = months * 30;
};

const voucherForm = ref({ code: '', name: '', days: 30, price: 0, processing: false });
const planForm = ref({
    name: '',
    description: '',
    price: 0,
    duration_days: 30,
    max_products: null as number | null,
    max_customers: null as number | null,
    is_active: true,
    is_popular: false,
    sort_order: 0,
    billing_cycle: 'monthly' as 'monthly' | 'yearly',
    processing: false,
});
const planFeaturesText = ref('');
const trialForm = ref({ processing: false });

/** Token dari props Inertia (selaras sesi setelah navigasi); fallback meta untuk kompatibilitas. */
const getCSRF = () => {
    const t = (page.props as { csrf_token?: string }).csrf_token;
    if (typeof t === 'string' && t.length > 0) {
        return t;
    }
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

// ===== Store Actions =====
const openExtendModal = (store: Store) => {
    selectedStore.value = store;
    showExtendModal.value = true;
};
const closeExtendModal = () => {
    showExtendModal.value = false;
    selectedStore.value = null;
    extendForm.value = { days: 30, notes: '', processing: false };
    extendCustomMonths.value = null;
};

const extendSubscription = async () => {
    if (!selectedStore.value) return;
    extendForm.value.processing = true;
    try {
        const res = await fetch(`/superadmin/stores/${selectedStore.value.slug}/extend`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
            body: JSON.stringify({ days: extendForm.value.days, notes: extendForm.value.notes }),
        });
        const result = await res.json().catch(() => ({}));
        if (res.ok && result.success) {
            closeExtendModal();
            await toastSuccessThenReload(result.message ?? 'Subscription diperpanjang');
        } else {
            const msg =
                result.message ?? (result.errors ? Object.values(result.errors).flat().join(' ') : null) ?? 'Gagal memperpanjang subscription';
            await toastError(msg);
        }
    } catch {
        await toastError('Terjadi kesalahan');
    } finally {
        extendForm.value.processing = false;
    }
};

const openReduceModal = (store: Store) => {
    selectedReduceStore.value = store;
    showReduceModal.value = true;
};
const closeReduceModal = () => {
    showReduceModal.value = false;
    selectedReduceStore.value = null;
    reduceForm.value = { days: 30, notes: '', processing: false };
    reduceCustomMonths.value = null;
};

const reduceSubscription = async () => {
    if (!selectedReduceStore.value) return;
    reduceForm.value.processing = true;
    try {
        const res = await fetch(`/superadmin/stores/${selectedReduceStore.value.slug}/reduce`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
            body: JSON.stringify({ days: reduceForm.value.days, notes: reduceForm.value.notes }),
        });
        const result = await res.json().catch(() => ({}));
        if (res.ok && result.success) {
            closeReduceModal();
            await toastSuccessThenReload(result.message ?? 'Masa aktif dikurangi');
        } else {
            const msg = result.message ?? (result.errors ? Object.values(result.errors).flat().join(' ') : null) ?? 'Gagal mengurangi masa aktif';
            await toastError(msg);
        }
    } catch {
        await toastError('Terjadi kesalahan');
    } finally {
        reduceForm.value.processing = false;
    }
};

const toggleStoreStatus = async (store: Store) => {
    const ok = await confirmDialog(store.is_active ? 'Nonaktifkan toko?' : 'Aktifkan toko?', `Toko "${store.name}"`);
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/stores/${store.slug}/toggle`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
        });
        const result = await res.json();
        if (result.success) {
            await toastSuccessThenReload(result.message ?? 'Status toko diubah');
        } else {
            await toastError(result.message || 'Gagal mengubah status');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

const deleteStore = async (store: Store) => {
    const activeWarning = store.is_active ? '\n\nPERHATIAN: TOKO INI SEDANG AKTIF. ' : '';
    const ok = await confirmDelete(
        'Hapus toko permanen?',
        `${activeWarning}Toko "${store.name}" beserta SEMUA data produk, transaksi, pelanggan, diskon, dan laporan akan dihapus selamanya. Tindakan ini TIDAK dapat dibatalkan.`,
    );
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/stores/${store.slug}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
        });
        const result = await res.json();
        if (result.success) {
            await toastSuccessThenReload(result.message ?? 'Toko dihapus');
        } else {
            await toastError(result.message || 'Gagal menghapus toko');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

// ===== Trial Actions =====
const openTrialModal = (store: Store) => {
    selectedTrialStore.value = store;
    showTrialModal.value = true;
};
const closeTrialModal = () => {
    showTrialModal.value = false;
    selectedTrialStore.value = null;
    trialForm.value = { processing: false };
};

const activateTrial = async () => {
    if (!selectedTrialStore.value) return;
    trialForm.value.processing = true;
    try {
        const res = await fetch(`/superadmin/stores/${selectedTrialStore.value.slug}/activate-trial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
        });
        const result = await res.json();
        if (result.success) {
            closeTrialModal();
            await toastSuccessThenReload(result.message ?? 'Trial Pro diaktifkan');
        } else {
            await toastError(result.message || 'Gagal mengaktifkan trial');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    } finally {
        trialForm.value.processing = false;
    }
};

const stopTrial = async (store: Store) => {
    const ok = await confirmDialog('Hentikan Trial Pro?', `Trial untuk toko "${store.name}" akan dihentikan dan toko akan kembali ke paket Starter.`);
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/stores/${store.slug}/stop-trial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
        });
        const result = await res.json();
        if (result.success) {
            await toastSuccessThenReload(result.message ?? 'Trial dihentikan');
        } else {
            await toastError(result.message || 'Gagal menghentikan trial');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

// ===== Payment Actions =====
const confirmPayment = async (payment: Payment) => {
    const ok = await confirmDialog(
        'Konfirmasi pembayaran?',
        `${payment.invoice_number} — masa aktif toko akan diperpanjang ${payment.duration_days} hari.`,
        { icon: 'question', confirmText: 'Konfirmasi' },
    );
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/payments/${payment.id}/confirm`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
            body: JSON.stringify({ admin_notes: '' }),
        });
        const result = await res.json();
        if (result.success) {
            await toastSuccessThenReload(result.message ?? 'Pembayaran dikonfirmasi');
        } else {
            await toastError(result.message || 'Gagal mengonfirmasi');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

const openRejectModal = (payment: Payment) => {
    selectedPayment.value = payment;
    rejectNotes.value = '';
    showRejectModal.value = true;
};
const closeRejectModal = () => {
    showRejectModal.value = false;
    selectedPayment.value = null;
};

const rejectPayment = async () => {
    if (!selectedPayment.value || !rejectNotes.value) return;
    try {
        const res = await fetch(`/superadmin/payments/${selectedPayment.value.id}/reject`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
            body: JSON.stringify({ admin_notes: rejectNotes.value }),
        });
        const result = await res.json();
        if (result.success) {
            closeRejectModal();
            await toastSuccessThenReload(result.message ?? 'Pembayaran ditolak');
        } else {
            await toastError(result.message || 'Gagal menolak');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

// ===== Plan Actions =====
const openCreatePlanModal = () => {
    editingPlan.value = null;
    planForm.value = {
        name: '',
        description: '',
        price: 0,
        duration_days: 30,
        max_products: null,
        max_customers: null,
        is_active: true,
        is_popular: false,
        sort_order: 0,
        billing_cycle: 'monthly',
        processing: false,
    };
    planFeaturesText.value = '';
    showPlanModal.value = true;
};

const openEditPlanModal = (plan: Plan) => {
    editingPlan.value = plan;
    planForm.value = {
        name: plan.name,
        description: plan.description || '',
        price: plan.price,
        duration_days: plan.duration_days,
        max_products: plan.max_products,
        max_customers: plan.max_customers,
        is_active: plan.is_active,
        is_popular: plan.is_popular,
        sort_order: plan.sort_order,
        billing_cycle: plan.billing_cycle ?? 'monthly',
        processing: false,
    };
    planFeaturesText.value = (plan.features || []).join('\n');
    showPlanModal.value = true;
};

const closePlanModal = () => {
    showPlanModal.value = false;
    editingPlan.value = null;
};

const toNullableInt = (v: unknown): number | null => {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
};

const savePlan = () => {
    planForm.value.processing = true;
    const features = planFeaturesText.value
        .split('\n')
        .map((f) => f.trim())
        .filter((f) => f);
    const { processing, ...rest } = planForm.value;
    void processing;
    const dur = Number(rest.duration_days);
    const body = {
        ...rest,
        features,
        duration_days: Number.isFinite(dur) ? Math.max(0, Math.trunc(dur)) : 0,
        price: Number(rest.price) ?? 0,
        sort_order: rest.sort_order != null ? Number(rest.sort_order) : null,
        max_products: toNullableInt(rest.max_products),
        max_customers: toNullableInt(rest.max_customers),
    };
    const visitOpts = {
        preserveScroll: true,
        onFinish: () => {
            planForm.value.processing = false;
        },
        onSuccess: () => {
            closePlanModal();
            void toastSuccessThenReload('Paket disimpan');
        },
        onError: (errors: Record<string, string | string[]>) => {
            const msg = Object.values(errors)
                .flatMap((v) => (Array.isArray(v) ? v : [v]))
                .join(' ');
            void toastError(msg || 'Gagal menyimpan paket');
        },
    };
    if (editingPlan.value) {
        router.put(`/superadmin/plans/${editingPlan.value.id}`, body, visitOpts);
    } else {
        router.post('/superadmin/plans', body, visitOpts);
    }
};

const deletePlan = async (plan: Plan) => {
    if (!(await confirmDelete('Hapus paket?', `Paket "${plan.name}" akan dihapus.`))) return;
    try {
        const res = await fetch(`/superadmin/plans/${plan.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': getCSRF(), Accept: 'application/json' },
        });
        if (res.ok || res.status === 302) {
            await toastSuccessThenReload('Paket dihapus');
        } else {
            const r = await res.json();
            await toastError(r.message || 'Gagal menghapus');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
};

// ===== Voucher Actions =====
const openCreateVoucherModal = () => {
    voucherForm.value = { code: '', name: '', days: 30, price: 0, processing: false };
    showCreateVoucherModal.value = true;
};
const closeCreateVoucherModal = () => {
    showCreateVoucherModal.value = false;
};

const createVoucher = async () => {
    voucherForm.value.processing = true;
    try {
        const res = await fetch('/superadmin/vouchers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF() },
            body: JSON.stringify(voucherForm.value),
        });
        if (res.ok || res.status === 302) {
            closeCreateVoucherModal();
            await toastSuccessThenReload('Voucher dibuat');
        } else {
            await toastError('Gagal membuat voucher');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    } finally {
        voucherForm.value.processing = false;
    }
};

// Tutup modal yang terbuka dengan tombol Esc.
const onEscape = (e: KeyboardEvent) => {
    if (e.key !== 'Escape') return;
    if (showPlanModal.value) closePlanModal();
    else if (showCreateVoucherModal.value) closeCreateVoucherModal();
    else if (showRejectModal.value) closeRejectModal();
    else if (showExtendModal.value) closeExtendModal();
    else if (showReduceModal.value) closeReduceModal();
    else if (showTrialModal.value) closeTrialModal();
};
onMounted(() => window.addEventListener('keydown', onEscape));
onUnmounted(() => {
    window.removeEventListener('keydown', onEscape);
    clearTimeout(copiedTimer);
});

// ===== Helpers =====
const PLAN_LABELS: Record<string, string> = {
    starter: 'Starter',
    'starter-tahunan': 'Starter (tahunan)',
    growup: 'GrowUp',
    'growup-tahunan': 'GrowUp (tahunan)',
};
const planLabel = (store: Store): string => {
    const slug = store.subscription_plan?.slug;
    if (!slug) return '—';
    return PLAN_LABELS[slug] ?? slug.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
};

const statusPillClass = (store: Store) => {
    const base = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset';
    const status = getStoreStatus(store);
    if (status === 'Aktif') return `${base} bg-emerald-50 text-emerald-700 ring-emerald-200`;
    if (status === 'Expired') return `${base} bg-red-50 text-red-700 ring-red-200`;
    return `${base} bg-slate-100 text-slate-600 ring-slate-200`;
};

const paymentPillClass = (status: string) => {
    const base = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset';
    const map: Record<string, string> = {
        pending: 'bg-amber-50 text-amber-800 ring-amber-200',
        confirmed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
        expired: 'bg-slate-100 text-slate-600 ring-slate-200',
    };
    return `${base} ${map[status] ?? map.expired}`;
};

/** Sisa masa aktif yang informatif (termasuk sudah lewat berapa hari). */
const remainingLabel = (endDate: string) => {
    const diff = Math.ceil((new Date(endDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24));
    if (diff > 0) return `${diff} hari lagi`;
    if (diff === 0) return 'Berakhir hari ini';
    return `Lewat ${Math.abs(diff)} hari`;
};

const paginationLabel = (label: string) => label.replace('&laquo; Previous', '&laquo;').replace('Next &raquo;', '&raquo;');

const isStarterFreeStore = (store: Store): boolean => {
    const slug = store.subscription_plan?.slug ?? '';
    const price = Number(store.subscription_plan?.price ?? NaN);
    return (slug === 'starter' || slug === 'starter-tahunan') && Number.isFinite(price) && price === 0;
};

const getStoreStatus = (store: Store) => {
    if (!store.is_active) return 'Nonaktif';
    if (isStarterFreeStore(store)) return 'Aktif';
    if (!store.subscription_end) return 'Aktif';
    return new Date(store.subscription_end) < new Date() ? 'Expired' : 'Aktif';
};

const getPaymentStatusLabel = (status: string) => {
    const labels: Record<string, string> = { pending: 'Menunggu', confirmed: 'Dikonfirmasi', rejected: 'Ditolak', expired: 'Kedaluwarsa' };
    return labels[status] || status;
};

const getMethodLabel = (method: string) => {
    const labels: Record<string, string> = { bank_transfer: 'Transfer Bank', e_wallet: 'E-Wallet', qris: 'QRIS', cash: 'Tunai' };
    return labels[method] || method;
};

const getDaysRemaining = (endDate: string) => Math.max(0, Math.ceil((new Date(endDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24)));
const getDaysRemainingClass = (endDate: string) => {
    const d = getDaysRemaining(endDate);
    if (d <= 0) return 'text-red-600 font-semibold';
    if (d <= 7) return 'text-amber-600 font-semibold';
    return 'text-slate-500';
};

const formatDate = (date: string) => new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const formatDateTime = (date: string) =>
    new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const formatCurrency = (amount: number) => new Intl.NumberFormat('id-ID').format(amount);
const formatCurrencyShort = (amount: number) => {
    const n = Number(amount) || 0;
    const fmt = (v: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(v);
    if (n >= 1_000_000_000) return fmt(n / 1_000_000_000) + ' M';
    if (n >= 1_000_000) return fmt(n / 1_000_000) + ' jt';
    if (n >= 1_000) return fmt(n / 1_000) + ' rb';
    return fmt(n);
};
</script>
