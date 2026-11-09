<?php

namespace Azera\Cli\Console;

use ReflectionClass;

/**
 * Help system: overview + per-task help, doc-comment parsing,
 * syntax-highlighted command/usage/options rendering, word wrap,
 * and tabular output.
 */
trait HelpRendering
{
    /** Built-in help task */
    public function helpOverview(): void
    {
        $this->writeln();
        $this->writeln("Usage: $this->scriptName <task:action> [args...]");
        $this->writeln();
        $this->writeln($this->style('Available tasks and actions:', 'bold', 'white'));
        $termWidth = $this->terminalWidth();
        foreach ($this->tasks as $name => $class) {
            $actionDescriptions = $this->extractActionDescriptions($class);
            if (empty($actionDescriptions)) {
                continue; // skip tasks with no public actions
            }
            $this->writeln();
            $desc = $this->extractShortDescription($class);

            // Task label column
            $labelWidth = 22;
            $leftPad    = 2; // leading spaces printed before label
            $avail      = max(10, $termWidth - $leftPad - $labelWidth - 1);
            $descLines  = $this->wrapText($desc, $avail);

            $labelStyled = $this->style(str_pad($name, $labelWidth), ...$this->taskStyles);
            if (count($descLines) > 0 && $descLines[0] !== '') {
                $this->writeln('  ' . $labelStyled . ' ' . $this->style($descLines[0], 'bold'));
            } else {
                $this->writeln('  ' . $labelStyled);
            }

            // remaining wrapped description lines (align under description column)
            for ($i = 1; $i < count($descLines); $i++) {
                $this->writeln('  ' . str_repeat(' ', $labelWidth) . ' ' . $this->style($descLines[$i], 'bold'));
            }

            // Actions: rendered as "task:action" with the colon prefix.
            // For single-action tasks, the sole action is shown only when its
            // name carries meaning beyond "do the task". By convention, an
            // action named "run" means "execute the task itself" — it never
            // adds information the task name doesn't already convey, so it is
            // suppressed. Any other verb (compile, ingest-postfix, diff, …)
            // was chosen to describe what the task does and is worth showing.
            if ($this->singleActionMethod($class) !== null) {
                $soleAction = array_key_first($actionDescriptions);
                if ($soleAction === 'run') {
                    continue;
                }
            }

            $actionLabelInner = 22;    // base str_pad width used for actions
            $actionLeft       = 2 + 2; // visual indent (leading spaces + inner padding)
            foreach ($actionDescriptions as $action => $actionDesc) {
                // Render as "task:action" (colon layout)
                $fullAction = $name . ':' . $action;
                // If an action name is longer than the base label width, account
                // for its actual length when calculating description wrap width
                $labelWidth = max($actionLabelInner, strlen($fullAction));
                // Reserve two spaces between action label and description
                $actionAvail = max(10, $termWidth - $actionLeft - $labelWidth - 2);

                // Colour the task and action parts separately (matching the
                // Usage/Examples highlight) so the task name stands out.
                $styledLabel = $this->style(str_pad($fullAction, $labelWidth), ...$this->actionStyles);
                if (str_contains($fullAction, ':')) {
                    [$taskPart, $actionPart] = explode(':', $fullAction, 2);
                    $padLen      = $labelWidth - strlen($fullAction);
                    $styledLabel = $this->style($taskPart, ...$this->taskStyles)
                        . $this->style(':', ...$this->muteStyles)
                        . $this->style($actionPart, ...$this->actionStyles)
                        . str_repeat(' ', max(0, $padLen));
                }

                if ($actionDesc === '') {
                    $this->writeln('    ' . '  ' . $styledLabel);
                    continue;
                }

                $actionLines = $this->wrapText($actionDesc, $actionAvail);
                $first       = array_shift($actionLines);
                $this->writeln(
                    '  '
                    . '  ' . $styledLabel
                    . '  ' . $this->style($first)
                );
                foreach ($actionLines as $ln) {
                    $this->writeln('  ' . str_repeat(' ', $labelWidth + 2) . '  ' . $this->style($ln));
                }
            }
        }
        $this->writeln();
        $this->writeln($this->style('Run "' . $this->scriptName . ' help <task>" for details.'));

        /*
        if ($this->globalHelp !== null) {
            $this->writeln();
            $this->renderGlobalHelp($this->terminalWidth());
        }
        */

        $this->writeln();
    }

