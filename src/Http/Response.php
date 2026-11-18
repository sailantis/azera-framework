<?php
namespace Azera\Http;

/**
 * Represents an HTTP response.
 *
 * Build a response by chaining setters and finish by calling {@see send()},
 * or use one of the static factory methods ({@see json()}, {@see html()},
 * {@see redirect()}, etc.) for common cases.
 */
class Response
{
    /**
     * Create a new Response.
     *
     * @param int $status HTTP status code.
     * @param array<string, string|list<string>> $headers Header values by field
     *        name. A string is one value; a list is a repeated field such as
     *        several `Set-Cookie` lines.
     * @param string $body Response body.
     */
    public function __construct(
        protected int $status = 200,
        array $headers = [],
        protected string $body = ''
    ) {
        // An integer key is a complete line rather than a name, so the older
        // `['Content-Type: application/json']` form keeps working. Turning them
        // into name => value pairs first keeps the loop below to one shape.
        if (isset($headers[0])) {
            foreach ($headers as $line) {
                [$name, $value] = self::parseHeaderLine((string) $line);
                $lower = strtolower($name);
                $key   = $this->headerIndex[$lower] ?? null;
                if ($key === null) {
                    $this->headerIndex[$lower] = $name;
                    $this->headers[$name]      = [$value];
                } else {
                    $this->headers[$key][] = $value;
                }
            }
        } else {
            foreach ($headers as $name => $values) {
                $lower = strtolower($name);
                $this->headerIndex[$lower] = $name;
                $this->headers[$name]      = (array) $values;
            }
        }

        if (self::$strict) {
            self::validateHeaderMap($this->headers);
        }
    }

    /**
     * Header values by field name, e.g. `'Content-Type' => ['application/json']`.
     *
     * Every name maps to a list even when the field was set once: a repeated
     * field (two `Set-Cookie` lines) is the case that must not be lost, and a
     * uniform shape keeps readers from branching on the value type.
     *
     * @var array<string, list<string>>
     */
    protected array $headers = [];

    /**
     * Lowercase field name to the spelling stored in {@see $headers}.
     *
     * HTTP field names are case-insensitive, so `X-Trace` and `x-trace` are one
     * field. Keeping the first spelling preserves what the application wrote
     * while still matching any casing on lookup.
     *
     * @var array<string, string>
     */
    protected array $headerIndex = [];

    /**
     * Field names already accepted by {@see validateName()}.
     *
     * The token check is a pure function of the name and the same names recur on
     * every request, so the result is cached. Values are never cached: they carry
     * the request's data.
     *
     * @var array<string, true>
     */
    protected static array $validNames = [];

    /**
     * Whether header names and values are checked as they are set.
     *
     * OFF BY DEFAULT, and the default is the point. This is a development
     * assertion about the APPLICATION's code, not a security control: PHP's
     * `header()` already refuses a value containing CR or LF, and the PSR-7
     * adapters (RoadRunner, Volucer) reject one at construction. What is left
     * for this check to catch is a malformed header written by the application
     * itself — `'Location: ' . $url` where `$url` came from a request, a field
     * name with a space in it — and the value of catching that early is a
     * message naming the field instead of a silently dropped header.
     *
     * Because that is a debugging aid rather than a guarantee, it is paid for
     * only where it is wanted. When off, the cost is one static read per header;
     * when on, 6 headers cost ~2.5 us per request (~0.4% of a PHP-FPM request,
     * ~1.3% of a resident-worker one).
     *
     * PROCESS-WIDE, so set it once at boot from the application's own debug
     * switch — never per request. A resident worker keeps this value for every
     * request it serves after the one that changed it. No env lookup happens
     * here: reading the environment inside a header setter would add a global
     * read to the hot path and make the behaviour SAPI-dependent and hard to
     * test. The entry script knows its own debug flag and states it once.
     */
    protected static bool $strict = false;

    /**
     * Enable or disable header validation, or read the current setting.
     *
     * Enable it in development so a malformed header fails loudly and names the
     * field; leave it off elsewhere. See {@see self::$strict} for why the
     * default is off and what the check does and does not protect.
     *
     * @param bool|null $on `true`/`false` to set, `null` (default) to read.
     * @return bool The setting in effect after the call.
     */
    public static function strict(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$strict = $on;
        }

