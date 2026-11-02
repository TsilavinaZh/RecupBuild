<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/**
 * Decodes a SAM 3.1 "one_bit" mask: base85 text around a range-coded bitmap, one context
 * per pixel, read from the twelve pixels already decoded around it.
 *
 * Ported from Meta's meta-sam-parser (_mask_codec.py). Copyright (c) Meta Platforms, Inc.
 * and affiliates. Distributed under the SAM License, see docs/licenses/SAM-LICENSE.txt.
 */
final class SamMask
{
    private const MASK_32 = 0xFFFFFFFF;
    private const TOP = 1 << 24;
    private const BOTTOM = 1 << 16;
    private const RADIX = 85;
    private const PREFIX_LENGTH = 5;
    private const INCREMENT = 14;
    private const COUNT_LIMIT = 4096;
    private const CONTEXTS = 1 << 12;
    private const PADDING = 2;

    /** Larger than any photo of the app: refuses a mask that would hold the CPU. */
    public const MAX_PIXELS = 4_000_000;

    /**
     * The mask as rows of pixels, row-major, one integer (0 or 1) per pixel.
     *
     * @return list<int>
     *
     * @throws \RuntimeException the payload is not a valid one_bit mask
     */
    public static function decode(string $payload, int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException('mask size is not acceptable');
        }
        if (!str_starts_with($payload, '!')) {
            throw new \RuntimeException('not a one_bit mask');
        }
        $input = self::unpack(substr($payload, 1));
        if (strlen($input) < 4) {
            throw new \RuntimeException('mask payload is truncated');
        }

        $position = 4;
        $inputLength = strlen($input);
        $code = unpack('N', $input)[1];
        $low = 0;
        $range = self::MASK_32;

        $counts = array_fill(0, self::CONTEXTS * 2, 1);
        $paddedWidth = $width + 2 * self::PADDING;
        $padded = array_fill(0, $paddedWidth * ($height + self::PADDING), 0);
        $output = array_fill(0, $width * $height, 0);

        for ($y = 0; $y < $height; ++$y) {
            $row = ($y + self::PADDING) * $paddedWidth + self::PADDING;
            for ($x = 0; $x < $width; ++$x) {
                $at = $row + $x;
                $prior = $at - $paddedWidth;
                $earlier = $prior - $paddedWidth;
                $context = $padded[$at - 1]
                    | ($padded[$at - 2] << 1)
                    | ($padded[$prior - 2] << 2)
                    | ($padded[$prior - 1] << 3)
                    | ($padded[$prior] << 4)
                    | ($padded[$prior + 1] << 5)
                    | ($padded[$prior + 2] << 6)
                    | ($padded[$earlier - 2] << 7)
                    | ($padded[$earlier - 1] << 8)
                    | ($padded[$earlier] << 9)
                    | ($padded[$earlier + 1] << 10)
                    | ($padded[$earlier + 2] << 11);
                $offset = $context << 1;
                $zero = $counts[$offset];
                $one = $counts[$offset + 1];
                $total = $zero + $one;

                $scaled = intdiv($range, $total);
                if ($scaled === 0) {
                    throw new \RuntimeException('mask has an invalid coding state');
                }
                $value = intdiv(($code - $low) & self::MASK_32, $scaled);
                $bit = min($value, $total - 1) < $zero ? 0 : 1;

                $low = ($low + $scaled * ($bit === 0 ? 0 : $zero)) & self::MASK_32;
                $range = $scaled * ($bit === 0 ? $zero : $one);
                while (true) {
                    if (($low ^ (($low + $range) & self::MASK_32)) >= self::TOP) {
                        if ($range >= self::BOTTOM) {
                            break;
                        }
                        $range = (-$low & self::MASK_32) & (self::BOTTOM - 1);
                    }
                    if ($position >= $inputLength) {
                        throw new \RuntimeException('mask ended before decoding completed');
                    }
                    $code = (($code << 8) | ord($input[$position++])) & self::MASK_32;
                    $low = ($low << 8) & self::MASK_32;
                    $range = ($range << 8) & self::MASK_32;
                }

                $padded[$at] = $bit;
                $output[$y * $width + $x] = $bit;
                $counts[$offset + $bit] += self::INCREMENT;
                if ($counts[$offset] + $counts[$offset + 1] >= self::COUNT_LIMIT) {
                    $counts[$offset] = ($counts[$offset] >> 1) ?: 1;
                    $counts[$offset + 1] = ($counts[$offset + 1] >> 1) ?: 1;
                }
            }
        }

        return $output;
    }

    /** The base85 text to bytes: a 5-digit length, then 5 digits for every 4 bytes. */
    private static function unpack(string $payload): string
    {
        $digits = self::digits();
        $size = strlen($payload);
        if ($size < self::PREFIX_LENGTH) {
            throw new \RuntimeException('mask has no length prefix');
        }

        $digit = static function (int $at) use ($payload, $digits): int {
            $value = $digits[$payload[$at]] ?? self::RADIX;
            if ($value >= self::RADIX) {
                throw new \RuntimeException('mask has an invalid character');
            }

            return $value;
        };

        $length = 0;
        for ($i = 0; $i < self::PREFIX_LENGTH; ++$i) {
            $length = $length * self::RADIX + $digit($i);
        }
        $remainder = $length % 4;
        $expected = self::PREFIX_LENGTH + intdiv($length, 4) * 5 + ($remainder === 0 ? 0 : $remainder + 1);
        if ($size !== $expected) {
            throw new \RuntimeException('mask length does not match its prefix');
        }

        $bytes = '';
        $source = self::PREFIX_LENGTH;
        for ($done = 0; $done < $length - $remainder; $done += 4) {
            $value = 0;
            for ($i = 0; $i < 5; ++$i) {
                $value = $value * self::RADIX + $digit($source + $i);
            }
            if ($value > self::MASK_32) {
                throw new \RuntimeException('mask has an out-of-range group');
            }
            $source += 5;
            $bytes .= pack('N', $value);
        }

        if ($remainder > 0) {
            $value = 0;
            for ($i = 0; $i < 5; ++$i) {
                $value = $value * self::RADIX + ($i < $remainder + 1 ? $digit($source + $i) : self::RADIX - 1);
            }
            if ($value > self::MASK_32) {
                throw new \RuntimeException('mask has an out-of-range tail');
            }
            $bytes .= substr(pack('N', $value), 0, $remainder);
        }

        return $bytes;
    }

    /**
     * The value of each base85 character: printable ASCII minus the protocol's delimiters.
     *
     * @return array<string, int>
     */
    private static function digits(): array
    {
        $digits = [];
        foreach (range(0x21, 0x7E) as $code) {
            $character = chr($code);
            if (!str_contains('"\\,;<>|', $character)) {
                $digits[$character] = count($digits);
            }
        }

        return $digits;
    }
}
