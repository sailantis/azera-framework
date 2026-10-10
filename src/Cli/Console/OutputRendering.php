<?php

namespace Azera\Cli\Console;

/**
 * ANSI color/styling and convenience output methods.
 */
trait OutputRendering
{
    protected const ANSI = [
        'reset' => "\033[0m",
        // basic styles
        'bold'    => "\033[1m",
        'dim'     => "\033[2m",
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'white'   => "\033[37m",
        // Bright variants (prefix with 'b')
        'gray'     => "\033[90m",
        'bred'     => "\033[91m",
        'bgreen'   => "\033[92m",
        'byellow'  => "\033[93m",
        'bblue'    => "\033[94m",
        'bmagenta' => "\033[95m",
        'bcyan'    => "\033[96m",
        // Background colors (prefix with 'bg-')
        'bg-black'   => "\033[40m",
        'bg-red'     => "\033[41m",
        'bg-green'   => "\033[42m",
        'bg-yellow'  => "\033[43m",
        'bg-blue'    => "\033[44m",
        'bg-magenta' => "\033[45m",
        'bg-cyan'    => "\033[46m",
        'bg-white'   => "\033[47m",
    ];

    public const STYLE_ERROR = ['bg-red', 'white', 'bold'];
    public const STYLE_WARN = ['byellow'];
    public const STYLE_INFO = ['bcyan'];
    public const STYLE_SUCCESS = ['bgreen'];
    public const STYLE_MUTED = ['gray'];

    protected bool $useColors = false;
    protected bool $hasTruecolor = false;

    /**
     * Detect terminal color/truecolor support and initialise the trait's
     * properties. Call once from the host class constructor.
     */
    protected function initColors(): void
    {
        $this->useColors    = $this->detectColorSupport();
        $this->hasTruecolor = $this->useColors && $this->detectTruecolorSupport();
    }

    // -------------------------------------------------------------------------
    //  Capability detection
    // -------------------------------------------------------------------------