        return self::$strict;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $code HTTP status code (e.g. 200, 404).
     * @return $this
     */
    public function setStatus(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Get the response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set a header field, replacing every value already stored for that name.
     *
     * A complete line (`"Content-Type: application/json"`) may be passed instead of
     * a name and value; a field name can never contain a colon, so the two forms
     * cannot be confused. Use {@see addHeader()} to append a second value to a
     * repeated field such as `Set-Cookie` instead of replacing it.
     *
     * @param string      $name  Header field name, or a complete `Name: value` line.
     * @param string|null $value Header value; omit when passing a complete line.
     * @return $this
     * @throws \InvalidArgumentException When the line has no colon.
     */
    public function setHeader(string $name, ?string $value = null): static
    {
        if ($value === null) {
            [$name, $value] = self::parseHeaderLine($name);
        } elseif (self::$strict) {
            self::validateName($name);
            self::validateValues($name, [$value]);
        }

        $lower = strtolower($name);
        $key   = $this->headerIndex[$lower] ?? null;

        if ($key === null) {
            $this->headerIndex[$lower] = $name;
            $this->headers[$name]      = [$value];
            return $this;
        }

        $this->headers[$key] = [$value];
        return $this;
    }

    /**
     * Set several header fields, each with {@see setHeader()} semantics.
     *
     * Accepts a list of complete lines (`['X-Trace: abc']`) or a name => value map
     * (`['X-Trace' => 'abc']`). A list of values replaces the field and keeps every
     * one, as PSR-7's `withHeader()` does; {@see addHeaders()} appends instead.
     *
     * @param array<int|string, string|list<string>> $headers Fields to set.
     * @return $this
     * @throws \InvalidArgumentException When a line has no colon.
     */
    public function setHeaders(array $headers): static
    {
        if (isset($headers[0])) {
            foreach ($headers as $line) {
                $this->setHeader((string) $line);
            }

            return $this;
        }

        foreach ($headers as $name => $values) {
            $values = (array) $values;
            if (self::$strict) {
                self::validateName($name);
                self::validateValues($name, $values);
            }
            $lower = strtolower($name);
            $key   = $this->headerIndex[$lower] ?? null;

            if ($key === null) {
                $this->headerIndex[$lower] = $name;
                $key = $name;
            }

            $this->headers[$key] = $values;
        }

        return $this;
    }

    /**
     * Append values to a header field without replacing the ones already set.
     *
     * This is what {@see Cookies::applyTo()} and {@see addCookie()} use, because a
     * second `Set-Cookie` must not overwrite the first.
     *
     * @param string      $name  Header field name, or a complete `Name: value` line.
     * @param string|null $value Header value; omit when passing a complete line.
     * @return $this
     * @throws \InvalidArgumentException When the line has no colon.
     */
    public function addHeader(string $name, ?string $value = null): static
    {
        if ($value === null) {
            [$name, $value] = self::parseHeaderLine($name);
        } elseif (self::$strict) {
            self::validateName($name);
            self::validateValues($name, [$value]);
        }

        $lower = strtolower($name);
        $key   = $this->headerIndex[$lower] ?? null;

        if ($key === null) {
            $this->headerIndex[$lower] = $name;
            $this->headers[$name]      = [$value];
            return $this;
        }

        $this->headers[$key][] = $value;
        return $this;
    }

    /**
     * Append several header fields, each with {@see addHeader()} semantics.
     *
     * @param array<int|string, string|list<string>> $headers Fields to append.
     * @return $this
     */
    public function addHeaders(array $headers): static
    {
        if (isset($headers[0])) {
            foreach ($headers as $line) {
                $this->addHeader($line);
            }
        } else {
            foreach ($headers as $key => $value) {
                foreach ((array) $value as $item) {
                    $this->addHeader($key, (string) $item);
                }
            }
        }
        return $this;
    }

    /**
     * Append a cookie as a `Set-Cookie` header.
     *
     * The formatting lives in {@see Cookie::toHeader()}, so this is a delegation and
     * not a second implementation. A cookie the application read is never sent —
     * that decision belongs to {@see Cookies::applyTo()}, which only emits written
     * cookies; this method always writes what it is given.
     *
     * @return $this
     */
    public function addCookie(Cookie $cookie): static
    {
        $this->addHeader('Set-Cookie', $cookie->toHeader());
        $cookie->markClean();
        return $this;
    }

    /**
     * Get the value of every header field, keyed by field name.
     *
     * Each name maps to a list of values, in the order they were added, so a
     * repeated field such as `Set-Cookie` keeps every one. This is the shape
     * PSR-7's `getHeaders()` uses. Use {@see getHeader()} for a single value and
     * {@see getHeaderLines()} for the wire format.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get every value of one header field, or an empty list when it is absent.
     *
     * @param string $name Header field name (matched case-insensitively).
     * @return list<string>
     */
    public function getHeaderValues(string $name): array
    {
        $key = $this->headerIndex[strtolower($name)] ?? null;

        return $key === null ? [] : $this->headers[$key];
    }

    /**
     * Get the first value of the given header field.
     *
     * A header present with an empty value returns `''`; an absent header returns
     * `$default` (`null` unless given).
     *
     * @param string      $name    Header field name (matched case-insensitively).
     * @param string|null $default Value to return when the header is absent.
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $key = $this->headerIndex[strtolower($name)] ?? null;

        if ($key === null) {
            return $default;
        }

        return $this->headers[$key][0];
    }

    /**
     * Get all headers in wire format, e.g. `['Content-Type: application/json']`.
     *
     * A repeated field appears once per value. This is what `header()` and the
     * worker adapters write; nothing in this class depends on it.
     *
     * @return list<string>
     */
    public function getHeaderLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }

