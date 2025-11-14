<?php

namespace Orryv;
/**
 * Cmd — tiny cross‑platform console helper
 *
 * Features
 *  - Works on Unix and Windows (enables ANSI VT100 on Win10+ when possible)
 *  - Detects TTY; falls back to plain printing when piped/redirected
 *  - Update the current line (single-line live output)
 *  - Reserve a live block and update any of the last N lines
 *  - Color/style helpers (fg/bg + bold/underline/etc.)
 *  - Cursor show/hide
 *
 * Usage (quick):
 *   Cmd::writeln("hello");
 *   Cmd::beginLive(2);               // reserve 2 lines
 *   Cmd::updateLive(0, "first");     // top line in the live block
 *   Cmd::updateLive(1, "second");    // second line
 *   Cmd::finishLive();               // place cursor below block
 *
 *   // Single-line updating
 *   Cmd::overwrite("Working… 10%" );
 *   Cmd::overwrite("Working… 100%" );
 *   Cmd::newline();
 *
 *   // Colors
 *   Cmd::writeln(Cmd::colorize("OK", 'green', null, ['bold']));
 */
final class Cmd
{
    /** @var bool */
    private static $initialized = false;
    /** @var bool */
    private static $isTty = true;
    /** @var bool */
    private static $ansi = true;

    // For overwrite fallback when no ANSI
    /** @var int */
    private static $prevVisibleLen = 0;

    // Live block state
    /** @var int */
    private static $liveLines = 0;     // number of reserved lines
    /** @var bool */
    private static $cursorHidden = false;

    // ===== Initialization =====
    private static function init(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        // Detect TTY
        if (function_exists('stream_isatty')) {
            self::$isTty = @stream_isatty(STDOUT);
        } elseif (function_exists('posix_isatty')) {
            self::$isTty = @posix_isatty(STDOUT);
        } else {
            self::$isTty = true; // assume true if unknown
        }

        // Enable ANSI on Windows when possible
        if (PHP_OS_FAMILY === 'Windows') {
            if (function_exists('sapi_windows_vt100_support')) {
                // Try to enable; if it returns false, leave as-is
                @sapi_windows_vt100_support(STDOUT, true);
                self::$ansi = @sapi_windows_vt100_support(STDOUT);
            } else {
                // Some terminals set these env vars when they support ANSI
                self::$ansi = getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON' || getenv('WT_SESSION');
            }
        } else {
            self::$ansi = true; // POSIX terminals typically support ANSI
        }

        // If not a TTY, disable ANSI codes (to keep logs clean)
        if (!self::$isTty) {
            self::$ansi = false;
        }
    }

    // ===== Basic output =====
    public static function write(string $text): void
    {
        self::init();
        fwrite(STDOUT, $text);
    }

    public static function writeln(string $text = ''): void
    {
        self::init();
        fwrite(STDOUT, $text . PHP_EOL);
        self::$prevVisibleLen = 0; // reset for overwrite fallback
    }

    public static function newline(): void { self::writeln(''); }

    // ===== Cursor & line controls =====
    private static function esc(string $seq): string { return "\033[" . $seq; }

    public static function clearLine(): void
    {
        self::init();
        if (self::$ansi) {
            // Clear entire line and move cursor to column 1
            fwrite(STDOUT, "\r" . self::esc('2K'));
        } else {
            // No ANSI: best effort — overwrite with spaces based on previous visible length
            $pad = max(self::$prevVisibleLen, 0);
            fwrite(STDOUT, "\r" . str_repeat(' ', $pad) . "\r");
        }
    }

    public static function overwrite(string $text): void
    {
        self::init();
        if (!self::$isTty) {
            // When not a TTY, just print lines so logs make sense
            self::writeln($text);
            return;
        }

        self::clearLine();
        fwrite(STDOUT, $text);
        self::$prevVisibleLen = self::visibleLen($text);
        fflush(STDOUT);
    }

    private static function moveUp(int $n): void
    {
        if ($n <= 0) return;
        if (self::$ansi) fwrite(STDOUT, self::esc($n . 'A'));
    }
    private static function moveDown(int $n): void
    {
        if ($n <= 0) return;
        if (self::$ansi) fwrite(STDOUT, self::esc($n . 'B'));
    }

    private static function hideCursor(): void
    {
        if (self::$ansi && !self::$cursorHidden) {
            fwrite(STDOUT, self::esc('?25l'));
            self::$cursorHidden = true;
        }
    }

