package com.nativephp.plugins.bluetoothprinter

import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.content.Context
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.NativeApplication
import java.io.IOException
import java.io.OutputStream
import java.util.UUID

object BluetoothPrinterFunctions {

    // Standard SPP UUID
    private val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    class GetPairedDevices : BridgeFunction {
        @SuppressLint("MissingPermission") // Permissions handled by manifest
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val context = NativeApplication.context
            val bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
            val bluetoothAdapter = bluetoothManager.adapter

            if (bluetoothAdapter == null) {
                return BridgeResponse.error("Bluetooth is not supported on this device")
            }

            if (!bluetoothAdapter.isEnabled) {
                return BridgeResponse.error("Bluetooth is not enabled")
            }

            val pairedDevices: Set<BluetoothDevice> = bluetoothAdapter.bondedDevices
            val deviceList = mutableListOf<Map<String, String>>()

            for (device in pairedDevices) {
                deviceList.add(
                    mapOf(
                        "name" to (device.name ?: "Unknown"),
                        "mac" to device.address
                    )
                )
            }

            return BridgeResponse.success(deviceList)
        }
    }

    class Print : BridgeFunction {
        @SuppressLint("MissingPermission")
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val macAddress = parameters["mac"] as? String ?: return BridgeResponse.error("MAC address is required")
            
            // Expected list of integers from PHP (0-255)
            val bytesList = parameters["bytes"] as? List<*> ?: return BridgeResponse.error("Bytes payload is required")
            val byteArray = ByteArray(bytesList.size)
            for (i in bytesList.indices) {
                byteArray[i] = (bytesList[i] as Number).toByte()
            }

            val context = NativeApplication.context
            val bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
            val bluetoothAdapter = bluetoothManager.adapter

            if (bluetoothAdapter == null || !bluetoothAdapter.isEnabled) {
                return BridgeResponse.error("Bluetooth is not available")
            }

            var outputStream: OutputStream? = null
            var socket: android.bluetooth.BluetoothSocket? = null

            try {
                val device = bluetoothAdapter.getRemoteDevice(macAddress)
                
                // Fallback to insecure if secure fails on some printers
                socket = device.createRfcommSocketToServiceRecord(SPP_UUID)
                
                // Cancel discovery before connecting as it slows down connection
                bluetoothAdapter.cancelDiscovery()
                
                socket.connect()
                outputStream = socket.outputStream
                
                outputStream.write(byteArray)
                outputStream.flush()
                
                return BridgeResponse.success(mapOf("status" to "success"))
            } catch (e: Exception) {
                return BridgeResponse.error("Failed to print: ${e.message}")
            } finally {
                try {
                    outputStream?.close()
                    socket?.close()
                } catch (e: IOException) {
                    // Ignore close errors
                }
            }
        }
    }
}