    /**
     * Built-in help task for a specific task
     * @param string $task The name of the task to display help for
     */
    public function helpTask(string $task): void
    {
        $taskKey = strtolower($task);
        $class   = $this->tasks[$taskKey] ?? null;
        if (!$class) {
            $this->writeln("Task '{$task}' not found.");
            return;
        }

        $termWidth     = $this->terminalWidth();
        $sectionStyles = $this->sectionStyles;

        $ref  = new ReflectionClass($class);
        $doc  = $ref->getDocComment() ?: '';
        $info = static::parseDocComment($doc, $this->scriptName);

        $this->writeln();
        $this->writeln($this->style('Task: ', ...$sectionStyles) . $this->style($taskKey, ...$this->taskStyles));
        //$this->writeln('      ' . $this->style(str_repeat('─', strlen($taskKey)), 'cyan'));
        $this->writeln();
        // The description section collapses single newlines into spaces, so
        // any shrink sentinels (↰) would appear inline — strip them.
        $descText = str_replace(self::SHRINK_SENTINEL, ' ', $info['description']);
        $descText = preg_replace('/\s{2,}/', ' ', $descText) ?? $descText;
        $this->writeln($this->style(trim($descText), 'bold', 'white'));
        $this->writeln();

        // list available actions
        $actions        = $this->extractActionDescriptions($class);
        $isSingleAction = $this->singleActionMethod($class) !== null;
        // A single-action task whose only action is "run" hides the Actions
        // section entirely — "run" means "execute the task" and never adds
        // information the task name doesn't already convey.
        $hideSingleRun = $isSingleAction && array_key_first($actions) === 'run';

        if (!empty($actions) && !$hideSingleRun) {
            $this->writeln($this->style('Actions:', ...$sectionStyles));

            $actionLabelInner = 16;
            $leadingSpaces    = 2; // two leading spaces before task

            $descStartCol = $leadingSpaces + $actionLabelInner + 2;
            $actionAvail  = max(10, $termWidth - $descStartCol);

            foreach ($actions as $action => $actionDesc) {
                $fullAction = $taskKey . ':' . $action;
                $lines      = $this->wrapText($actionDesc, $actionAvail);
                $first      = array_shift($lines);

                // Colour the task and action parts separately (matching the
                // Usage/Examples highlight) so the task name stands out.
                $styledLabel = $this->style(str_pad($fullAction, $actionLabelInner), ...$this->actionStyles);
                if (str_contains($fullAction, ':')) {
                    [$taskPart, $actionPart] = explode(':', $fullAction, 2);
                    $padLen      = $actionLabelInner - strlen($fullAction);
                    $styledLabel = $this->style($taskPart, ...$this->taskStyles)
                        . $this->style(':', ...$this->muteStyles)
                        . $this->style($actionPart, ...$this->actionStyles)
                        . str_repeat(' ', max(0, $padLen));
                } else {
                    $styledLabel = $this->style($fullAction, ...$this->taskStyles);
                }

                $this->writeln(
                    str_repeat(' ', $leadingSpaces)
                    . $styledLabel
                    . ($first !== '' ? '  ' . $this->style($first) : '')
                );

                // continuation lines: indent to description column
                $continuationIndent = str_repeat(' ', $descStartCol);
                foreach ($lines as $ln) {
                    $this->writeln($continuationIndent . $this->style($ln));
                }

            }
            $this->writeln();
        }

        $this->writeln($this->style('Usage:', ...$sectionStyles));
        if ($isSingleAction && $hideSingleRun) {
            // Sole action is "run" — it adds no information, so the task
            // name alone is the usage.
            $this->writeln(
                '  ' . $this->style($this->scriptName, ...$this->muteStyles)
                . ' ' . $this->style($taskKey, ...$this->taskStyles)
                . ' [args...]'
            );
        } else {
            $actionsList = implode(
                '|',
                array_map(
                    fn($a) =>
                        $this->style($taskKey, ...$this->taskStyles) . ':' . $this->style($a, ...$this->actionStyles),
                    array_keys($actions)
                )
            )
                ?: '<action>';
            $this->writeln('  ' . $this->style($this->scriptName, ...$this->muteStyles) . ' ' . $actionsList . ' [args...]');
        }
        if ($info['usage']) {
            $this->renderUsageBlock($info['usage'], $taskKey, $termWidth);
        }
        $this->writeln();

        if ($info['options']) {
            $this->writeln($this->style('Options:', ...$sectionStyles));
            $this->renderOptionsBlock($info['options'], $termWidth);
            $this->writeln();
        }

        if ($info['examples']) {
            $this->writeln($this->style('Examples:', ...$sectionStyles));
            foreach (explode("\n", $info['examples']) as $l) {
                $this->writeln($this->highlightCommandLine($l, $taskKey));
            }
            $this->writeln();
        }

        // Global help: shown unless the task class opts out via $showGlobalHelp = false.
        if ($this->globalHelp !== null) {
            $showGlobal = true;
            if ($class && class_exists($class)) {
                $taskRef = new ReflectionClass($class);
                if ($taskRef->hasProperty('showGlobalHelp')) {
                    $prop = $taskRef->getProperty('showGlobalHelp');
                    // Read default value from class definition (not from an instance)
                    $showGlobal = (bool) ($prop->hasDefaultValue() ? $prop->getDefaultValue() : true);
                }
            }
            if ($showGlobal) {
                $this->renderGlobalHelp();
            }
        }
    }

