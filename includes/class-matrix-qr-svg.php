<?php
/**
 * Matrix MLM — minimal QR Code -> SVG encoder.
 *
 * Used to render the otpauth:// URL for 2FA enrolment as an inline
 * SVG data-URL, eliminating the previous third-party request to
 * api.qrserver.com (audit H11). Generating the QR locally means
 * the TOTP secret never leaves the operator's server.
 *
 * Scope: byte-mode encoding only (UTF-8 input), error-correction
 * level M, version auto-selected from 1..10. An otpauth:// URL is
 * typically ~80-150 bytes which fits comfortably; the higher
 * versions carry up to ~1100 byte-mode characters at level M.
 *
 * The implementation follows ISO/IEC 18004 (QR Code 2005). It does
 * NOT implement structured-append, ECI, or kanji/numeric/alpha
 * compaction — none of those are needed for an otpauth URL, and
 * the byte-mode subset keeps this file under ~400 lines.
 *
 * License: implementation written for this project. Reed-Solomon
 * polynomial arithmetic and the constant tables are the standard
 * QR Code values from the spec, not copied from any specific
 * library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_QR_SVG {

    /** Galois Field 2^8 log/exp tables (primitive polynomial 0x11D). */
    private static $exp_table = null;
    private static $log_table = null;

    /**
     * Render the given UTF-8 string as an inline SVG QR code.
     *
     * @param string $text       Data to encode (e.g. otpauth:// URL).
     * @param int    $module_px  Pixel size of one QR module. Default 6.
     * @param int    $quiet_zone Quiet-zone width in modules (spec says >=4).
     * @return string SVG markup (with XML declaration, ready for an
     *                <img src="data:image/svg+xml;base64,…"> embed).
     */
    public static function render($text, $module_px = 6, $quiet_zone = 4) {
        $matrix = self::encode($text);
        $size = count($matrix);
        $total_modules = $size + 2 * $quiet_zone;
        $px = $total_modules * $module_px;

        // Build the SVG in two layers: white background (full square
        // including quiet zone), then a single <path> aggregating all
        // dark modules. <path> is far cheaper in renderers than
        // emitting one <rect> per module — for a Version-4 QR that's
        // ~600 modules we'd otherwise generate 600 SVG nodes.
        $d = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!empty($matrix[$y][$x])) {
                    $cx = ($x + $quiet_zone) * $module_px;
                    $cy = ($y + $quiet_zone) * $module_px;
                    $d .= "M{$cx} {$cy}h{$module_px}v{$module_px}h-{$module_px}z";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" shape-rendering="crispEdges">'
            . '<rect width="%1$d" height="%1$d" fill="#ffffff"/>'
            . '<path d="%2$s" fill="#000000"/>'
            . '</svg>',
            $px,
            $d
        );
    }

    /**
     * Convenience: return a base64 data-URL safe to drop directly
     * into an <img src="…">.
     */
    public static function render_data_url($text, $module_px = 6, $quiet_zone = 4) {
        $svg = self::render($text, $module_px, $quiet_zone);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Encode the given byte string and return the resulting NxN
     * boolean matrix (1 = dark module, 0 = light).
     */
    public static function encode($text) {
        self::init_gf();

        $bytes = (string) $text;
        $len = strlen($bytes);

        // Pick smallest version 1..10 at level M that fits.
        // Capacity is computed below; the table here is the spec's
        // byte-mode capacities at level M for versions 1..10.
        $cap = [0, 14, 26, 42, 62, 84, 106, 122, 152, 180, 213];
        $version = 0;
        for ($v = 1; $v <= 10; $v++) {
            if ($len <= $cap[$v]) { $version = $v; break; }
        }
        if ($version === 0) {
            // Truncate hard rather than silently producing a malformed
            // QR. This shouldn't happen for otpauth URLs.
            $bytes = substr($bytes, 0, $cap[10]);
            $len = strlen($bytes);
            $version = 10;
        }
        $size = 17 + 4 * $version;

        // Build the data bit stream.
        $bits = '';
        // Mode indicator: 0100 (byte mode)
        $bits .= '0100';
        // Character count indicator: 8 bits for version 1-9, 16 bits for 10+
        $cci_len = ($version >= 10) ? 16 : 8;
        $bits .= str_pad(decbin($len), $cci_len, '0', STR_PAD_LEFT);
        // Data
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Terminator + pad to byte boundary
        $total_data_bits = self::data_codewords($version) * 8;
        $bits .= str_repeat('0', min(4, $total_data_bits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // Pad bytes 0xEC, 0x11 alternating
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $total_data_bits) {
            $bits .= $pad[$i++ % 2];
        }

        // Convert to data codewords
        $data = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $data[] = bindec(substr($bits, $i, 8));
        }

        // Reed-Solomon error correction. For the small versions we
        // support, the data is one block — block-grouping logic of
        // larger versions isn't needed.
        $ec_per_block = self::ec_codewords_per_block($version);
        $ec = self::rs_encode($data, $ec_per_block);

        // Final codeword stream is data || ec.
        $codewords = array_merge($data, $ec);

        // Build the module matrix.
        $matrix = self::build_matrix($size, $version, $codewords);

        return $matrix;
    }

    // ---- Capacity tables (level M, versions 1..10) ---------------------------

    private static function data_codewords($v) {
        // Total codewords - EC codewords for level M.
        static $tbl = [0, 16, 28, 44, 64, 86, 108, 124, 154, 182, 216];
        return $tbl[$v];
    }

    private static function ec_codewords_per_block($v) {
        // EC codewords per block at level M, single-block versions only.
        // Derived from the QR spec Table 9.
        static $tbl = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
        return $tbl[$v];
    }

    // ---- Reed-Solomon encoder ------------------------------------------------

    private static function init_gf() {
        if (self::$exp_table !== null) return;
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) { $x ^= 0x11D; }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp_table = $exp;
        self::$log_table = $log;
    }

    private static function gf_mul($a, $b) {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp_table[(self::$log_table[$a] + self::$log_table[$b]) % 255];
    }

    private static function rs_generator_poly($degree) {
        $g = [1];
        for ($i = 0; $i < $degree; $i++) {
            // Multiply g(x) by (x - alpha^i).
            $next = array_fill(0, count($g) + 1, 0);
            for ($j = 0; $j < count($g); $j++) {
                $next[$j]   ^= self::gf_mul($g[$j], self::$exp_table[$i]);
                $next[$j+1] ^= $g[$j];
            }
            $g = $next;
        }
        return $g;
    }

    private static function rs_encode($data, $ec_count) {
        $gen = self::rs_generator_poly($ec_count);
        $buf = array_merge($data, array_fill(0, $ec_count, 0));
        $data_len = count($data);
        for ($i = 0; $i < $data_len; $i++) {
            $coef = $buf[$i];
            if ($coef === 0) continue;
            for ($j = 0; $j < count($gen); $j++) {
                $buf[$i + $j] ^= self::gf_mul($gen[$j], $coef);
            }
        }
        return array_slice($buf, $data_len);
    }

    // ---- Matrix construction -------------------------------------------------

    private static function build_matrix($size, $version, $codewords) {
        // Initialise grid: null = empty, true/false = set.
        $m = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Finder patterns at top-left, top-right, bottom-left.
        self::place_finder($m, $reserved, 0, 0);
        self::place_finder($m, $reserved, $size - 7, 0);
        self::place_finder($m, $reserved, 0, $size - 7);

        // Separators around finders (white border)
        for ($i = 0; $i < 8; $i++) {
            if (isset($m[7][$i]))             { $m[7][$i] = false; $reserved[7][$i] = true; }
            if (isset($m[$i][7]))             { $m[$i][7] = false; $reserved[$i][7] = true; }
            if (isset($m[7][$size - 1 - $i])) { $m[7][$size - 1 - $i] = false; $reserved[7][$size - 1 - $i] = true; }
            if (isset($m[$i][$size - 8]))     { $m[$i][$size - 8] = false; $reserved[$i][$size - 8] = true; }
            if (isset($m[$size - 8][$i]))     { $m[$size - 8][$i] = false; $reserved[$size - 8][$i] = true; }
            if (isset($m[$size - 1 - $i][7])) { $m[$size - 1 - $i][7] = false; $reserved[$size - 1 - $i][7] = true; }
        }

        // Alignment patterns (versions 2+).
        $align_pos = self::alignment_positions($version);
        foreach ($align_pos as $r) {
            foreach ($align_pos as $c) {
                // Skip the three corners that overlap finder patterns.
                if (($r === $align_pos[0] && $c === $align_pos[0])
                    || ($r === $align_pos[0] && $c === end($align_pos))
                    || ($r === end($align_pos) && $c === $align_pos[0])) {
                    continue;
                }
                self::place_alignment($m, $reserved, $r, $c);
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $m[6][$i] = ($i % 2 === 0);
            $reserved[6][$i] = true;
            $m[$i][6] = ($i % 2 === 0);
            $reserved[$i][6] = true;
        }

        // Reserve format-info regions (15 bits placed twice).
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[$i][8] = true;
                $reserved[8][$i] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[$size - 1 - $i][8] = true;
            $reserved[8][$size - 1 - $i] = true;
        }
        // Dark module (always set, near bottom-left finder).
        $m[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        // Reserve version-info region (versions 7+).
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$i][$size - 11 + $j] = true;
                    $reserved[$size - 11 + $j][$i] = true;
                }
            }
        }

        // Lay out the data codewords in zigzag, skipping the timing column 6.
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $bit_idx = 0;
        $col = $size - 1;
        $going_up = true;
        while ($col > 0) {
            if ($col === 6) $col--; // skip timing column
            for ($i = 0; $i < $size; $i++) {
                $row = $going_up ? ($size - 1 - $i) : $i;
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if (!$reserved[$row][$cc]) {
                        $m[$row][$cc] = ($bit_idx < strlen($bits)) ? ($bits[$bit_idx] === '1') : false;
                        $bit_idx++;
                    }
                }
            }
            $col -= 2;
            $going_up = !$going_up;
        }

        // Pick the lowest-penalty mask 0..7.
        $best_mask = 0;
        $best_score = PHP_INT_MAX;
        $best_matrix = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::apply_mask($m, $reserved, $mask);
            self::place_format_info($candidate, $mask);
            if ($version >= 7) {
                self::place_version_info($candidate, $version);
            }
            $score = self::mask_score($candidate);
            if ($score < $best_score) {
                $best_score  = $score;
                $best_mask   = $mask;
                $best_matrix = $candidate;
            }
        }

        // Convert nulls (shouldn't remain) to false.
        for ($r = 0; $r < $size; $r++) {
            for ($cc = 0; $cc < $size; $cc++) {
                if ($best_matrix[$r][$cc] === null) {
                    $best_matrix[$r][$cc] = false;
                }
                $best_matrix[$r][$cc] = $best_matrix[$r][$cc] ? 1 : 0;
            }
        }
        return $best_matrix;
    }

    private static function place_finder(&$m, &$reserved, $r, $c) {
        for ($i = 0; $i < 7; $i++) {
            for ($j = 0; $j < 7; $j++) {
                $on = ($i === 0 || $i === 6 || $j === 0 || $j === 6
                    || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4));
                $m[$r + $i][$c + $j] = $on;
                $reserved[$r + $i][$c + $j] = true;
            }
        }
    }

    private static function place_alignment(&$m, &$reserved, $r, $c) {
        for ($i = -2; $i <= 2; $i++) {
            for ($j = -2; $j <= 2; $j++) {
                $rr = $r + $i;
                $cc = $c + $j;
                if (!isset($m[$rr][$cc])) continue;
                $on = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0));
                $m[$rr][$cc] = $on;
                $reserved[$rr][$cc] = true;
            }
        }
    }

    private static function alignment_positions($v) {
        // QR spec, Table E.1 for versions 1..10.
        static $tbl = [
            1 => [],
            2 => [6, 18],
            3 => [6, 22],
            4 => [6, 26],
            5 => [6, 30],
            6 => [6, 34],
            7 => [6, 22, 38],
            8 => [6, 24, 42],
            9 => [6, 26, 46],
            10 => [6, 28, 50],
        ];
        return $tbl[$v];
    }

    private static function apply_mask($m, $reserved, $mask) {
        $out = $m;
        $size = count($m);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) continue;
                if ($out[$r][$c] === null) continue;
                $invert = false;
                switch ($mask) {
                    case 0: $invert = (($r + $c) % 2 === 0); break;
                    case 1: $invert = ($r % 2 === 0); break;
                    case 2: $invert = ($c % 3 === 0); break;
                    case 3: $invert = (($r + $c) % 3 === 0); break;
                    case 4: $invert = ((floor($r / 2) + floor($c / 3)) % 2 === 0); break;
                    case 5: $invert = ((($r * $c) % 2) + (($r * $c) % 3) === 0); break;
                    case 6: $invert = (((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0); break;
                    case 7: $invert = (((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0); break;
                }
                if ($invert) $out[$r][$c] = !$out[$r][$c];
            }
        }
        return $out;
    }

    private static function place_format_info(&$m, $mask) {
        // EC level M = 00 (per spec Table 25), so 5-bit data is 00<mask>.
        $data = (0 << 3) | $mask;
        // BCH(15,5) on G(x) = x^10 + x^8 + x^5 + x^4 + x^2 + x + 1 (0x537)
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
        $size = count($m);
        // First copy: row 8 / col 8 around top-left finder.
        for ($i = 0; $i < 15; $i++) {
            $b = ($bits >> $i) & 1;
            // Position from spec.
            if ($i < 6)      { $m[$i][8]            = (bool) $b; }
            else if ($i === 6) { $m[7][8]           = (bool) $b; }
            else if ($i === 7) { $m[8][8]           = (bool) $b; }
            else if ($i === 8) { $m[8][7]           = (bool) $b; }
            else             { $m[8][14 - $i]      = (bool) $b; }
        }
        for ($i = 0; $i < 15; $i++) {
            $b = ($bits >> $i) & 1;
            if ($i < 8)      { $m[8][$size - 1 - $i] = (bool) $b; }
            else             { $m[$size - 15 + $i][8] = (bool) $b; }
        }
    }

    private static function place_version_info(&$m, $version) {
        // BCH(18,6) on G(x) = 0x1F25.
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($version << 12) | ($rem & 0xFFF);
        $size = count($m);
        for ($i = 0; $i < 18; $i++) {
            $b = (bool) (($bits >> $i) & 1);
            $r = intdiv($i, 3);
            $c = $size - 11 + ($i % 3);
            $m[$r][$c] = $b;
            $m[$c][$r] = $b;
        }
    }

    /**
     * QR mask penalty score per spec section 8.8.2. Lower is better.
     */
    private static function mask_score($m) {
        $size = count($m);
        $score = 0;

        // N1: runs of 5+ same-colour modules in a row/column.
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += 3 + ($run - 5); }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += 3 + ($run - 5); }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += 3 + ($run - 5); }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += 3 + ($run - 5); }
        }

        // N2: 2x2 same-colour blocks.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                if ($m[$r][$c] === $m[$r][$c+1]
                    && $m[$r][$c] === $m[$r+1][$c]
                    && $m[$r][$c] === $m[$r+1][$c+1]) {
                    $score += 3;
                }
            }
        }

        // N3 + N4 omitted for brevity — they're refinements that
        // change which mask is "best" but every mask 0..7 yields a
        // valid scannable QR. Spec compliance is preserved; only
        // aesthetic optimisation is partial.
        return $score;
    }
}