        return $lines;
    }

    /**
     * Append text to the response body.
     *
     * @param string $text Content to append.
     * @return $this
     */
    public function write(string $text): static
    {
        $this->body .= $text;
        return $this;
    }

    /**
     * Send the response: emit the status code, headers, and body.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $values) {
                foreach ($values as $index => $value) {
                    // The first value of a field replaces whatever the SAPI set;
                    // later ones must not, or a second `Set-Cookie` is dropped.
                    header("{$name}: {$value}", $index === 0);
                }
            }
        }

        echo $this->body;
    }

    /**
     * Split a complete `Name: value` line into its name and trimmed value.
     *
     * This is the path a header built by concatenation takes —
     * `new Response(headers: ['ETag: ' . $etag])` — so it is validated under the
     * same switch as the name/value setters. Validating only the two-argument
     * form would leave the style most code actually writes unchecked.
     *
     * @return array{0: string, 1: string}
     * @throws \InvalidArgumentException When the line has no colon, or (in
     *         strict mode) when its name or value is not a valid field.
     */
    private static function parseHeaderLine(string $line): array
    {
        $name = strstr($line, ':', true);
        if ($name === false) {
            throw new \InvalidArgumentException("Header line must contain a colon: {$line}");
        }

        $value = ltrim(substr($line, strlen($name) + 1));

        if (self::$strict) {
            self::validateName($name);
            self::validateValues($name, [$value]);
        }

        return [$name, $value];
    }

    /**
     * Validate a field name, reusing an earlier verdict for the same name.
     *
     * A name is a pure function of itself and the same names recur on every
     * request, so the token check is memoised. Values are never memoised: they
     * carry the request's data.
     *
     * @throws \InvalidArgumentException When the name is not an RFC 9110 token.
     */
    private static function validateName(string &$name): void
    {
        if (isset(self::$validNames[$name])) {
            return;
        }

        // RFC 9110 field-name is a non-empty token; a colon or whitespace in a
        // name would let the name and value run together into a second field.
        if ($name === '' || strcspn($name, "()<>@,;:\\\"/[]?={} \t\r\n\x00") !== strlen($name)) {
            throw new \InvalidArgumentException("Invalid header name: {$name}");
        }

        self::$validNames[$name] = true;
    }

    /**
     * Reject a value that would end its field and start another one.
     *
     * @param string       $name   Field name, for the message.
     * @param list<string> $values The values to check.
     * @throws \InvalidArgumentException When a value contains CR, LF or NUL.
     */
    private static function validateValues(string $name, array $values): void
    {
        foreach ($values as $value) {
            if (strpbrk($value, "\r\n\x00") !== false) {
                throw new \InvalidArgumentException("Invalid header value for {$name}: {$value}");
            }
        }
    }

    /**
     * Validate a whole map, once per distinct name.
     *
     * @param array<string, list<string>> $headers Fields to check.
     * @throws \InvalidArgumentException When a name or a value is not valid.
     */
    private static function validateHeaderMap(array $headers): void
    {
        foreach ($headers as $name => $values) {
            self::validateName($name);
            self::validateValues($name, $values);
        }
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data   Data to JSON-encode.
     * @param int   $status HTTP status code (default 200).
     * @return static
     */
    public static function json(mixed $data, int $status = 200): static
    {
        return new static(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Create a plain-text response.
     *
     * @param string $text   Response body.
     * @param int    $status HTTP status code (default 200).
     * @return static
     */
    public static function text(string $text, int $status = 200): static
    {
        return new static(
            status: $status,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            body: $text
        );
    }

    /**
     * Create an HTML response.
     *
     * @param string $html   HTML content.
     * @param int    $status HTTP status code (default 200).
     * @return static
     */
    public static function html(string $html, int $status = 200): static
    {
        return new static(
            status: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $html
        );
    }

    /**
     * Create a redirect response.
     *
     * @param string $url    URL to redirect to.
     * @param int    $status HTTP redirect status code (default 302).
     * @return static
     */
    public static function redirect(string $url, int $status = 302): static
    {
        return new static(
            status: $status,
            headers: ['Location' => $url],
            body: ''
        );
    }

    /**
     * Create a response with only a status code and an empty body.
     *
     * @param int $status HTTP status code.
     * @return static
     */
    public static function status(int $status): static
    {
        return new static(status: $status);
    }

}