    /**
     * Syntax-highlight a single command line in a Usage or Examples block.
     *
     * Token colouring rules:
     *   interpreter (php)   → dim
     *   script name         → dim
     *   task name           → bold green
     *   action name         → bold cyan
     *   <placeholder>       → bright yellow
     *   [--option]          → green brackets, highlighted inner token
     *   --flag / --key=val  → green (val placeholder stays bright yellow)
     *   # comment           → gray
     *   positional arg      → white
     *   continuation lines  → option/arg tokens, plus task:action if present
     */
    protected function highlightCommandLine(string $line, ?string $taskName = null): string
    {
        if (!$this->hasColors()) {
            return $line;
        }

        // Strip trailing CR/LF so Windows \r doesn't corrupt the last token
        $line = rtrim($line);

        // Preserve leading indentation
        $trimmed = ltrim($line);
        $indent  = substr($line, 0, strlen($line) - strlen($trimmed));

        if ($trimmed === '') {
            return $line;
        }

        // Split into (word, whitespace, word, whitespace …) keeping delimiters
        $parts = preg_split('/( +)/', $trimmed, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result    = $indent;
        $wordIndex = 0; // counts only non-whitespace tokens
        $inComment = false;

        foreach ($parts as $part) {
            // Whitespace between tokens – pass through unchanged
            if ($part !== '' && $part[0] === ' ') {
                $result .= $part;
                continue;
            }

            if ($inComment) {
                $result .= $this->style($part, ...$this->commentStyles);
                continue;
            }

            if ($part === '') {
                continue;
            }

            // Comment marker
            if ($part[0] === '#') {
                $inComment = true;
                $result .= $this->style($part, ...$this->commentStyles);
                $wordIndex++;
                continue;
            }

            // Continuation line: still colour task:action tokens if present
            $result .= str_contains($part, ':') && !str_starts_with($part, '--')
                ? $this->highlightTaskAction($part)
                : $this->highlightCliToken($part);

            $wordIndex++;
        }

        return $result;
    }

    /**
     * Colour a "task:action" token, splitting on the colon so the task
     * part gets taskStyles and the action part gets actionStyles.
     * If there's no colon, the whole token is styled as a task.
     */
    protected function highlightTaskAction(string $token): string
    {
        if (str_contains($token, ':')) {
            $task   = strtok($token, ':');
            $action = substr($token, strlen($task) + 1);
            return $this->style($this->scriptName, ...$this->muteStyles)
                . ' '
                . $this->style($task, ...$this->taskStyles)
                . $this->style(':', ...$this->muteStyles)
                . $this->style($action, ...$this->actionStyles);
        }
        return $this->style($this->scriptName, ...$this->muteStyles) . ' '
            . $this->style($token, ...$this->taskStyles);
    }

    /**
     * Colour a single CLI token: option, placeholder, or positional argument.
     */
    protected function highlightCliToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        // [--option], [--key=<val>], [<placeholder>] …
        if ($token[0] === '[' && str_ends_with($token, ']')) {
            $inner = substr($token, 1, -1);
            return $this->style('[', ...$this->braceStyles)
                . $this->highlightCliToken($inner)
                . $this->style(']', ...$this->braceStyles);
        }

        // <placeholder> or <a|b|c>
        if ($token[0] === '<' && str_ends_with($token, '>')) {
            return $this->style($token, ...$this->requiredArgStyles);
        }

        // --flag or --key=<val> or --key=literal
        if (str_starts_with($token, '--') || (strlen($token) === 2 && $token[0] === '-')) {
            if (str_contains($token, '=')) {
                [$flag, $val] = explode('=', $token, 2);
                $coloredVal = ($val !== '' && $val[0] === '<')
                    ? $this->style($val, ...$this->requiredArgStyles)
                    : $this->style($val, 'white');
                return $this->style($flag, ...$this->optionStyles) . $this->style('=', 'gray') . $coloredVal;
            }
            return $this->style($token, ...$this->optionStyles);
        }

        // short option -f
        if (strlen($token) >= 2 && $token[0] === '-') {
            return $this->style($token, ...$this->optionStyles);
        }

        // Plain positional argument (e.g. src/Models, User.php)
        return $this->style($token, 'white');
    }

