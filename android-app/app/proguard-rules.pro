# Jaga Bridge class dari obfuscation
-keep class com.umkmkasir.BluetoothPrintBridge { *; }
-keepclassmembers class com.umkmkasir.BluetoothPrintBridge {
    @android.webkit.JavascriptInterface <methods>;
}

# WebView JS Interface
-keepattributes JavascriptInterface
