<?php

namespace App\Services\Printer;

class EscPosEncoder
{
    /**
     * Encode an array of text lines into ESC/POS bytes.
     * Includes basic printer initialization and paper cut commands.
     */
    public static function textLinesToBytes(array $lines): array
    {
        // ESC @ - Initialize printer
        $bytes = [0x1B, 0x40];

        foreach ($lines as $line) {
            $stringBytes = array_values(unpack('C*', $line));
            $bytes = array_merge($bytes, $stringBytes);
            // LF - Line feed
            $bytes[] = 0x0A;
        }

        // Feed some lines and cut paper
        // LF LF
        $bytes[] = 0x0A;
        $bytes[] = 0x0A;
        // GS V 1 - Full cut
        $bytes = array_merge($bytes, [0x1D, 0x56, 0x01]);

        return $bytes;
    }

    /**
     * Get maximum character width for a given paper size mm
     */
    public static function getMaxChars(int $paperWidthMm = 58): int
    {
        return $paperWidthMm === 58 ? 32 : 48; // Typical 32 chars for 58mm, 48 for 80mm
    }
}