    protected function detectColorSupport(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return function_exists('sapi_windows_vt100_support')
                && @sapi_windows_vt100_support(STDOUT);
        }
        return function_exists('stream_isatty') && stream_isatty(STDOUT);
    }

    /**
     * Check whether the terminal advertises 24-bit truecolor support.
     *
     * Terminals that don't support truecolor may misinterpret the
     * \033[38;2;R;G;Bm (foreground) escape sequence and render it as a
     * background fill instead. This is commonly seen on XShell over SSH
     * when the terminal type is set to a non-truecolor mode.
     */
    protected function detectTruecolorSupport(): bool
    {
        // ---------------------------------------------------------------------
        // 1) Windows: VT100 = Truecolor (Windows Terminal, ConPTY, PowerShell 7)
        // ---------------------------------------------------------------------
        if (PHP_OS_FAMILY === 'Windows') {
            // sapi_windows_vt100_support() is the most reliable indicator
            if (
                function_exists('sapi_windows_vt100_support') &&
                    @sapi_windows_vt100_support(STDOUT)
            ) {
                return true;
            }

            // Git Bash / MSYS2 / MinTTY -> Truecolor
            $termProgram = getenv('TERM_PROGRAM') ?: '';
            if (str_contains($termProgram, 'mintty')) {
                return true;
            }

            // VS Code Terminal -> Truecolor
            if ($termProgram === 'vscode') {
                return true;
            }

            // Windows Terminal does not set TERM reliably, but Truecolor is always available
            if (getenv('WT_SESSION')) {
                return true;
            }

            // Classic console (conhost.exe) -> unreliable
            // -> no Truecolor
            return false;
        }

        // ---------------------------------------------------------------------
        // 2) Unix: COLORTERM is the strongest indicator
        // ---------------------------------------------------------------------
        $colorterm = strtolower(getenv('COLORTERM') ?: '');
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return true;
        }

        // ---------------------------------------------------------------------
        // 3) TERM-Hints (xterm-256color, screen-256color, tmux-256color)
        // ---------------------------------------------------------------------
        $term = strtolower(getenv('TERM') ?: '');
        if (
            str_contains($term, 'truecolor') ||
                str_contains($term, '24bit') ||
                str_contains($term, '256color')
        ) {
            return true;
        }

        // ---------------------------------------------------------------------
        // 4) SSH-Clients: PuTTY, XShell, MobaXterm, iTerm2, etc.
        // ---------------------------------------------------------------------

        // iTerm2 → Truecolor
        if (getenv('TERM_PROGRAM') === 'iTerm.app') {
            return true;
        }

        // MobaXterm → Truecolor
        if (getenv('MobaXterm')) {
            return true;
        }

        // PuTTY -> no Truecolor
        if (getenv('PUTTY')) {
            return false;
        }

        // XShell -> Truecolor only if TERM is set correctly
        if (getenv('XDG_SESSION_TYPE') === 'x11' && str_contains($term, '256color')) {
            return true;
        }

        // ---------------------------------------------------------------------
        // 5) WSL: Truecolor always available
        // ---------------------------------------------------------------------
        if (getenv('WSL_DISTRO_NAME')) {
            return true;
        }

        // ---------------------------------------------------------------------
        // 6) Fallback: If we have a TTY but no info -> assume 256color
        // ---------------------------------------------------------------------
        if (function_exists('stream_isatty') && stream_isatty(STDOUT)) {
            return true; // 256color or better
        }

        // ---------------------------------------------------------------------
        // 7) No TTY -> no Truecolor
        // ---------------------------------------------------------------------
        return false;
    }

    // -------------------------------------------------------------------------
    //  Public configuration
    // -------------------------------------------------------------------------

    /** Enable or disable ANSI color output explicitly. */
    public function enableColors(bool $colors): void
    {
        $this->useColors = $colors;
    }

    /** Check whether ANSI color output is enabled. */
    public function hasColors(): bool
    {
        return $this->useColors;
    }

    // -------------------------------------------------------------------------
    //  Low-level ANSI helpers
    // -------------------------------------------------------------------------

    /**
     * Generate an ANSI escape code for a custom RGB color.
     *
     * @param string|int $r Either a hex color code (e.g. "#ff0000" or "bg:#00ff00" or "bg #00ff00") or the red component (0-255).
     * @param int|null $g The green component (0-255), required if $r is not a hex code.
     * @param int|null $b The blue component (0-255), required if $r is not a hex code.
     * @param bool $background Whether this color is for background (true) or foreground (false).
     * @return string The ANSI escape code for the specified color, or an empty string if colors are disabled or input is invalid.
     */
    public function color(string|int $r, ?int $g = null, ?int $b = null, bool $background = false): string
    {
        if (!$this->useColors) {
            return '';
        }

        $code = $background ? 48 : 38;

        // Hex-Mode?
        if ($g === null && $b === null) {
            $hex = (string) $r;

            if (str_starts_with($hex, 'bg')) {
                // Set Background explicitly
                $code = 48;
                $hex  = ltrim(substr($hex, 2), ' :;-');
            } elseif (str_starts_with($hex, 'fg')) {
                // Set Foreground explicitly
                $code = 38;
                $hex  = ltrim(substr($hex, 2), ' :;-');
            } elseif (str_starts_with($hex, "\033")) {
                // Already an ANSI code
                return $hex;
            }

            // Remove '#' character
            $hex = ltrim($hex, '#');

            // Short form #abc → #aabbcc
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            if (strlen($hex) !== 6) {
                return '';
            }

            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        return "\033[{$code};2;{$r};{$g};{$b}m";
    }

    /**
     * Apply one or more named ANSI styles or a custom color to a string.
     * Style names: bold, dim, red, green, yellow, blue, magenta, cyan, white, gray, bred, bgreen, byellow, bcyan, bg-red, bg-green, bg-yellow, bg-blue, bg-magenta, bg-cyan, bg-white
     * Custom colors can be specified via hex code (e.g. "#ff0000" or "bg:#00ff00" or "bg #00ff00").
     *
     * When color support is disabled, the text is returned unchanged.
     */
    public function style(string $text, string ...$styles): string
    {
        if (!$this->useColors || empty($styles)) {
            return $text;
        }
        $open = '';
        foreach ($styles as $s) {
            // Use named ANSI codes directly; only use color() for hex colors,
            // and skip hex colors entirely when truecolor is not supported
            // to avoid background-fill misrendering on limited terminals.
            if (isset(self::ANSI[$s])) {
                $open .= self::ANSI[$s];
            } elseif ($this->hasTruecolor) {
                $open .= $this->color($s);
            }
        }
        return $open . $text . self::ANSI['reset'];
    }

    // -------------------------------------------------------------------------
    //  Raw output primitives
    // -------------------------------------------------------------------------

    /** Write text to stdout. */
    public function write(string $text = ''): void
    {
        echo $text;
    }

    /** Write a line to stdout (newline appended). */
    public function writeln(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    /** Write text to stderr. */
    public function stderr(string $text): void
    {
        fwrite(STDERR, $text);
    }

    /** Write a line to stderr (newline appended). */
    public function stderrln(string $text): void
    {
        fwrite(STDERR, $text . PHP_EOL);
    }

    /** Write text to stdout. */
    public function stdout(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    /** Write a line to stdout (newline appended). */
    public function stdoutln(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    // -------------------------------------------------------------------------
    //  Styled convenience helpers
    // -------------------------------------------------------------------------

    /** Plain informational line. */
    public function line(string $text): void
    {
        $this->writeln($text);
    }

    /**
     * Write an informational message (cyan). Newline is appended automatically.
     */
    public function info(string $text): void
    {
        $this->writeln($this->style($text, ...static::STYLE_INFO));
    }

    /**
     * Write a success message (green). Newline is appended automatically.
     */
    public function success(string $text): void
    {
        $this->writeln($this->style($text, ...static::STYLE_SUCCESS));
    }

    /**
     * Write a warning message (yellow). Newline is appended automatically.
     */
    public function warn(string $text): void
    {
        $this->writeln($this->style($text, ...static::STYLE_WARN));
    }

    /**
     * Write an error message (white on red) to STDERR. Newline is appended automatically.
     */
    public function error(string $text): void
    {
        $this->stderrln($this->style($text, ...static::STYLE_ERROR));
    }

    /**
     * Write a muted / dimmed message. Newline is appended automatically.
     */
    public function muted(string $text): void
    {
        $this->writeln($this->style($text, ...static::STYLE_MUTED));
    }

    /**
     * Print a simple table with unicode-aware column width calculation.
     * @param array $headers The table headers as a numeric array.
     * @param array $rows The table rows as numeric arrays.
     */
    public function printTable(array $headers, array $rows): void
    {
        $cols   = count($headers);
        $widths = array_fill(0, $cols, 0);

        $visibleLen = static function (string $s): int {
            return mb_strwidth(preg_replace('/\e\[[0-9;]*m/', '', $s) ?? '');
        };

        for ($i = 0; $i < $cols; $i++) {
            $widths[$i] = $visibleLen($headers[$i]);
        }
        foreach ($rows as $row) {
            for ($i = 0; $i < $cols; $i++) {
                $len = $visibleLen($row[$i] ?? '');
                if ($len > $widths[$i]) {
                    $widths[$i] = $len;
                }
            }
        }

        $pad = static function (string $s, int $w) use ($visibleLen): string {
            $p = $w - $visibleLen($s);
            return $p > 0 ? $s . str_repeat(' ', $p) : $s;
        };

        $printRow = function (array $cells) use ($cols, $pad, $widths): void {
            $line = '';
            for ($i = 0; $i < $cols; $i++) {
                $line .= '  ' . $pad($cells[$i] ?? '', $widths[$i]);
            }
            $this->line(rtrim($line));
        };

        $printRow($headers);
        $this->line('  ' . str_repeat('-', array_sum($widths) + 2 * $cols));
        foreach ($rows as $row) {
            $printRow($row);
        }
    }
}