<ScrollView class="flex-1 bg-gray-50">
    <View class="p-4">
        <!-- Header -->
        <View class="mb-6">
            <Text class="text-2xl font-bold text-gray-900">Pengaturan Printer</Text>
            <Text class="text-sm text-gray-500">Hubungkan printer thermal via Bluetooth</Text>
        </View>

        <!-- Status & Aksi -->
        <View class="mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
            <View class="flex-row justify-between items-center mb-4">
                <Text class="text-base font-semibold text-gray-800">Status Perangkat</Text>
                <Button 
                    title="Refresh" 
                    onPress="loadPairedDevices"
                    class="bg-gray-100 text-gray-700 px-3 py-1 rounded-md text-sm"
                />
            </View>
            
            <View class="flex-row items-center mb-2">
                <Text class="text-gray-600 w-24">Dipilih:</Text>
                <Text class="font-medium text-gray-900 flex-1">
                    {{ $selectedDeviceAddress ?? 'Belum ada' }}
                </Text>
            </View>
            
            <View class="flex-row items-center">
                <Text class="text-gray-600 w-24">Status:</Text>
                <Text class="font-medium {{ $selectedDeviceAddress ? 'text-green-600' : 'text-orange-500' }}">
                    {{ $connectionStatus }}
                </Text>
            </View>

            @if($testPrintStatus)
                <View class="mt-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <Text class="text-sm text-gray-700">{{ $testPrintStatus }}</Text>
                </View>
            @endif

            <Button 
                title="Test Print" 
                onPress="testPrint"
                disabled="{{ !$selectedDeviceAddress }}"
                class="mt-4 {{ $selectedDeviceAddress ? 'bg-indigo-600' : 'bg-gray-300' }} text-white py-3 rounded-lg font-semibold w-full"
            />
        </View>

        <!-- Daftar Perangkat -->
        <View class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <View class="p-4 border-b border-gray-100 bg-gray-50">
                <Text class="text-base font-semibold text-gray-800">Perangkat Terpasangkan (Paired)</Text>
                <Text class="text-xs text-gray-500 mt-1">Pastikan printer sudah di-pairing melalui pengaturan Bluetooth HP Anda.</Text>
            </View>

            <View class="divide-y divide-gray-100">
                @forelse($pairedDevices as $device)
                    <TouchableOpacity 
                        onPress="selectDevice('{{ is_array($device) ? $device['address'] : $device->address }}')"
                        class="p-4 flex-row items-center justify-between {{ $selectedDeviceAddress === (is_array($device) ? $device['address'] : $device->address) ? 'bg-indigo-50' : 'bg-white' }}"
                    >
                        <View class="flex-1">
                            <Text class="text-base font-medium text-gray-900">
                                {{ is_array($device) ? $device['name'] : $device->name }}
                            </Text>
                            <Text class="text-sm text-gray-500 mt-1">
                                {{ is_array($device) ? $device['address'] : $device->address }}
                            </Text>
                        </View>
                        
                        @if($selectedDeviceAddress === (is_array($device) ? $device['address'] : $device->address))
                            <View class="bg-indigo-600 rounded-full p-1">
                                <Text class="text-white text-xs px-2 py-0.5">Aktif</Text>
                            </View>
                        @endif
                    </TouchableOpacity>
                @empty
                    <View class="p-8 items-center justify-center">
                        <Text class="text-gray-400 text-center">Tidak ada perangkat Bluetooth yang terpasangkan.</Text>
                        <Text class="text-xs text-gray-400 text-center mt-2">Buka Pengaturan HP > Bluetooth > Pair printer Anda.</Text>
                    </View>
                @endforelse
            </View>
        </View>
    </View>
</ScrollView>