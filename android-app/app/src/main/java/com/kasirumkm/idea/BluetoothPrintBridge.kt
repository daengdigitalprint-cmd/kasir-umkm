package com.kasirumkm.idea

import android.annotation.SuppressLint
import android.app.Activity
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothSocket
import android.webkit.JavascriptInterface
import android.webkit.WebView
import org.json.JSONArray
import org.json.JSONObject
import java.io.OutputStream
import java.util.UUID
import java.util.concurrent.Executors

class BluetoothPrintBridge(
    private val activity: Activity,
    private val webView: WebView,
    private val permissionHandler: PermissionHandler,
) {
    private data class PrinterConnection(
        val mac: String,
        var socket: BluetoothSocket? = null,
        var outputStream: OutputStream? = null,
    )

    private val connections = mutableMapOf<String, PrinterConnection>()
    private val btAdapter: BluetoothAdapter? = BluetoothAdapter.getDefaultAdapter()
    private val executor = Executors.newSingleThreadExecutor()

    // SPP UUID — standar untuk thermal printer Bluetooth Classic
    private val sppUuid: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    private fun validRole(role: String): Boolean = role == "cashier" || role == "kitchen"

    // ─── SCAN PAIRED DEVICES ──────────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    @JavascriptInterface
    fun scanDevices() {
        executor.execute {
            try {
                if (!permissionHandler.hasBluetoothPermissions()) {
                    throw SecurityException("Izin Bluetooth belum diberikan")
                }

                val paired = btAdapter?.bondedDevices ?: emptySet()
                val list = JSONArray()
                paired.forEach { device ->
                    val item = JSONObject()
                    item.put("name", device.name ?: "Unknown")
                    item.put("mac", device.address)
                    list.put(item)
                }
                invokeJs("window.__btCallback('scan', true, $list, null)")
            } catch (e: Exception) {
                invokeJs(
                    "window.__btCallback('scan', false, null, '${escapeJs(e.message ?: "Gagal scan")}')",
                )
            }
        }
    }

    // ─── CONNECT ──────────────────────────────────────────────────────────────

    @JavascriptInterface
    fun connect(macAddress: String) {
        connect(macAddress, "cashier")
    }

    @SuppressLint("MissingPermission")
    @JavascriptInterface
    fun connect(macAddress: String, role: String) {
        if (!validRole(role)) {
            invokeJs("window.__btCallback('connect', false, null, 'Role printer tidak valid')")
            return
        }

        val cleanMac = macAddress.uppercase().trim()
        executor.execute {
            try {
                if (!permissionHandler.hasBluetoothPermissions()) {
                    throw SecurityException("Izin Bluetooth belum diberikan")
                }

                if (btAdapter == null) {
                    throw Exception("Bluetooth tidak didukung")
                }

                if (!btAdapter.isEnabled) {
                    throw Exception("Bluetooth nonaktif")
                }

                disconnectInternal(role)

                val device = btAdapter.getRemoteDevice(cleanMac)
                    ?: throw Exception("Device $cleanMac tidak ditemukan")

                btAdapter.cancelDiscovery()
                Thread.sleep(500)

                // Strategi koneksi 3-Tahap Agresif
                var connectedSocket: BluetoothSocket? = null
                var connected = false
                var lastErrorMessage = ""

                // Tahap 1 & 2: UUID Standard (Secure & Insecure)
                val methods = listOf("createRfcommSocketToServiceRecord", "createInsecureRfcommSocketToServiceRecord")
                for (methodName in methods) {
                    if (connected) break
                    try {
                        val method = device.javaClass.getMethod(methodName, UUID::class.java)
                        connectedSocket = method.invoke(device, sppUuid) as BluetoothSocket
                        connectedSocket.connect()
                        connected = true
                    } catch (e: Exception) {
                        lastErrorMessage = e.cause?.message ?: e.message ?: "Gagal $methodName"
                        try { connectedSocket?.close() } catch (_: Exception) {}
                        connectedSocket = null
                    }
                }

                // Tahap 3: Direct Channel 1 Reflection (Khusus RPP02N/ECO 80BT)
                if (!connected) {
                    try {
                        val m = device.javaClass.getMethod("createRfcommSocket", Int::class.javaPrimitiveType)
                        connectedSocket = m.invoke(device, 1) as BluetoothSocket
                        connectedSocket!!.connect()
                        connected = true
                    } catch (e: Exception) {
                        lastErrorMessage = e.cause?.message ?: e.message ?: "Gagal Channel 1"
                        try { connectedSocket?.close() } catch (_: Exception) {}
                        connectedSocket = null
                    }
                }

                if (!connected || connectedSocket == null) {
                    throw Exception("Printer menolak koneksi: $lastErrorMessage")
                }

                val outputStream = connectedSocket.outputStream
                connections[role] = PrinterConnection(cleanMac, connectedSocket, outputStream)

                Thread.sleep(500)
                val data = JSONObject().apply {
                    put("role", role)
                    put("mac", cleanMac)
                }
                invokeJs("window.__btCallback('connect', true, $data, null)")

            } catch (e: Exception) {
                disconnectInternal(role)
                val errorMsg = e.message ?: "Gagal koneksi"
                invokeJs("window.__btCallback('connect', false, null, '${escapeJs(errorMsg)}')")
            }
        }
    }

    // ─── PRINT ───────────────────────────────────────────────────────────────

    @JavascriptInterface
    fun print(escposHex: String) {
        print(escposHex, "cashier")
    }

    @JavascriptInterface
    fun print(escposHex: String, role: String) {
        if (!validRole(role)) {
            invokeJs("window.__btCallback('print', false, null, 'Role printer tidak valid')")
            return
        }

        if (escposHex.isEmpty()) {
            invokeJs("window.__btCallback('print', false, role, 'Data cetak kosong')")
            return
        }

        executor.execute {
            try {
                val connection = connections[role]
                val outputStream = connection?.outputStream
                val socket = connection?.socket

                if (outputStream == null || socket?.isConnected != true) {
                    throw Exception("Printer $role terputus. Silakan hubungkan kembali.")
                }

                val bytes = hexStringToBytes(escposHex)

                // Chunked transfer untuk stabilitas ECO 80BT (RPP02N)
                // 512 bytes per chunk dengan delay 20ms untuk mencegah buffer overflow
                val chunkSize = 512
                var offset = 0
                while (offset < bytes.size) {
                    val count = Math.min(chunkSize, bytes.size - offset)
                    outputStream.write(bytes, offset, count)
                    outputStream.flush()
                    offset += count

                    if (offset < bytes.size) {
                        Thread.sleep(20)
                    }
                }

                invokeJs("window.__btCallback('print', true, '$role', null)")
            } catch (e: Exception) {
                val msg = e.message ?: "Gagal mencetak"
                invokeJs("window.__btCallback('print', false, '$role', '${escapeJs(msg)}')")
            }
        }
    }

    // ─── KICK DRAWER ──────────────────────────────────────────────────────────

    @JavascriptInterface
    fun kickDrawer() {
        executor.execute {
            try {
                // Kick drawer default ke cashier
                val connection = connections["cashier"]
                val outputStream = connection?.outputStream
                val socket = connection?.socket

                if (outputStream == null || socket?.isConnected != true) {
                    throw Exception("Printer kasir tidak terkoneksi. Hubungkan terlebih dahulu.")
                }

                // ESC/POS standard cash drawer kick command
                val cmd = byteArrayOf(0x1B, 0x70, 0x00, 0x19.toByte(), 0xFA.toByte())
                outputStream.write(cmd)
                outputStream.flush()

                invokeJs("window.__btCallback('drawer', true, null, null)")
            } catch (e: Exception) {
                val msg = e.message ?: "Gagal membuka laci"
                invokeJs(
                    "window.__btCallback('drawer', false, null, '${escapeJs(msg)}')",
                )
            }
        }
    }

    // ─── STATUS & DISCONNECT ──────────────────────────────────────────────────

    @JavascriptInterface
    fun disconnect() {
        disconnectAll()
    }

    @JavascriptInterface
    fun disconnect(role: String) {
        executor.execute {
            disconnectInternal(role)
        }
    }

    @JavascriptInterface
    fun disconnectAll() {
        executor.execute {
            connections.keys.toList().forEach { role ->
                disconnectInternal(role)
            }
        }
    }

    @JavascriptInterface
    fun isConnected(): Boolean = isConnected("cashier")

    @JavascriptInterface
    fun isConnected(role: String): Boolean =
        connections[role]?.socket?.isConnected == true

    @JavascriptInterface
    fun getConnections(): String {
        val result = JSONObject()
        connections.forEach { (role, conn) ->
            val item = JSONObject()
            item.put("mac", conn.mac)
            item.put("connected", conn.socket?.isConnected == true)
            result.put(role, item)
        }
        return result.toString()
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private fun disconnectInternal(role: String) {
        val conn = connections[role]
        try {
            conn?.outputStream?.close()
        } catch (_: Exception) {}
        try {
            conn?.socket?.close()
        } catch (_: Exception) {}
        connections.remove(role)
    }

    private fun invokeJs(script: String) {
        activity.runOnUiThread {
            webView.evaluateJavascript(script, null)
        }
    }

    private fun escapeJs(value: String): String {
        return value
            .replace("\\", "\\\\")
            .replace("'", "\\'")
            .replace("\n", "\\n")
            .replace("\r", "")
    }

    private fun hexStringToBytes(hex: String): ByteArray {
        val clean = hex.replace("\\s".toRegex(), "")
        val len = clean.length
        if (len % 2 != 0) {
            throw IllegalArgumentException("Hex string tidak valid")
        }
        val data = ByteArray(len / 2)
        var i = 0
        while (i < len) {
            data[i / 2] = (
                (Character.digit(clean[i], 16) shl 4) +
                    Character.digit(clean[i + 1], 16)
                ).toByte()
            i += 2
        }
        return data
    }
}