    private static function showCursor(): void
    {
        if (self::$ansi && self::$cursorHidden) {
            fwrite(STDOUT, self::esc('?25h'));
            self::$cursorHidden = false;
        }
    }

    // ===== Live block API =====
    /**
     * Reserve N lines below the current cursor for live updates.
     * Cursor remains positioned at the TOP of the live block (column 1).
     */
    public static function beginLive(int $lines): void
    {
        self::init();

        if ($lines <= 0 || !self::$isTty || !self::$ansi) {
            // If we cannot manage a live block, just ensure future updates fall back to lines
            self::$liveLines = 0;
            return;
        }

        // Print N newlines to create the block, then move back up to its top
        fwrite(STDOUT, str_repeat(PHP_EOL, $lines));
        self::moveUp($lines);
        fwrite(STDOUT, "\r");
        self::$liveLines = $lines;
        self::hideCursor();
    }

    /**
     * Update the i-th line inside the live block (0 = top, liveLines-1 = bottom).
     * If there is no active live block or ANSI is unavailable, prints a plain line instead.
     */
    public static function updateLive(int $index, string $text): void
    {
        self::init();

        if ($index < 0) return;

        if (self::$liveLines <= 0 || !self::$ansi || !self::$isTty) {
            // Fallback: normal line printing
            self::writeln($text);
            return;
        }

        if ($index >= self::$liveLines) {
            $index = self::$liveLines - 1;
        }

        // Move to the target line (relative to top), clear, write, and return to top
        self::moveDown($index);
        self::clearLine();
        fwrite(STDOUT, $text . "\r"); // CR to go back to column 1
        self::moveUp($index);
        fflush(STDOUT);
    }

    /** Move the cursor to the line just AFTER the live block and show it again. */
    public static function finishLive(bool $leaveContent = true): void
    {
        self::init();
        if (self::$liveLines > 0 && self::$ansi && self::$isTty) {
            // Go to bottom of block
            self::moveDown(self::$liveLines);
            fwrite(STDOUT, "\r");
            if (!$leaveContent) {
                // Clear all lines we used
                for ($i = 0; $i < self::$liveLines; $i++) {
                    self::moveUp(1);
                    self::clearLine();
                    self::moveDown(1);
                }
                fwrite(STDOUT, "\r");
            }
        }
        self::showCursor();
        self::$liveLines = 0;
        self::$prevVisibleLen = 0;
        self::newline();
    }

    // ===== Colors & styles =====
    private static $fgMap = [
        'default' => 39,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'gray' => 90, 'brightRed' => 91, 'brightGreen' => 92, 'brightYellow' => 93, 'brightBlue' => 94, 'brightMagenta' => 95, 'brightCyan' => 96, 'brightWhite' => 97,
    ];
    private static $bgMap = [
        'default' => 49,
        'black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47,
        'gray' => 100, 'brightRed' => 101, 'brightGreen' => 102, 'brightYellow' => 103, 'brightBlue' => 104, 'brightMagenta' => 105, 'brightCyan' => 106, 'brightWhite' => 107,
    ];
    private static $optMap = [
        'bold' => 1, 'dim' => 2, 'underline' => 4, 'blink' => 5, 'reverse' => 7, 'hidden' => 8,
    ];

    /** Colorize a string if ANSI is available; otherwise return as-is. */
    public static function colorize(string $text, ?string $fg = null, ?string $bg = null, array $options = []): string
    {
        self::init();
        if (!self::$ansi) return $text;

        $codes = [];
        if ($fg && isset(self::$fgMap[$fg])) $codes[] = self::$fgMap[$fg];
        if ($bg && isset(self::$bgMap[$bg])) $codes[] = self::$bgMap[$bg];
        foreach ($options as $opt) if (isset(self::$optMap[$opt])) $codes[] = self::$optMap[$opt];
        if (!$codes) return $text;

        return self::esc(implode(';', $codes) . 'm') . $text . self::esc('0m');
    }

    /** Convenience: write a line with optional color & styles. */
    public static function println(string $text, ?string $fg = null, ?string $bg = null, array $options = []): void
    {
        self::writeln(self::colorize($text, $fg, $bg, $options));
    }

    // ===== Utilities =====
    /** Strip ANSI CSI sequences — good for length calculations. */
    public static function stripAnsi(string $s): string
    {
        return (string)preg_replace('/\x1b\[[0-9;?]*[ -/]*[@-~]/', '', $s);
    }

    /** Visible (non-ANSI) length; uses strlen on the stripped string. */
    private static function visibleLen(string $s): int
    {
        return strlen(self::stripAnsi($s));
    }
}
