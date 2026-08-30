<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { 
    Users, 
    Plus, 
    Search, 
    Edit, 
    Trash2, 
    Eye,
    Store,
    Grid3X3,
    List,
    Phone,
    MapPin,
    Calendar,
    DollarSign,
    Home,
    Settings,
    LogOut,
    Menu,
    X,
    Package,
    BarChart3,
    ShoppingCart
} from 'lucide-vue-next';
import { ref, computed } from 'vue';

interface Customer {
    id: number;
    name: string;
    email: string;
    phone: string;
    address: string;
    total_purchases: number;
    last_purchase: string | null;
    created_at: string;
}

interface Props {
    customers: Customer[] | {
        data: Customer[];
        links: any[];
        meta: any;
    };
    store: {
        id: number;
        name: string;
        currency: string;
    };
}

const props = defineProps<Props>();

const searchQuery = ref('');
const viewMode = ref<'grid' | 'list'>('list');

// Mobile menu state
const isMobileMenuOpen = ref(false);

// Safe access to customers data
const customersList = computed(() => {
    if (Array.isArray(props.customers)) {
        return props.customers;
    }
    return props.customers?.data || [];
});

const customersMeta = computed(() => {
    if (Array.isArray(props.customers)) {
        return { total: props.customers.length };
    }
    return props.customers?.meta || { total: 0 };
});

const filteredCustomers = computed(() => {
    if (!searchQuery.value) return customersList.value;
    
    const query = searchQuery.value.toLowerCase();
    return customersList.value.filter(customer => 
        customer.name.toLowerCase().includes(query) ||
        customer.email.toLowerCase().includes(query) ||
        customer.phone.includes(query) ||
        customer.address.toLowerCase().includes(query)
    );
});


// Toggle mobile menu
const toggleMobileMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
};

// Close mobile menu
const closeMobileMenu = () => {
    isMobileMenuOpen.value = false;
};

// Format currency helper
const formatCurrency = (amount: number | null | undefined) => {
    const safeAmount = amount || 0;
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(safeAmount);
};

// Format date helper
const formatDate = (dateString: string | null) => {
    if (!dateString) return 'Belum ada';
    return new Date(dateString).toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};


import AdminLayout from '@/layouts/AdminLayout.vue';
</script>