    protected function methodToActionName(string $method): string
    {
        $base = preg_replace('/Action$/', '', $method);
        // convert camelCase to dashed
        $dashed = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $base));
        return $dashed;
    }

    protected function extractShortDescription(?string $class): string
    {
        if (!$class || !class_exists($class)) {
            return '';
        }
        $ref  = new ReflectionClass($class);
        $doc  = $ref->getDocComment() ?: '';
        $info = static::parseDocComment($doc, $this->scriptName);
        if (!$info['description']) {
            return '';
        }
        // The description collapses single newlines into spaces, so shrink
        // sentinels (↰) would appear inline — strip them before taking the
        // first line.
        $desc = str_replace(self::SHRINK_SENTINEL, ' ', $info['description']);
        return strtok($desc, "\n") ?: '';
    }

    /**
     * Returns an ordered map of action-name => one-line description for all
     * public *Action methods on the given class. Lines starting with @param
     * (and everything after) are stripped; only the opening prose is kept.
     *
     * @return array<string, string>
     */
    protected function extractActionDescriptions(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $ref     = new ReflectionClass($class);
        $actions = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $name = $m->getName();
            if (!str_ends_with($name, 'Action')) {
                continue;
            }
            $actionName = $this->methodToActionName($name);
            $doc        = $m->getDocComment() ?: '';
            $actions[$actionName] = $this->extractDocHeader($doc);
        }
        return $actions;
    }

    /**
     * Extract the opening prose from a doc comment, stopping at the first
     * @-tag line. All consecutive prose lines are joined into one
     * string so wrapped sentences come out complete.
     */
    protected function extractDocHeader(string $doc): string
    {
        if ($doc === '') {
            return '';
        }
        // Strip /** ... */ wrapper and leading " * ". Use [ \t] instead of
        // \s so blank comment lines keep their newline (see parseDocComment).
        $doc = trim(preg_replace('/^\/\*\*|\*\/$/', '', $doc));
        $doc = preg_replace('/^[ \t]*\*[ \t]?/m', '', $doc);

        $parts = [];
        foreach (explode("\n", $doc) as $line) {
            $line = rtrim($line, "\r");
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '@') {
                break; // stop at blank line or first @tag
            }
            $parts[] = $trim;
        }
        return implode(' ', $parts);
    }

    protected static function parseDocComment(string $doc, string $scriptName): array
    {
        $doc = trim(preg_replace('/^\/\*\*|\*\/$/', '', $doc));
        // Strip the leading " * " docblock marker, but use [ \t] instead of
        // \s so that blank comment lines (which contain only whitespace and
        // a newline) keep their newline instead of being collapsed away.
        $doc      = preg_replace('/^[ \t]*\*[ \t]?/m', '', $doc);
        $sections = [
            'description' => '',
            'usage'       => '',
            'options'     => '',
            'examples'    => '',
        ];
        $current = 'description';
        $doc     = str_replace("\r", '', $doc);
        foreach (explode("\n", $doc) as $line) {
            $trim = trim($line);
            // A line containing only the shrink marker (↰) requests a tight
            // line break. Emit the sentinel on its own line so renderers can
            // distinguish it from a real blank line (paragraph break) and
            // from a normal single newline (which collapses into the
            // previous line). The trailing \n puts the sentinel on its own
            // line when the section text is later split by \n.
            if ($trim === self::SHRINK_MARKER) {
                $sections[$current] .= self::SHRINK_SENTINEL . "\n";
                continue;
            }
            if ($trim === '') {
                $sections[$current] .= "\n";
                continue;
            }
            if (stripos($trim, 'Usage:') === 0) {
                $current = 'usage';
                continue;
            }
            if (stripos($trim, 'Options:') === 0) {
                $current = 'options';
                continue;
            }
            if (stripos($trim, 'Examples:') === 0) {
                $current = 'examples';
                continue;
            }
            if ($current === 'description') {
                $line .= " ";
            } else {
                $line .= "\n";
            }
            $sections[$current] .= $line;
        }
        foreach ($sections as $key => $s) {
            $sections[$key] = rtrim($s);
        }
        return $sections;
    }

    /**
     * Return detected terminal width (columns). Falls back to 80.
     */
    public function terminalWidth(): int
    {
        static $w = null;
        if ($w !== null) {
            return $w;
        }

        $default = 80;

        // 1) ENV
        $cols = getenv('COLUMNS');
        if ($cols !== false && (int) $cols > 0) {
            $w = (int) $cols;
            return $w;
        }

        // 2) tput (Unix)
        if (function_exists('exec')) {
            // only try tput when we are in a real terminal
            if (function_exists('posix_isatty') && @posix_isatty(STDOUT)) {
                $out = [];
                @exec('tput cols 2>/dev/null', $out);
                if (!empty($out) && is_array($out) && (int) $out[0] > 0) {
                    $w = (int) $out[0];
                    return $w;
                }
            }

            // 3) Windows: try to parse "mode CON" output for "Columns: N"
            if (stripos(PHP_OS, 'WIN') === 0) {
                $out = [];
                @exec('mode CON 2>&1', $out);
                if (!empty($out) && is_array($out)) {
                    $columnPos = 1; // usually the second number in the line "Columns: 120"
                    foreach ($out as $line) {
                        if (preg_match('/\b(\d{2,4})\b/', $line, $m)) {
                            if ($columnPos-- > 0) {
                                continue; // skip until we reach the column number
                            }
                            $w = (int) $m[1];
                            return $w;
                        }
                    }
                }
            }
        }

        $w = $default;
        return $w;
    }

    /**
     * Parse and render the global help text, which may contain multiple named sections.
     *
     * Section headers are lines matching "Word(s):" (e.g. "Options:", "Notes:", "Examples:").
     * Any text before the first header is rendered as plain prose.
     *
     * Section rendering by label (case-insensitive):
     *  - "Options"  → renderOptionsBlock()  – aligned flag + description columns, syntax-highlighted tokens
     *  - "Examples" → highlightCommandLine() – one highlighted command per line
     *  - "Usage"    → highlightCommandLine() – treated like examples (no task-name context)
     *  - Anything else (Notes, Warning, Info …) → word-wrapped plain text in muted color
     */
    protected function renderGlobalHelp(): void
    {
        if ($this->globalHelp === null) {
            return;
        }

        $termWidth = $this->terminalWidth();

        // ── Split into sections ──────────────────────────────────────────────
        // A header is a line whose trimmed form looks like "Word(s):" with nothing after the colon.
        $headerPattern = '/^([A-Za-z][A-Za-z\s]*):\s*$/';

        $sections = []; // [['label' => string|null, 'lines' => string[]]]
        $current  = ['label' => null, 'lines' => []];

        foreach (explode("\n", str_replace("\r", '', $this->globalHelp)) as $line) {
            if (preg_match($headerPattern, rtrim($line), $m)) {
                if ($current['label'] !== null || !empty($current['lines'])) {
                    $sections[] = $current;
                }
                $current = ['label' => trim($m[1]), 'lines' => []];
            } else {
                $current['lines'][] = $line;
            }
        }
        if ($current['label'] !== null || !empty($current['lines'])) {
            $sections[] = $current;
        }

        // ── Render each section ──────────────────────────────────────────────
        foreach ($sections as $section) {
            $label       = $section['label'];
            $body        = implode("\n", $section['lines']);
            $bodyTrimmed = trim($body);

            if ($label !== null) {
                $this->writeln($this->style($label . ':', ...$this->sectionStyles));
            }

            if ($bodyTrimmed === '') {
                if ($label !== null) {
                    $this->writeln();
                }
                continue;
            }

            $labelLower = strtolower($label ?? '');

            if ($labelLower === 'options' || str_ends_with($labelLower, ' options')) {
                // Options block: flag + description columns, syntax-highlighted
                $this->renderOptionsBlock($body, $termWidth);
                $this->writeln();
            } elseif ($labelLower === 'examples' || $labelLower === 'usage') {
                // Command lines with syntax highlighting
                foreach (explode("\n", $body) as $l) {
                    $this->writeln($this->highlightCommandLine($l));
                }
                $this->writeln();
            } else {
                // Prose / notes: word-wrap in muted color, 2-space indent.
                // A shrink sentinel (↰ in the source) is rendered as a tight
                // line break (no blank line); other lines are wrapped.
                foreach (explode("\n", $body) as $l) {
                    if (trim($l) === self::SHRINK_SENTINEL) {
                        $this->writeln();
                        continue;
                    }
                    $trimmed = trim($l);
                    if ($trimmed === '') {
                        $this->writeln();
                        continue;
                    }
                    foreach ($this->wrapText($trimmed, max(10, $termWidth - 2)) as $wrapped) {
                        $this->writeln('  ' . $this->style($wrapped, ...$this->commentStyles));
                    }
                }
                $this->writeln();
            }
        }
    }

    /**
     * Parse and render a Usage block:
     *  - Lines starting with 'php' or the task name open a new usage entry.
     *  - Lines starting with '[', '<', or '-' are continuations of the current entry.
     *  - All other non-blank lines are treated as prose and rendered inline.
     *  - Argument columns across all entries are aligned to the longest left side.
     */
    protected function renderUsageBlock(string $usageText, string $taskKey, int $termWidth): void
    {
        // Match the task name at the start of a line, optionally followed by
        // ":action", whitespace, or end-of-line. A bare task name (e.g. a
        // single "about" line) is a valid usage entry, so the trailing group
        // is optional — without it, bare-name lines fall through to prose and
        // are rendered unformatted.
        $taskPattern = '/^' . preg_quote($taskKey, '/') . '(?:[:\b]|\s|$)?/i';

        // ── Pass 1: split into 'entry' and 'prose' items ─────────────────────
        $items        = [];
        $currentEntry = null;
        $emptyLine    = false;
        $tightNext    = false; // a ↰ marker requested a tight break before the next prose line

        foreach (explode("\n", $usageText) as $ln) {
            $ln   = rtrim($ln);
            $trim = ltrim($ln);

            if ($trim === '') {
                if ($currentEntry !== null) {
                    $items[] = ['type' => 'entry', 'text' => $currentEntry];
                    $currentEntry = null;
                }
                $emptyLine = true;
                $tightNext = false; // a real blank line overrides a pending tight break
                continue;
            }

            // A shrink sentinel (↰ in the source) requests a tight line
            // break before the next prose line: no blank line, and the next
            // line must NOT be joined into the previous prose block.
            if ($trim === self::SHRINK_SENTINEL) {
                if ($currentEntry !== null) {
                    $items[] = ['type' => 'entry', 'text' => $currentEntry];
                    $currentEntry = null;
                }
                $tightNext = true;
                $emptyLine = false;
                continue;
            }

            $isEntryStart   = (bool) preg_match($taskPattern, $trim);
            $isContinuation = (bool) preg_match('/^[\[<\-]/', $trim);

            if ($isEntryStart) {
                if ($currentEntry !== null) {
                    $items[] = ['type' => 'entry', 'text' => $currentEntry];
                }
                $currentEntry = $trim;
                $tightNext    = false;
            } elseif ($isContinuation && $currentEntry !== null) {
                $currentEntry .= ' ' . $trim;
            } else {
                if ($currentEntry !== null) {
                    $items[] = ['type' => 'entry', 'text' => $currentEntry];
                    $currentEntry = null;
                }
                if ($tightNext) {
                    // A ↰ marker forced a tight break: start a new prose item
                    // with no blank line before it and no joining.
                    $items[] = ['type' => 'prose', 'text' => $trim, 'blankBefore' => false, 'tight' => true];
                    $tightNext = false;
                } elseif (!$emptyLine && !empty($items) && end($items)['type'] === 'prose') {
                    // append to previous prose block
                    $items[count($items) - 1]['text'] .= ' ' . $trim;
                } else {
                    // Record whether this prose item was preceded by a blank
                    // line so the renderer can reproduce the visual gap.
                    $items[] = ['type' => 'prose', 'text' => $trim, 'blankBefore' => $emptyLine];
                }
            }
            $emptyLine = false;
        }
        if ($currentEntry !== null) {
            $items[] = ['type' => 'entry', 'text' => $currentEntry];
        }

        // ── Pass 2: parse each entry, find max left-column width ─────────────
        $maxLeftLen = 0;
        foreach ($items as &$item) {
            if ($item['type'] !== 'entry') {
                continue;
            }
            $parts = preg_split('/\s+/', $item['text']);
            // model:sync-all  → left = [model:sync-all]
            $actionIdx = 0;
            $actionIdx = min($actionIdx, count($parts) - 1);
            $leftParts = array_slice($parts, 0, $actionIdx + 1);
            $restParts = array_slice($parts, $actionIdx + 1);
            $leftPlain = implode(' ', $leftParts);

            $item['leftParts'] = $leftParts;
            $item['leftPlain'] = $leftPlain;
            $item['rest']      = implode(' ', $restParts);

            $maxLeftLen = max($maxLeftLen, strlen($leftPlain));
        }
        unset($item);

        // ── Pass 3: render ───────────────────────────────────────────────────
        $leadingSpaces = 2;
        $descStartCol  = $leadingSpaces + $maxLeftLen + 1;
        $argAvail      = max(10, $termWidth - $descStartCol);
        $contIndent    = str_repeat(' ', $descStartCol);
        $addEmptyLine  = true;

        foreach ($items as $item) {

            if ($item['type'] === 'prose') {
                // A tight shrink marker with no text just forces a line break
                // without a blank line. Skip it entirely if it has no text.
                if (!empty($item['tight']) && $item['text'] === '') {
                    continue;
                }
                // Reproduce a blank line that appeared in the source before
                // this prose item, unless the renderer already emitted one
                // (e.g. after an entry) via $addEmptyLine, or this is a tight
                // break requested by a ↰ marker.
                if (!empty($item['blankBefore']) && !$addEmptyLine && empty($item['tight'])) {
                    $this->writeln();
                }
                if ($addEmptyLine) {
                    $this->writeln();
                    $addEmptyLine = false;
                }
                $this->writeln(str_repeat(' ', $leadingSpaces) . $this->style($item['text'], ...$this->commentStyles));
                continue;
            }

            $addEmptyLine = true;
            $this->writeln();

            // Style left tokens
            $leftStyled = [];
            foreach ($item['leftParts'] as $i => $tok) {
                $leftStyled[] = $i === 0
                    ? $this->highlightTaskAction($tok)  // task:action
                    : $this->style($tok, ...$this->actionStyles);
            }

            // Pad left side so all args start at the same column
            $padding  = str_repeat(' ', $maxLeftLen - strlen($item['leftPlain']));
            $argLines = $this->wrapText($item['rest'], $argAvail);
            $firstArg = array_shift($argLines);

            $this->writeln(
                str_repeat(' ', $leadingSpaces)
                . implode(' ', $leftStyled)
                . $padding
                . ($firstArg !== '' ? ' ' . $this->highlightCommandLine($firstArg) : '')
            );
            foreach ($argLines as $al) {
                $this->writeln($contIndent . $this->highlightCommandLine($al));
            }
        }
    }

    /**
     * Parse and render an Options block:
     *  - Lines starting with '-' open a new option entry (token + description split at 2+ spaces).
     *  - All other non-blank lines are treated as continuation text of the current option.
     *  - All option names are left-aligned to the longest token; descriptions are word-wrapped.
     *  - The option token is coloured via highlightCliToken(); descriptions are rendered in gray.
     */
    protected function renderOptionsBlock(string $optionsText, int $termWidth): void
    {
        // ── Pass 1: parse into token => full-description pairs ───────────────
        $options      = [];
        $currentToken = null;

        foreach (explode("\n", $optionsText) as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '-')) {
                // New option line – split at first run of 2+ spaces
                $parts        = preg_split('/\s{2,}/', $trim, 2);
                $currentToken = $parts[0];
                $options[$currentToken] = isset($parts[1]) ? trim($parts[1]) : '';
            } elseif ($currentToken !== null) {
                // Continuation line – join into description
                $options[$currentToken] .= ' ' . $trim;
            }
        }

        if (empty($options)) {
            return;
        }

        // ── Pass 2: find max token length for alignment ──────────────────────
        $maxTokenLen = 0;
        foreach (array_keys($options) as $token) {
            $maxTokenLen = max($maxTokenLen, strlen($token));
        }

        // ── Pass 3: render ───────────────────────────────────────────────────
        $leadingSpaces = 2;
        $gap           = 2;
        $descStartCol  = $leadingSpaces + $maxTokenLen + $gap;
        $descAvail     = max(10, $termWidth - $descStartCol);
        $contIndent    = str_repeat(' ', $descStartCol);

        foreach ($options as $token => $description) {
            $coloredToken = $this->highlightCliToken($token);
            $padding      = str_repeat(' ', $maxTokenLen - strlen($token) + $gap);

            if ($description === '') {
                $this->writeln(str_repeat(' ', $leadingSpaces) . $coloredToken);
                continue;
            }

            $lines = $this->wrapText($description, $descAvail);
            $first = array_shift($lines);
            $this->writeln(
                str_repeat(' ', $leadingSpaces)
                . $coloredToken
                . $padding
                . $this->style($first, 'white')
            );
            foreach ($lines as $ln) {
                $this->writeln($contIndent . $this->style($ln, 'white'));
            }
        }
    }

    /**
     * Word-wrap a text block into an array of lines for the given column width.
     * Lines are trimmed of trailing whitespace. Empty input returns an array with one empty string.
     * @param string $text The text to wrap.
     * @param int $width The maximum column width for wrapping.
     */
    public function wrapText(string $text, int $width): array
    {
        $width = max(10, (int) $width);
        if ($text === '') {
            return [''];
        }
        $wrapped = wordwrap($text, $width, "\n");
        $lines   = explode("\n", $wrapped);
        return array_map(fn($l) => rtrim($l), $lines);
    }
}