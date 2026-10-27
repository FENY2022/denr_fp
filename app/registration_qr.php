<?php
declare(strict_types=1);

/**
 * Generate a self-contained, scannable PNG for applicant FP-OMS login links.
 *
 * QR matrix: MIT-licensed kazuhikoarase/qrcode-generator (bundled locally).
 * PNG output: 1-bit grayscale using PHP's zlib, without GD, remote QR APIs,
 * public storage, or external HTTP requests.
 *
 * The QR contains only the ordinary system login URL, never a password,
 * verification token, one-time login token, or applicant identity.
 */
function registration_login_qr_png(string $loginUrl): ?string
{
    if ($loginUrl === '' || strlen($loginUrl) > 512 || !function_exists('gzcompress')) {
        return null;
    }

    require_once PROJECT_ROOT . '/lib/qrcode-generator/qrcode.php';

    $qr = QRCode::getMinimumQRCode($loginUrl, QR_ERROR_CORRECT_LEVEL_M);
    $count = (int)$qr->getModuleCount();
    // QR version 1 through 40: 21 to 177 modules.
    if ($count < 21 || $count > 177) return null;

    $quietZone = 4; // ISO-recommended four-module white border.
    $scale = 5;
    $width = ($count + $quietZone * 2) * $scale;
    $pad = (8 - $width % 8) % 8;

    $scanlines = '';
    for ($row = -$quietZone; $row < $count + $quietZone; $row++) {
        $bits = '';
        for ($col = -$quietZone; $col < $count + $quietZone; $col++) {
            $dark = $row >= 0 && $row < $count && $col >= 0 && $col < $count
                && $qr->isDark($row, $col);
            // PNG grayscale bit-depth 1: black = 0, white = 1.
            $bits .= str_repeat($dark ? '0' : '1', $scale);
        }

        // Pad the last byte with white pixels outside the picture width.
        $bits .= str_repeat('1', $pad);
        // PHP pack() has no binary-bit-string (B) format. Encode the
        // padded scanline in 8-bit chunks instead (MSB first).
        $packedRow = '';
        for ($offset = 0, $length = strlen($bits); $offset < $length; $offset += 8) {
            $packedRow .= chr(bindec(substr($bits, $offset, 8)));
        }
        $scanline = "\x00" . $packedRow; // PNG filter type 0 (None).
        $scanlines .= str_repeat($scanline, $scale);
    }

    $compressed = gzcompress($scanlines, 9);
    if ($compressed === false) return null;

    $ihdr = pack('NNCCCCC', $width, $width, 1, 0, 0, 0, 0);
    $png = "\x89PNG\r\n\x1a\n"
        . registration_qr_png_chunk('IHDR', $ihdr)
        . registration_qr_png_chunk('IDAT', $compressed)
        . registration_qr_png_chunk('IEND', '');

    return strlen($png) <= 524288 ? $png : null;
}

/** Construct a PNG chunk and big-endian CRC32 without requiring GD. */
function registration_qr_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data))
        . $type . $data
        . pack('N', crc32($type . $data));
}