<template>
    <AdminLayout title="Manajemen Pelanggan">
        
        <!-- Search and Filters -->
                    <Card class="border-0 shadow-lg mb-4 lg:mb-6">
                        <CardContent class="p-4 lg:p-6">
                            <div class="flex flex-col lg:flex-row gap-3 lg:gap-4">
                                <!-- Search -->
                                <div class="flex-1">
                                    <div class="relative">
                                        <Search class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                                        <Input
                                            v-model="searchQuery"
                                            placeholder="Cari pelanggan berdasarkan nama, email, telepon..."
                                            class="pl-10 h-10 lg:h-12 border-gray-300 focus:border-primary/70 focus:ring-primary/70 text-sm lg:text-base"
                                        />
                                    </div>
                                </div>
                                
                                <!-- View Mode Toggle - Hidden on mobile -->
                                <div class="hidden lg:flex items-center gap-2">
                                    <Button
                                        :variant="viewMode === 'grid' ? 'default' : 'outline'"
                                        size="sm"
                                        @click="viewMode = 'grid'"
                                        class="h-8 px-3 text-xs"
                                    >
                                        <Grid3X3 class="h-3 w-3 mr-1" />
                                        Grid
                                    </Button>
                                    <Button
                                        :variant="viewMode === 'list' ? 'default' : 'outline'"
                                        size="sm"
                                        @click="viewMode = 'list'"
                                        class="h-8 px-3 text-xs"
                                    >
                                        <List class="h-3 w-3 mr-1" />
                                        List
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Customers List -->
                    <Card class="border-0 shadow-lg mb-8 lg:mb-6">
                        <CardHeader class="pb-3 lg:pb-4 px-4 lg:px-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div>
                                    <CardTitle class="text-lg lg:text-xl font-bold text-gray-900">Daftar Pelanggan</CardTitle>
                                    <CardDescription class="text-sm lg:text-base text-gray-600">
                                        {{ filteredCustomers.length }} dari {{ customersMeta.total || 0 }} pelanggan
                                    </CardDescription>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Users class="h-4 w-4 lg:h-5 lg:w-5 text-gray-400" />
                                    <span class="text-xs lg:text-sm text-gray-500">{{ viewMode === 'grid' ? 'Grid' : 'List' }} View</span>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent class="px-4 lg:px-6">
                    <!-- Grid View - Only on desktop -->
                    <div v-if="viewMode === 'grid'" class="hidden lg:grid gap-4 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                                <Card 
                                    v-for="customer in filteredCustomers" 
                                    :key="customer.id"
                                    class="hover:shadow-lg transition-all duration-200 border-0 shadow-md"
                                >
                                    <CardContent class="p-3 md:p-4">
                                        <div class="flex items-center gap-3 mb-3 md:mb-4">
                                            <div class="w-10 h-10 md:w-12 md:h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                                                <Users class="h-5 w-5 md:h-6 md:w-6 text-primary" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <h3 class="font-semibold text-sm md:text-base text-gray-900 truncate">{{ customer.name }}</h3>
                                                <p class="text-xs md:text-sm text-gray-500 truncate">{{ customer.email }}</p>
                                            </div>
                                        </div>
                                        
                                        <div class="space-y-2 mb-3 md:mb-4">
                                            <div class="flex items-center gap-2 text-xs md:text-sm text-gray-600">
                                                <Phone class="h-3 w-3 md:h-4 md:w-4 flex-shrink-0" />
                                                <span class="truncate">{{ customer.phone }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-xs md:text-sm text-gray-600">
                                                <MapPin class="h-3 w-3 md:h-4 md:w-4 flex-shrink-0" />
                                                <span class="truncate">{{ customer.address }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-xs md:text-sm text-gray-600">
                                                <DollarSign class="h-3 w-3 md:h-4 md:w-4 flex-shrink-0" />
                                                <span class="truncate">Total: {{ formatCurrency(customer.total_purchases) }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-xs md:text-sm text-gray-600">
                                                <Calendar class="h-3 w-3 md:h-4 md:w-4 flex-shrink-0" />
                                                <span class="truncate">Terakhir: {{ formatDate(customer.last_purchase) }}</span>
                                            </div>
                                        </div>
                                        
                                        <div class="flex gap-1 md:gap-2">
                                            <Button 
                                                as="a"
                                                :href="`/customers/${customer.id}`"
                                                variant="outline"
                                                size="sm"
                                                class="flex-1 h-7 md:h-8 text-xs"
                                            >
                                                <Eye class="h-3 w-3 mr-1" />
                                                <span class="hidden sm:inline">Lihat</span>
                                            </Button>
                                            <Button 
                                                as="a"
                                                :href="`/customers/${customer.id}/edit`"
                                                variant="outline"
                                                size="sm"
                                                class="flex-1 h-7 md:h-8 text-xs"
                                            >
                                                <Edit class="h-3 w-3 mr-1" />
                                                <span class="hidden sm:inline">Edit</span>
                                            </Button>
                                            <Button 
                                                variant="outline"
                                                size="sm"
                                                class="h-7 w-7 md:h-8 md:w-8 p-0 text-red-600 hover:text-red-700 hover:bg-red-50"
                                            >
                                                <Trash2 class="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- List View -->
                            <div v-else class="space-y-3">
                                <div 
                                    v-for="customer in filteredCustomers" 
                                    :key="customer.id"
                                    class="flex items-center gap-3 sm:gap-4 p-3 lg:p-4 bg-gray-50 rounded-2xl border hover:bg-gray-100 transition-colors"
                                >
                                    <div class="w-16 h-16 sm:w-12 sm:h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                                        <Users class="h-8 w-8 sm:h-6 sm:w-6 text-primary" />
                                    </div>
                                    
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-semibold text-gray-900 truncate text-sm sm:text-base">{{ customer.name }}</h3>
                                        <p class="text-xs sm:text-sm text-gray-500 truncate mt-1">{{ customer.email }}</p>
                                        <div class="flex items-center gap-2 mt-2">
                                            <span class="flex items-center gap-1 text-xs text-gray-500">
                                                <Phone class="h-3 w-3" />
                                                {{ customer.phone }}
                                            </span>
                                            <span class="text-xs text-gray-400">•</span>
                                            <span class="flex items-center gap-1 text-xs text-gray-500">
                                                <DollarSign class="h-3 w-3" />
                                                {{ formatCurrency(customer.total_purchases) }}
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex gap-2 flex-shrink-0">
                                        <Button 
                                            as="a"
                                            :href="`/customers/${customer.id}`"
                                            variant="outline"
                                            size="sm"
                                            class="h-8 w-8 p-0"
                                        >
                                            <Eye class="h-3 w-3" />
                                        </Button>
                                        <Button 
                                            as="a"
                                            :href="`/customers/${customer.id}/edit`"
                                            variant="outline"
                                            size="sm"
                                            class="h-8 w-8 p-0"
                                        >
                                            <Edit class="h-3 w-3" />
                                        </Button>
                                        <Button 
                                            variant="outline"
                                            size="sm"
                                            class="h-8 w-8 p-0 text-red-600 hover:text-red-700 hover:bg-red-50"
                                        >
                                            <Trash2 class="h-3 w-3" />
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Empty State -->
                            <div v-if="filteredCustomers.length === 0" class="text-center py-8 lg:py-12">
                                <div class="p-4 bg-gray-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                    <Users class="h-8 w-8 text-gray-400" />
                                </div>
                                <p class="text-gray-500 text-base lg:text-lg">Tidak ada pelanggan ditemukan</p>
                                <p class="text-gray-400 text-xs lg:text-sm mt-2">
                                    {{ searchQuery ? 'Coba kata kunci pencarian yang berbeda' : 'Mulai dengan menambahkan pelanggan pertama Anda' }}
                                </p>
                                <Button 
                                    v-if="!searchQuery"
                                    as="a" 
                                    href="/customers/create" 
                                    class="mt-4 bg-primary hover:bg-primary/90 text-white text-sm lg:text-base"
                                >
                                    <Plus class="h-4 w-4 mr-2" />
                                    Tambah Pelanggan Pertama
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
    </AdminLayout>
</template>
