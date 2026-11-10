import Foundation

enum BluetoothPrinterFunctions {

    class GetPairedDevices: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // iOS does not allow listing paired classic bluetooth devices generically.
            // ExternalAccessory framework requires explicit protocol strings in Info.plist.
            // For this phase, we'll return an empty list or an error indicating iOS limitation.
            return BridgeResponse.success(data: [])
        }
    }
    
    class Print: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.error(message: "iOS Bluetooth printing not implemented in this phase")
        }
    }
}