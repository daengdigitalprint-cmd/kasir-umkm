<template>
    <Head title="Super Admin Dashboard" />
    
    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <!-- Header -->
        <div class="bg-white/80 backdrop-blur-md border-b border-slate-200/60 sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-20">
                    <div class="flex items-center gap-4">
                        <div class="p-2.5 bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl shadow-lg shadow-red-200">
                            <Shield class="h-6 w-6 text-white" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Super Admin</h1>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Management Panel</p>
                        </div>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                class="flex h-auto items-center gap-4 rounded-2xl px-2 py-1.5 hover:bg-slate-100/80"
                            >
                                <div class="text-right hidden sm:block">
                                    <p class="text-sm font-bold text-slate-800">{{ safeUser.name }}</p>
                                    <p class="text-xs text-slate-400">Super Admin</p>
                                </div>
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 shadow-lg shadow-red-200"
                                >
                                    <span class="text-sm font-black text-white">SA</span>
                                </div>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="z-[60] w-56 rounded-2xl border border-slate-200 p-2 shadow-lg"
                        >
                            <DropdownMenuLabel class="px-2 py-1.5 font-normal">
                                <p class="text-sm font-bold text-slate-900">{{ safeUser.name }}</p>
                                <p class="text-xs text-slate-500">Super Admin</p>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :as-child="true">
                                <Link
                                    class="flex w-full cursor-pointer items-center"
                                    :href="logout()"
                                    as="button"
                                    @click="handleLogout"
                                >
                                    <LogOut class="mr-2 h-4 w-4" />
                                    Keluar
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 lg:pt-8 pb-8">
            
            <!-- Tab Navigation -->
            <div class="flex gap-2 mb-8 overflow-x-auto pb-2">
                <button 
                    v-for="tab in tabs" 
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="[
                        'flex items-center gap-2 px-5 py-2.5 rounded-2xl text-sm font-bold transition-all duration-300 whitespace-nowrap',
                        activeTab === tab.id 
                            ? 'bg-slate-900 text-white shadow-lg shadow-slate-300' 
                            : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                    ]"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                    <span 
                        v-if="tab.badge" 
                        :class="[
                            'px-2 py-0.5 rounded-full text-xs font-black',
                            activeTab === tab.id ? 'bg-white/20 text-white' : 'bg-red-100 text-red-700'
                        ]"
                    >{{ tab.badge }}</span>
                </button>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="p-[2px] bg-gradient-to-br from-blue-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-blue-100 rounded-xl">
                                <Building2 class="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Total Toko</p>
                                <p class="text-xl font-black text-slate-900">{{ safeStats.total_stores }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-[2px] bg-gradient-to-br from-green-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-green-100 rounded-xl">
                                <CheckCircle class="h-5 w-5 text-green-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Toko Aktif</p>
                                <p class="text-xl font-black text-slate-900">{{ safeStats.active_stores }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-[2px] bg-gradient-to-br from-red-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-red-100 rounded-xl">
                                <XCircle class="h-5 w-5 text-red-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Expired</p>
                                <p class="text-xl font-black text-slate-900">{{ safeStats.expired_stores }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-[2px] bg-gradient-to-br from-amber-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-amber-100 rounded-xl">
                                <AlertTriangle class="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Akan Expire</p>
                                <p class="text-xl font-black text-slate-900">{{ safeStats.expiring_soon }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-[2px] bg-gradient-to-br from-purple-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-purple-100 rounded-xl">
                                <Clock class="h-5 w-5 text-purple-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Pending</p>
                                <p class="text-xl font-black text-slate-900">{{ safeStats.pending_payments }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-[2px] bg-gradient-to-br from-emerald-400/70 to-white/10 rounded-[1.5rem]">
                    <div class="bg-white rounded-[1.35rem] p-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-emerald-100 rounded-xl">
                                <DollarSign class="h-5 w-5 text-emerald-600" />
                            </div>
                            <div>
                                <p class="text-xs text-slate-500 font-medium">Revenue</p>
                                <p class="text-lg font-black text-slate-900">{{ formatCurrencyShort(safeStats.total_revenue) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== STORES TAB ========== -->
            <div v-if="activeTab === 'stores'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <Building2 class="h-5 w-5 text-slate-600" />
                            Daftar Toko
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-for="store in safeStores.data" :key="store.id" 
                                 class="border border-slate-200 rounded-2xl p-4 hover:shadow-md transition-all duration-300">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <h3 class="font-bold text-slate-900">{{ store.name }}</h3>
                                            <Badge :class="getStoreStatusClass(store)">
                                                {{ getStoreStatus(store) }}
                                            </Badge>
                                        </div>
                                        <p class="text-sm text-slate-500 mt-1 truncate">{{ store.address || 'Alamat belum diatur' }}</p>
                                        <div class="flex items-center gap-4 mt-2 text-xs text-slate-400 flex-wrap">
                                            <span>Dibuat: {{ formatDate(store.created_at) }}</span>
                                            <span v-if="store.subscription_end && !isStarterFreeStore(store)">
                                                Expire: {{ formatDate(store.subscription_end) }}
                                            </span>
                                            <span v-if="store.subscription_end && !isStarterFreeStore(store)" 
                                                  :class="getDaysRemainingClass(store.subscription_end)">
                                                {{ getDaysRemaining(store.subscription_end) }} hari lagi
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Button 
                                            @click="openExtendModal(store)"
                                            size="sm"
                                            class="bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 rounded-xl text-xs font-bold"
                                        >
                                            <Plus class="h-3.5 w-3.5 mr-1" />
                                            Perpanjang
                                        </Button>
                                        <Button 
                                            v-if="isStarterFreeStore(store) && !store.pro_trial_used && store.is_active"
                                            @click="openTrialModal(store)"
                                            size="sm"
                                            class="bg-purple-50 text-purple-700 hover:bg-purple-100 border border-purple-200 rounded-xl text-xs font-bold"
                                        >
                                            <FlaskConical class="h-3.5 w-3.5 mr-1" />
                                            Aktifkan Trial Pro
                                        </Button>
                                        <Button 
                                            v-if="store.pro_trial_active"
                                            @click="stopTrial(store)"
                                            size="sm"
                                            class="bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200 rounded-xl text-xs font-bold"
                                        >
                                            <Square class="h-3.5 w-3.5 mr-1" />
                                            Hentikan Trial
                                        </Button>
                                        <span 
                                            v-if="store.pro_trial_active && store.pro_trial_end"
                                            class="text-xs text-purple-600 font-bold"
                                        >
                                            Trial berakhir: {{ formatDate(store.pro_trial_end) }}
                                        </span>
                                        <Button 
                                            @click="toggleStoreStatus(store)"
                                            size="sm"
                                            :class="store.is_active 
                                                ? 'bg-red-50 text-red-700 hover:bg-red-100 border border-red-200 rounded-xl text-xs font-bold' 
                                                : 'bg-green-50 text-green-700 hover:bg-green-100 border border-green-200 rounded-xl text-xs font-bold'"
                                        >
                                            {{ store.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </Button>
                                        <Button 
                                            @click="deleteStore(store)"
                                            size="sm"
                                            :class="store.is_active 
                                                ? 'bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 rounded-xl text-xs font-bold' 
                                                : 'bg-red-600 text-white hover:bg-red-700 rounded-xl text-xs font-bold shadow-sm shadow-red-200'"
                                        >
                                            <Trash2 class="h-3.5 w-3.5 mr-1" />
                                            Hapus
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div v-if="safeStores.links && safeStores.links.length > 3" class="mt-6 flex justify-center">
                            <nav class="flex items-center gap-1">
                                <Link 
                                    v-for="link in safeStores.links" 
                                    :key="link.label"
                                    :href="link.url || '#'"
                                    :class="[
                                        'px-3 py-2 text-sm rounded-xl font-bold transition-colors',
                                        link.active 
                                            ? 'bg-slate-900 text-white' 
                                            : 'text-slate-600 hover:bg-slate-100'
                                    ]"
                                >
                                    <span v-html="link.label" />
                                </Link>
                            </nav>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ========== PAYMENTS TAB ========== -->
            <div v-if="activeTab === 'payments'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <CreditCard class="h-5 w-5 text-slate-600" />
                            Konfirmasi Pembayaran
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="safePayments.length === 0" class="text-center py-12">
                            <div class="p-4 bg-slate-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <CreditCard class="h-8 w-8 text-slate-400" />
                            </div>
                            <p class="text-slate-500">Belum ada pembayaran</p>
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="payment in safePayments" :key="payment.id" 
                                 :class="[
                                     'border rounded-2xl p-5 transition-all duration-300',
                                     payment.status === 'pending' ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200'
                                 ]">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <span class="font-mono font-bold text-sm text-slate-900">{{ payment.invoice_number }}</span>
                                            <Badge :class="getPaymentStatusClass(payment.status)">
                                                {{ getPaymentStatusLabel(payment.status) }}
                                            </Badge>
                                        </div>
                                        <div class="mt-2 space-y-1">
                                            <p class="text-sm text-slate-600">
                                                <span class="font-medium">Toko:</span> {{ payment.store?.name || '-' }}
                                            </p>
                                            <p class="text-sm text-slate-600">
                                                <span class="font-medium">Paket:</span> {{ payment.plan?.name || 'Custom' }} · {{ payment.duration_days }} hari
                                            </p>
                                            <p v-if="payment.type === 'outlet_addon'" class="text-sm font-semibold text-violet-700">
                                                <span class="font-medium text-slate-600">Add-on Outlet:</span> +{{ payment.extra_outlets_count ?? 0 }} outlet
                                            </p>
                                            <p v-if="payment.type === 'attendance_payroll_addon'" class="text-sm font-semibold text-emerald-700">
                                                Paket Absensi + Payroll
                                            </p>
                                            <p class="text-sm text-slate-600">
                                                <span class="font-medium">Metode:</span> {{ getMethodLabel(payment.payment_method) }}
                                            </p>
                                            <p v-if="payment.notes" class="text-xs text-slate-400 italic mt-1">
                                                "{{ payment.notes }}"
                                            </p>
                                        </div>
                                        <p class="text-xs text-slate-400 mt-2">{{ formatDateTime(payment.created_at) }}</p>
                                    </div>
                                    <div class="flex flex-col items-end gap-3">
                                        <p class="text-xl font-black text-slate-900">Rp {{ formatCurrency(payment.amount) }}</p>
                                        
                                        <!-- Payment Proof -->
                                        <a v-if="payment.payment_proof" 
                                           :href="`/storage/${payment.payment_proof}`" 
                                           target="_blank"
                                           class="text-xs text-blue-600 hover:text-blue-800 underline font-medium">
                                            Lihat Bukti Bayar
                                        </a>

                                        <div v-if="payment.status === 'pending'" class="flex items-center gap-2">
                                            <Button 
                                                @click="confirmPayment(payment)"
                                                size="sm"
                                                class="bg-green-500 text-white hover:bg-green-600 rounded-xl text-xs font-bold px-4"
                                            >
                                                <CheckCircle class="h-3.5 w-3.5 mr-1" />
                                                Konfirmasi
                                            </Button>
                                            <Button 
                                                @click="openRejectModal(payment)"
                                                size="sm"
                                                class="bg-red-50 text-red-700 hover:bg-red-100 border border-red-200 rounded-xl text-xs font-bold px-4"
                                            >
                                                <XCircle class="h-3.5 w-3.5 mr-1" />
                                                Tolak
                                            </Button>
                                        </div>
                                        <p v-if="payment.admin_notes" class="text-xs text-slate-400 italic text-right max-w-xs">
                                            Admin: {{ payment.admin_notes }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ========== REKENING & QRIS (CHECKOUT LANGGANAN) ========== -->
            <div v-if="activeTab === 'transfer'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <Landmark class="h-5 w-5 text-slate-600" />
                            Rekening &amp; QRIS pembayaran langganan
                        </CardTitle>
                        <p class="text-sm text-slate-500 font-medium">
                            Data ini tampil kepada pemilik toko di halaman checkout langganan (
                            <code class="rounded bg-slate-100 px-1 text-xs">/subscription/checkout</code>
                            ).
                        </p>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="ts-bank">Nama bank</Label>
                                <Input
                                    id="ts-bank"
                                    v-model="transferForm.bank_name"
                                    placeholder="contoh: BCA"
                                    class="rounded-xl"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="ts-number">No. rekening</Label>
                                <Input
                                    id="ts-number"
                                    v-model="transferForm.account_number"
                                    placeholder="Nomor rekening"
                                    class="rounded-xl font-mono"
                                />
                            </div>
                            <div class="space-y-2 sm:col-span-2">
                                <Label for="ts-holder">Atas nama</Label>
                                <Input
                                    id="ts-holder"
                                    v-model="transferForm.account_holder"
                                    placeholder="Nama pemilik rekening"
                                    class="rounded-xl"
                                />
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5">
                            <Label class="text-slate-800">Gambar QRIS</Label>
                            <p class="mt-1 text-xs text-slate-500">
                                JPG, PNG, atau WebP · maks. 2 MB. Disarankan gambar jelas untuk di-scan.
                            </p>
                            <div v-if="safeTransferSettings.qris_url" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start">
                                <img
                                    :src="safeTransferSettings.qris_url"
                                    alt="QRIS saat ini"
                                    class="max-h-48 max-w-[200px] rounded-xl border border-slate-200 bg-white object-contain"
                                />
                                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                    <input v-model="transferForm.remove_qris" type="checkbox" class="rounded border-slate-300" />
                                    Hapus QRIS yang ada (tanpa mengganti file baru)
                                </label>
                            </div>
                            <div class="mt-3">
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/jpg"
                                    class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-xs file:font-bold file:text-white"
                                    @change="onTransferQrisChange"
                                />
                                <p v-if="transferForm.qris_file" class="mt-2 text-xs font-medium text-emerald-700">
                                    File terpilih: {{ transferForm.qris_file.name }}
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            class="rounded-xl bg-slate-900 px-6 font-bold text-white hover:bg-slate-800"
                            :disabled="transferForm.processing"
                            @click="submitTransferSettings"
                        >
                            {{ transferForm.processing ? 'Menyimpan…' : 'Simpan pengaturan' }}
                        </Button>
                    </CardContent>
                </Card>

                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <DollarSign class="h-5 w-5 text-emerald-600" />
                            Harga Paket Absensi + Payroll
                        </CardTitle>
                        <p class="text-sm text-slate-500 font-medium">
                            Harga add-on Absensi + Payroll yang tampil di halaman langganan toko. Dipakai sebagai acuan saat checkout.
                        </p>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="ap-monthly">Harga bulanan (Rp)</Label>
                                <Input
                                    id="ap-monthly"
                                    v-model.number="attendancePayrollPricingForm.monthly_price"
                                    type="number"
                                    min="0"
                                    step="1000"
                                    class="rounded-xl"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="ap-yearly">Harga tahunan (Rp)</Label>
                                <Input
                                    id="ap-yearly"
                                    v-model.number="attendancePayrollPricingForm.yearly_price"
                                    type="number"
                                    min="0"
                                    step="1000"
                                    class="rounded-xl"
                                />
                            </div>
                        </div>
                        <Button
                            type="button"
                            class="rounded-xl bg-emerald-600 px-6 font-bold text-white hover:bg-emerald-700"
                            :disabled="attendancePayrollPricingForm.processing"
                            @click="submitAttendancePayrollPricing"
                        >
                            {{ attendancePayrollPricingForm.processing ? 'Menyimpan…' : 'Simpan harga add-on' }}
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <!-- ========== PLANS TAB ========== -->
            <div v-if="activeTab === 'plans'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-lg">
                                <Crown class="h-5 w-5 text-slate-600" />
                                Paket Subscription
                            </div>
                            <Button @click="openCreatePlanModal" size="sm" class="bg-slate-900 text-white hover:bg-slate-800 rounded-xl text-xs font-bold">
                                <Plus class="h-3.5 w-3.5 mr-1" />
                                Tambah Paket
                            </Button>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="safePlans.length === 0" class="text-center py-12">
                            <div class="p-4 bg-slate-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <Crown class="h-8 w-8 text-slate-400" />
                            </div>
                            <p class="text-slate-500">Belum ada paket subscription</p>
                            <Button @click="openCreatePlanModal" class="mt-4 rounded-xl bg-slate-900 text-white">
                                <Plus class="h-4 w-4 mr-2" />
                                Buat Paket Pertama
                            </Button>
                        </div>
                        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="plan in safePlans" :key="plan.id" 
                                 :class="[
                                     'border rounded-2xl p-5 relative',
                                     plan.is_popular ? 'border-primary shadow-lg shadow-primary/10' : 'border-slate-200',
                                     !plan.is_active ? 'opacity-60' : ''
                                 ]">
                                <div v-if="plan.is_popular" class="absolute -top-3 left-4">
                                    <span class="bg-primary text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider">Populer</span>
                                </div>
                                <div class="mb-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="font-bold text-slate-900">{{ plan.name }}</h4>
                                        <span
                                            v-if="(plan.billing_cycle ?? 'monthly') === 'yearly'"
                                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900"
                                        >
                                            Tahunan
                                        </span>
                                        <span
                                            v-else
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600"
                                        >
                                            Bulanan
                                        </span>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5">{{ plan.description || '-' }}</p>
                                </div>
                                <div class="mb-4">
                                    <span class="text-2xl font-black text-slate-900">Rp {{ formatCurrency(plan.price) }}</span>
                                    <span class="text-xs text-slate-500">{{
                                        plan.duration_days > 0 ? ` / ${plan.duration_days} hari` : ' / tanpa masa aktif (0 hari)'
                                    }}</span>
                                </div>
                                <div class="text-xs text-slate-500 space-y-1 mb-4">
                                    <p>Max Produk: {{ plan.max_products || 'Unlimited' }}</p>
                                    <p>Status: {{ plan.is_active ? 'Aktif' : 'Nonaktif' }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <Button @click="openEditPlanModal(plan)" size="sm" variant="outline" class="rounded-xl text-xs font-bold flex-1">
                                        <Edit class="h-3 w-3 mr-1" />
                                        Edit
                                    </Button>
                                    <Button @click="deletePlan(plan)" size="sm" class="bg-red-50 text-red-700 hover:bg-red-100 rounded-xl text-xs font-bold">
                                        <Trash2 class="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ========== VOUCHERS TAB ========== -->
            <div v-if="activeTab === 'vouchers'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-lg">
                                <Ticket class="h-5 w-5 text-slate-600" />
                                Voucher
                            </div>
                            <Button @click="openCreateVoucherModal" size="sm" class="bg-slate-900 text-white hover:bg-slate-800 rounded-xl text-xs font-bold">
                                <Plus class="h-3.5 w-3.5 mr-1" />
                                Buat Voucher
                            </Button>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="safeVouchers.length === 0" class="text-center py-12">
                            <p class="text-slate-500">Belum ada voucher</p>
                        </div>
                        <div v-else class="space-y-3">
                            <div v-for="voucher in safeVouchers" :key="voucher.id" 
                                 class="border border-slate-200 rounded-2xl p-4 flex items-center justify-between">
                                <div>
                                    <h4 class="font-bold text-slate-900">{{ voucher.name }}</h4>
                                    <p class="text-sm text-slate-500 font-mono">{{ voucher.code }}</p>
                                    <p class="text-xs text-slate-400 mt-1">
                                        {{ voucher.days }} hari · Rp {{ formatCurrency(voucher.price) }} · Dipakai: {{ voucher.used_count || 0 }}x
                                    </p>
                                </div>
                                <Badge :class="voucher.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'">
                                    {{ voucher.is_active ? 'Aktif' : 'Nonaktif' }}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ========== TRIAL LOGS TAB ========== -->
            <div v-if="activeTab === 'trial-logs'" class="space-y-6">
                <Card class="border-0 shadow-lg rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-lg">
                            <History class="h-5 w-5 text-slate-600" />
                            Log Aktivitas Trial Pro
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-slate-500 mb-4">
                            Riwayat lengkap aktivasi, penghentian, dan kedaluwarsa trial Pro untuk semua toko.
                        </p>
                        <Link href="/superadmin/trial-logs">
                            <Button class="rounded-xl bg-slate-900 text-white hover:bg-slate-800 text-xs font-bold">
                                <History class="h-3.5 w-3.5 mr-1" />
                                Lihat Log Trial Lengkap
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- ========== MODALS ========== -->

        <!-- Activate Trial Confirmation Modal -->
        <div v-if="showTrialModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeTrialModal">
            <div class="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <div class="flex items-center gap-3 mb-4">
                    <div class="p-2.5 bg-purple-100 rounded-2xl">
                        <FlaskConical class="h-5 w-5 text-purple-600" />
                    </div>
                    <h3 class="text-lg font-bold text-slate-900">Aktifkan Trial Pro</h3>
                </div>
                <p class="text-sm text-slate-500 mb-2">
                    Aktifkan trial <strong class="text-slate-900">7 hari</strong> paket <strong class="text-purple-700">GrowUp (Pro)</strong> untuk toko:
                </p>
                <p class="text-base font-bold text-slate-900 mb-4">{{ selectedTrialStore?.name }}</p>
                <div class="bg-purple-50 border border-purple-200 rounded-2xl p-4 mb-6">
                    <ul class="text-sm text-purple-800 space-y-1">
                        <li>Akses fitur GrowUp tanpa batas selama 7 hari</li>
                        <li>Produk, pelanggan, transaksi, dan laporan unlimited</li>
                        <li>Multi-outlet, transfer stok, dan fitur premium lainnya</li>
                    </ul>
                </div>
                <p class="text-xs text-slate-400 mb-6">
                    Masa trial akan berakhir otomatis setelah 7 hari dan toko akan kembali ke paket Starter.
                </p>
                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" @click="closeTrialModal" class="rounded-xl">Batal</Button>
                    <Button 
                        @click="activateTrial" 
                        :disabled="trialForm.processing"
                        class="rounded-xl bg-purple-600 text-white hover:bg-purple-700"
                    >
                        {{ trialForm.processing ? 'Mengaktifkan...' : 'Ya, Aktifkan Trial' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Extend Subscription Modal -->
        <div v-if="showExtendModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeExtendModal">
            <div class="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="text-lg font-bold text-slate-900 mb-1">Perpanjang Masa Aktif</h3>
                <p class="text-sm text-slate-500 mb-6">{{ selectedStore?.name }}</p>
                <form @submit.prevent="extendSubscription">
                    <div class="space-y-4">
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Pilih Durasi</Label>
                            <div class="flex flex-wrap gap-2 mt-1.5">
                                <button
                                    v-for="m in extendMonthPresets"
                                    :key="m"
                                    type="button"
                                    @click="setExtendMonths(m)"
                                    :class="[
                                        'px-3 py-1.5 rounded-xl text-sm font-medium border transition',
                                        extendForm.days === m * 30
                                            ? 'bg-slate-900 text-white border-slate-900'
                                            : 'bg-white text-slate-700 border-slate-200 hover:border-slate-400'
                                    ]"
                                >
                                    {{ m }} Bln
                                </button>
                            </div>
                        </div>
                        <div>
                            <Label for="months" class="text-sm font-medium text-slate-700">Atau jumlah bulan custom</Label>
                            <Input id="months" v-model.number="extendCustomMonths" type="number" min="1" max="12" placeholder="contoh: 5" class="mt-1.5 rounded-xl" @input="setExtendMonths(extendCustomMonths)" />
                        </div>
                        <div>
                            <Label for="days" class="text-sm font-medium text-slate-700">Jumlah Hari ({{ extendForm.days }} hari = {{ (extendForm.days / 30).toFixed(extendForm.days % 30 === 0 ? 0 : 1) }} bulan)</Label>
                            <Input id="days" v-model="extendForm.days" type="number" min="1" max="365" required class="mt-1.5 rounded-xl" />
                        </div>
                        <div>
                            <Label for="notes" class="text-sm font-medium text-slate-700">Catatan (Opsional)</Label>
                            <textarea id="notes" v-model="extendForm.notes" class="w-full mt-1.5 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none resize-none" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <Button type="button" variant="outline" @click="closeExtendModal" class="rounded-xl">Batal</Button>
                        <Button type="submit" :disabled="extendForm.processing" class="rounded-xl bg-slate-900 text-white">
                            {{ extendForm.processing ? 'Memproses...' : 'Perpanjang' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reject Payment Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeRejectModal">
            <div class="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="text-lg font-bold text-slate-900 mb-1">Tolak Pembayaran</h3>
                <p class="text-sm text-slate-500 mb-6">{{ selectedPayment?.invoice_number }}</p>
                <form @submit.prevent="rejectPayment">
                    <div>
                        <Label for="reject_reason" class="text-sm font-medium text-slate-700">Alasan Penolakan *</Label>
                        <textarea id="reject_reason" v-model="rejectNotes" class="w-full mt-1.5 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-red-200 focus:border-red-400 outline-none resize-none" rows="3" required placeholder="Misal: Bukti pembayaran tidak valid"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <Button type="button" variant="outline" @click="closeRejectModal" class="rounded-xl">Batal</Button>
                        <Button type="submit" class="rounded-xl bg-red-500 text-white hover:bg-red-600">Tolak Pembayaran</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create/Edit Plan Modal -->
        <div v-if="showPlanModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closePlanModal">
            <div class="bg-white rounded-3xl p-6 w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold text-slate-900 mb-6">{{ editingPlan ? 'Edit Paket' : 'Buat Paket Baru' }}</h3>
                <form @submit.prevent="savePlan">
                    <div class="space-y-4">
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Nama Paket *</Label>
                            <Input v-model="planForm.name" required class="mt-1.5 rounded-xl" placeholder="Contoh: Paket Premium" />
                        </div>
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Deskripsi</Label>
                            <Input v-model="planForm.description" class="mt-1.5 rounded-xl" placeholder="Cocok untuk toko menengah" />
                        </div>
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Penagihan *</Label>
                            <select
                                v-model="planForm.billing_cycle"
                                class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            >
                                <option value="monthly">Bulanan</option>
                                <option value="yearly">Tahunan</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Harga (Rp) *</Label>
                                <Input v-model="planForm.price" type="number" min="0" step="1000" required class="mt-1.5 rounded-xl" />
                            </div>
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Durasi (hari) *</Label>
                                <Input
                                    v-model.number="planForm.duration_days"
                                    type="number"
                                    min="0"
                                    max="3650"
                                    required
                                    class="mt-1.5 rounded-xl"
                                />
                                <p class="mt-1 text-xs text-slate-500">0 = tanpa durasi tetap (cocok paket gratis Starter).</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Max Produk</Label>
                                <Input
                                    :model-value="planForm.max_products ?? ''"
                                    type="number"
                                    min="1"
                                    class="mt-1.5 rounded-xl"
                                    placeholder="Kosong = unlimited"
                                    @update:model-value="(v) => { planForm.max_products = toNullableInt(v); }"
                                />
                            </div>
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Max Pelanggan</Label>
                                <Input
                                    :model-value="planForm.max_customers ?? ''"
                                    type="number"
                                    min="1"
                                    class="mt-1.5 rounded-xl"
                                    placeholder="Kosong = unlimited"
                                    @update:model-value="(v) => { planForm.max_customers = toNullableInt(v); }"
                                />
                            </div>
                        </div>
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Fitur (pisahkan dengan baris baru)</Label>
                            <textarea v-model="planFeaturesText" class="w-full mt-1.5 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none resize-none" rows="4" placeholder="Laporan penjualan&#10;Export Excel/PDF&#10;Support prioritas"></textarea>
                        </div>
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="planForm.is_active" class="rounded border-slate-300" />
                                <span class="text-sm text-slate-700 font-medium">Aktif</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="planForm.is_popular" class="rounded border-slate-300" />
                                <span class="text-sm text-slate-700 font-medium">Populer</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <Button type="button" variant="outline" @click="closePlanModal" class="rounded-xl">Batal</Button>
                        <Button type="submit" :disabled="planForm.processing" class="rounded-xl bg-slate-900 text-white">
                            {{ planForm.processing ? 'Menyimpan...' : (editingPlan ? 'Simpan Perubahan' : 'Buat Paket') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create Voucher Modal -->
        <div v-if="showCreateVoucherModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4" @click.self="closeCreateVoucherModal">
            <div class="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="text-lg font-bold text-slate-900 mb-6">Buat Voucher Baru</h3>
                <form @submit.prevent="createVoucher">
                    <div class="space-y-4">
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Kode Voucher *</Label>
                            <Input v-model="voucherForm.code" required class="mt-1.5 rounded-xl font-mono" placeholder="PROMO2025" />
                        </div>
                        <div>
                            <Label class="text-sm font-medium text-slate-700">Nama Voucher *</Label>
                            <Input v-model="voucherForm.name" required class="mt-1.5 rounded-xl" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Hari *</Label>
                                <Input v-model="voucherForm.days" type="number" min="1" max="365" required class="mt-1.5 rounded-xl" />
                            </div>
                            <div>
                                <Label class="text-sm font-medium text-slate-700">Harga (Rp) *</Label>
                                <Input v-model="voucherForm.price" type="number" min="0" required class="mt-1.5 rounded-xl" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <Button type="button" variant="outline" @click="closeCreateVoucherModal" class="rounded-xl">Batal</Button>
                        <Button type="submit" :disabled="voucherForm.processing" class="rounded-xl bg-slate-900 text-white">
                            {{ voucherForm.processing ? 'Membuat...' : 'Buat Voucher' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Building2,
    CheckCircle,
    XCircle,
    AlertTriangle,
    Plus,
    Ticket,
    Crown,
    Shield,
    Clock,
    CreditCard,
    DollarSign,
    Edit,
    Trash2,
    LogOut,
    Landmark,
    FlaskConical,
    Square,
    History,
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { logout } from '@/routes';
import {
    toastError,
    toastSuccess,
    toastSuccessThenReload,
    confirmDialog,
    confirmDelete,
} from '@/lib/swal';

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
    stores: { data: Store[]; links: any[]; };
    vouchers: Voucher[];
    plans: Plan[];
    recentPayments: Payment[];
    transferSettings?: TransferSettings;
    attendancePayrollPricing?: { monthly_price: number; yearly_price: number; };
    user: { name: string; };
}

const props = defineProps<Props>();

const handleLogout = () => {
    router.flushAll();
};

// Tab system
const tabs = computed(() => [
    { id: 'stores', label: 'Toko', icon: Building2, badge: null },
    { id: 'payments', label: 'Pembayaran', icon: CreditCard, badge: safeStats.value.pending_payments > 0 ? safeStats.value.pending_payments : null },
    { id: 'transfer', label: 'Rekening & QRIS', icon: Landmark, badge: null },
    { id: 'plans', label: 'Paket', icon: Crown, badge: null },
    { id: 'vouchers', label: 'Voucher', icon: Ticket, badge: null },
    { id: 'trial-logs', label: 'Log Trial', icon: History, badge: null },
]);
const activeTab = ref('stores');

// Safe computed props
const safeStats = computed(() => props.stats || { total_stores: 0, active_stores: 0, expired_stores: 0, expiring_soon: 0, pending_payments: 0, total_revenue: 0 });
const safeStores = computed(() => props.stores || { data: [], links: [] });
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
const safeAttendancePayrollPricing = computed(
    () => props.attendancePayrollPricing ?? { monthly_price: 49000, yearly_price: 490000 },
);
const safeUser = computed(() => props.user || { name: 'User' });

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
    router.post('/superadmin/attendance-payroll-pricing', {
        monthly_price: attendancePayrollPricingForm.value.monthly_price,
        yearly_price: attendancePayrollPricingForm.value.yearly_price,
    }, {
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
    });
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
const voucherForm = ref({ code: '', name: '', days: 30, price: 0, processing: false });
const planForm = ref({
    name: '', description: '', price: 0, duration_days: 30,
    max_products: null as number | null, max_customers: null as number | null,
    is_active: true, is_popular: false, sort_order: 0,
    billing_cycle: 'monthly' as 'monthly' | 'yearly',
    processing: false
});
const planFeaturesText = ref('');
const trialForm = ref({ processing: false });

const page = usePage();

/** Token dari props Inertia (selaras sesi setelah navigasi); fallback meta untuk kompatibilitas. */
const getCSRF = () => {
    const t = (page.props as { csrf_token?: string }).csrf_token;
    if (typeof t === 'string' && t.length > 0) {
        return t;
    }
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

// ===== Store Actions =====
const openExtendModal = (store: Store) => { selectedStore.value = store; showExtendModal.value = true; };
const closeExtendModal = () => { showExtendModal.value = false; selectedStore.value = null; extendForm.value = { days: 30, notes: '', processing: false }; extendCustomMonths.value = null; };

const extendSubscription = async () => {
    if (!selectedStore.value) return;
    extendForm.value.processing = true;
    try {
        const res = await fetch(`/superadmin/stores/${selectedStore.value.slug}/extend`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF() },
            body: JSON.stringify({ days: extendForm.value.days, notes: extendForm.value.notes }),
        });
        if (res.ok) {
            closeExtendModal();
            await toastSuccessThenReload('Subscription diperpanjang');
        } else {
            await toastError('Gagal memperpanjang subscription');
        }
    } catch {
        await toastError('Terjadi kesalahan');
    }
    finally { extendForm.value.processing = false; }
};

const toggleStoreStatus = async (store: Store) => {
    const ok = await confirmDialog(
        store.is_active ? 'Nonaktifkan toko?' : 'Aktifkan toko?',
        `Toko "${store.name}"`,
    );
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/stores/${store.slug}/toggle`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
const openTrialModal = (store: Store) => { selectedTrialStore.value = store; showTrialModal.value = true; };
const closeTrialModal = () => { showTrialModal.value = false; selectedTrialStore.value = null; trialForm.value = { processing: false }; };

const activateTrial = async () => {
    if (!selectedTrialStore.value) return;
    trialForm.value.processing = true;
    try {
        const res = await fetch(`/superadmin/stores/${selectedTrialStore.value.slug}/activate-trial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
    }
    finally { trialForm.value.processing = false; }
};

const stopTrial = async (store: Store) => {
    const ok = await confirmDialog(
        'Hentikan Trial Pro?',
        `Trial untuk toko "${store.name}" akan dihentikan dan toko akan kembali ke paket Starter.`,
    );
    if (!ok) return;
    try {
        const res = await fetch(`/superadmin/stores/${store.slug}/stop-trial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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

const openRejectModal = (payment: Payment) => { selectedPayment.value = payment; rejectNotes.value = ''; showRejectModal.value = true; };
const closeRejectModal = () => { showRejectModal.value = false; selectedPayment.value = null; };

const rejectPayment = async () => {
    if (!selectedPayment.value || !rejectNotes.value) return;
    try {
        const res = await fetch(`/superadmin/payments/${selectedPayment.value.id}/reject`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
    planForm.value = { name: '', description: '', price: 0, duration_days: 30, max_products: null, max_customers: null, is_active: true, is_popular: false, sort_order: 0, billing_cycle: 'monthly', processing: false };
    planFeaturesText.value = '';
    showPlanModal.value = true;
};

const openEditPlanModal = (plan: Plan) => {
    editingPlan.value = plan;
    planForm.value = { 
        name: plan.name, description: plan.description || '', price: plan.price, duration_days: plan.duration_days,
        max_products: plan.max_products, max_customers: plan.max_customers,
        is_active: plan.is_active, is_popular: plan.is_popular, sort_order: plan.sort_order,
        billing_cycle: plan.billing_cycle ?? 'monthly',
        processing: false 
    };
    planFeaturesText.value = (plan.features || []).join('\n');
    showPlanModal.value = true;
};

const closePlanModal = () => { showPlanModal.value = false; editingPlan.value = null; };

const toNullableInt = (v: unknown): number | null => {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
};

const savePlan = () => {
    planForm.value.processing = true;
    const features = planFeaturesText.value.split('\n').map(f => f.trim()).filter(f => f);
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
            headers: { 'X-CSRF-TOKEN': getCSRF(), 'Accept': 'application/json' },
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
const openCreateVoucherModal = () => { voucherForm.value = { code: '', name: '', days: 30, price: 0, processing: false }; showCreateVoucherModal.value = true; };
const closeCreateVoucherModal = () => { showCreateVoucherModal.value = false; };

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
    }
    finally { voucherForm.value.processing = false; }
};

// ===== Helpers =====
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

const getStoreStatusClass = (store: Store) => {
    const status = getStoreStatus(store);
    if (status === 'Aktif') return 'bg-green-100 text-green-800 text-xs';
    if (status === 'Expired') return 'bg-red-100 text-red-800 text-xs';
    return 'bg-slate-100 text-slate-600 text-xs';
};

const getPaymentStatusLabel = (status: string) => {
    const labels: Record<string, string> = { pending: 'Menunggu', confirmed: 'Dikonfirmasi', rejected: 'Ditolak', expired: 'Kedaluwarsa' };
    return labels[status] || status;
};

const getPaymentStatusClass = (status: string) => {
    const classes: Record<string, string> = { 
        pending: 'bg-amber-100 text-amber-800 text-xs', 
        confirmed: 'bg-green-100 text-green-800 text-xs', 
        rejected: 'bg-red-100 text-red-800 text-xs', 
        expired: 'bg-slate-100 text-slate-600 text-xs' 
    };
    return classes[status] || 'bg-slate-100 text-slate-600 text-xs';
};

const getMethodLabel = (method: string) => {
    const labels: Record<string, string> = { bank_transfer: 'Transfer Bank', e_wallet: 'E-Wallet', qris: 'QRIS', cash: 'Tunai' };
    return labels[method] || method;
};

const getDaysRemaining = (endDate: string) => Math.max(0, Math.ceil((new Date(endDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24)));
const getDaysRemainingClass = (endDate: string) => {
    const d = getDaysRemaining(endDate);
    if (d <= 0) return 'text-red-600 font-bold';
    if (d <= 7) return 'text-amber-600 font-bold';
    return 'text-slate-400';
};

const formatDate = (date: string) => new Date(date).toLocaleDateString('id-ID');
const formatDateTime = (date: string) => new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const formatCurrency = (amount: number) => new Intl.NumberFormat('id-ID').format(amount);
const formatCurrencyShort = (amount: number) => {
    if (amount >= 1_000_000) return (amount / 1_000_000).toFixed(1) + 'jt';
    if (amount >= 1_000) return (amount / 1_000).toFixed(0) + 'rb';
    return amount.toString();
};
</script>
