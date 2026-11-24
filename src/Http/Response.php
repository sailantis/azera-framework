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
     * Whether `send()` delegates a file body to the web server instead of
     * streaming it itself (nginx `X-Sendfile`, Apache `X-Sendfile`).
     *
     * Process-wide, like {@see $strict}, and opt-in: the header is only honoured
     * by a server configured to honour it, and an unconfigured one would answer
     * with an empty body while the application believes it sent the file. Set it
     * once at boot from the deployment's own configuration.
     */
    protected static bool $xSendfile = false;

    /**
     * Absolute path of a file body, or `null` when the body is {@see $body}.
     *
     * Only {@see setFileToSend()} sets this; {@see getBody()} and {@see send()}
     * are the two places that read it, so every transport keeps working without
     * knowing the difference.
     */
    protected ?string $file = null;

    /** Client-facing name for a file body; `null` uses the file's basename. */
    protected ?string $fileDownloadName = null;

    /** Whether a file body is served as an attachment or inline (`Content-Disposition`). */
    protected bool $fileAttachment = true;

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
     * Enable or disable `X-Sendfile` delegation for file bodies, or read it.
     *
     * With it on, `send()` emits `X-Sendfile: <path>` for a response built by
     * {@see setFileToSend()} and sends no body, leaving the transfer to the web
     * server. That is the cheap path for large downloads: PHP returns after
     * reading the file's size instead of streaming every byte. Persistent
     * workers ignore it — there is no web server to delegate to — and stream the
     * file themselves.
     *
     * @param bool|null $on `true`/`false` to set, `null` (default) to read.
     * @return bool The setting in effect after the call.
     */
    public static function xSendfile(?bool $on = null): bool
    {
        if ($on !== null) {
            self::$xSendfile = $on;
        }

        return self::$xSendfile;
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
     *
     * For a response built by {@see setFileToSend()} this reads the file and
     * returns its bytes, so a transport that only knows how to send a string (the
     * RoadRunner and Volucer workers) still transmits it. A caller that wants the
     * efficient path should use `send()` under PHP-FPM or check {@see getFile()}
     * and stream it itself.
     *
     * @throws \RuntimeException When the file has disappeared since it was set.
     */
    public function getBody(): string
    {
        if ($this->file === null) {
            return $this->body;
        }

        $contents = @file_get_contents($this->file);

        if ($contents === false) {
            throw new \RuntimeException("Cannot read response file: {$this->file}");
        }

        return $contents;
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

    // --- Conditional GET (validators) ----------------------------------------

    /**
     * Set the `ETag` validator.
     *
     * The value is a quoted string per RFC 9110. Pass an unquoted value (a hash,
     * a revision, a version) and it is quoted for you; a value that is already
     * quoted is kept as-is, and the weak form (`W/"..."`) is preserved.
     *
     * Pair this with {@see isNotModified()} for the actual 304 decision.
     *
     * @param string $etag Strong or weak entity tag.
     * @return $this
     */
    public function setEtag(string $etag): static
    {
        return $this->setHeader('ETag', self::quoteEtag($etag));
    }

    /**
     * Set the `Last-Modified` validator.
     *
     * Truncated to whole seconds: the header has second resolution, so a value
     * with microseconds would never compare equal to what the client sends back
     * in `If-Modified-Since`.
     *
     * @param \DateTimeInterface|int $lastModified Timestamp or date object.
     * @return $this
     */
    public function setLastModified(\DateTimeInterface | int $lastModified): static
    {
        return $this->setHeader(
            'Last-Modified',
            self::httpDate($lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : $lastModified)
        );
    }

    /**
     * Set the `Expires` header.
     *
     * @param \DateTimeInterface|int $expires Timestamp or date object.
     * @return $this
     */
    public function setExpires(\DateTimeInterface | int $expires): static
    {
        return $this->setHeader(
            'Expires',
            self::httpDate($expires instanceof \DateTimeInterface ? $expires->getTimestamp() : $expires)
        );
    }

    /**
     * Mark the response cacheable for a number of seconds.
     *
     * Sets both `Cache-Control: max-age=N` and `Expires`, because an HTTP/1.0
     * cache only understands the latter while an HTTP/1.1 one ignores it in
     * favour of the former. `Cache-Control` wins where both are present, which is
     * what makes the pair safe.
     *
     * `$shared` adds `public` (a shared cache such as a CDN may store it); without
     * it the response is `private`, which keeps a personalised page out of a CDN
     * and in the browser. `$immutable` marks the body as never changing under that
     * URL — correct for a fingerprinted asset, wrong for anything else.
     *
     * @param int  $seconds   Freshness lifetime in seconds (0 = must revalidate).
     * @param bool $shared    Whether a shared cache may store the response.
     * @param bool $immutable Whether the body never changes under this URL.
     * @return $this
     */
    public function setCache(int $seconds, bool $shared = false, bool $immutable = false): static
    {
        $seconds = max(0, $seconds);
        $parts   = [$shared ? 'public' : 'private', 'max-age=' . $seconds];

        if ($immutable) {
            $parts[] = 'immutable';
        }

        $this->setHeader('Cache-Control', implode(', ', $parts));

        return $this->setExpires(self::now() + $seconds);
    }

    /**
     * Mark the response as non-storable.
     *
     * `no-store` is the directive a sensitive page needs: it forbids writing the
     * response to any cache, which `no-cache` does not (that one allows storing,
     * only requiring revalidation). Also emits the two headers an HTTP/1.0 cache
     * understands.
     *
     * @return $this
     */
    public function setNoCache(): static
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'no-cache');

        return $this->setExpires(0);
    }

    /**
     * Whether the request's validators match this response's, i.e. answer 304.
     *
     * `If-None-Match` is checked first and, when present, wins outright: RFC 9110
     * says a server that receives both validators MUST use the entity tag and
     * ignore `If-Modified-Since` — otherwise a cache holding a stale ETag but a
     * fresh date could be served a body it does not need. `*` matches any existing
     * representation.
     *
     * Comparison is weak for this direction: `W/"x"` and `"x"` are the same
     * representation, so weakening both sides is correct.
     *
     * Only GET and HEAD are eligible, and only when this response carries at least
     * one validator (an unconditional 304 would be a lie).
     */
    public function isNotModified(Request $request): bool
    {
        $method = strtoupper($request->method());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $etag = $this->getHeader('ETag');

        $inm = $request->header('If-None-Match');
        if (is_string($inm) && $inm !== '') {
            if ($etag === null || $etag === '') {
                return false;
            }

            foreach (self::parseEtagList($inm) as $candidate) {
                if ($candidate === '*' || self::weakenEtag($candidate) === self::weakenEtag($etag)) {
                    return true;
                }
            }

            return false;
        }

        $ims = $request->header('If-Modified-Since');
        $lastModified = $this->getHeader('Last-Modified');
        if (!is_string($ims) || $ims === '' || $lastModified === null || $lastModified === '') {
            return false;
        }

        $since = strtotime($ims);
        $mtime = strtotime($lastModified);

        if ($since === false || $mtime === false) {
            return false;
        }

        // Second resolution only: a Last-Modified carrying a fractional second is
        // "not modified" for a client that sent the truncated date.
        return $mtime <= $since;
    }

    /**
     * Turn this into a `304 Not Modified` when the request's validators match.
     *
     * The one-call form of the conditional-GET dance:
     *
     * ```php
     * $response = Response::html($rendered)
     *     ->setEtag($revision)
     *     ->setLastModified($mtime)
     *     ->setCache(3600, shared: true);
     *
     * return $response->notModifiedIfMatches($request);
     * ```
     *
     * On a match the status becomes 304 and the body is dropped, while the
     * validators and `Cache-Control` stay — a cache needs them to refresh its own
     * copy. Per RFC 9110 the remaining representation headers (such as
     * `Content-Type`) are dropped, because a 304 has no body for them to describe.
     */
    public function notModifiedIfMatches(Request $request): static
    {
        if (!$this->isNotModified($request)) {
            return $this;
        }

        $this->status = 304;
        $this->body   = '';
        $this->file   = null;

        foreach (array_keys($this->headers) as $name) {
            $lower = strtolower($name);
            if ($lower === 'etag' || $lower === 'last-modified' || $lower === 'cache-control' || $lower === 'expires') {
                continue;
            }

            unset($this->headers[$name], $this->headerIndex[$lower]);
        }

        return $this;
    }

    /**
     * Replace the body with the given file and describe it as a download.
     *
     * The file is not read here. `getBody()` streams it into the response string
     * (which is what the RoadRunner and Volucer workers send), and `send()`
     * streams it directly under PHP-FPM — or delegates to the web server when
     * {@see xSendfile()} is on.
     *
     * `Content-Type`, `Content-Length` and `Content-Disposition` are filled in from
     * the file itself; `Last-Modified` is set to its mtime so the download also
     * participates in conditional GET, and `ETag` is derived from size + mtime
     * unless one is already set.
     *
     * @param string      $path           Absolute path to an existing, readable file.
     * @param string|null $downloadName   Name the client should save it as (default: basename).
     * @param bool        $attachment     `true` downloads, `false` renders inline.
     * @return $this
     * @throws \InvalidArgumentException When the path is not a readable file.
     */
    public function setFileToSend(string $path, ?string $downloadName = null, bool $attachment = true): static
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("File to send is not a readable file: {$path}");
        }

        $this->file             = $path;
        $this->fileDownloadName = $downloadName;
        $this->fileAttachment   = $attachment;
        $this->body             = '';

        $size = filesize($path);
        $mtime = filemtime($path);

        $this->setHeader('Content-Type', self::fileMediaType($path));
        $this->setHeader('Content-Length', (string) (int) $size);
        $this->setHeader('Content-Disposition', self::disposition($downloadName ?? basename($path), $attachment));

        if ($mtime !== false) {
            $this->setLastModified($mtime);

            if ($this->getHeader('ETag') === null) {
                $this->setEtag(dechex((int) $size) . '-' . dechex($mtime));
            }
        }

        return $this;
    }

    /** The path of a file body, or `null` when the body is a string. */
    public function getFile(): ?string
    {
        return $this->file;
    }

    /**
     * Send the response: emit the status code, headers, and body.
     *
     * A file body is delegated to the web server when {@see xSendfile()} is on
     * (one `X-Sendfile` header, no body) and streamed otherwise, so a large
     * download never becomes a PHP string in the second case and never costs PHP
     * a copy in the first.
     */
    public function send(): void
    {
        $delegate = $this->file !== null && self::$xSendfile;

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $values) {
                foreach ($values as $index => $value) {
                    // The first value of a field replaces whatever the SAPI set;
                    // later ones must not, or a second `Set-Cookie` is dropped.
                    header("{$name}: {$value}", $index === 0);
                }
            }

            if ($delegate) {
                header('X-Sendfile: ' . $this->file);
            }
        }

        if ($delegate) {
            return;
        }

        if ($this->file !== null) {
            // readfile() streams in chunks; a resident worker never reaches this
            // branch (it takes getBody()), so only a SAPI pays for it, and even
            // then no full copy of the file is held in memory.
            if (@readfile($this->file) === false) {
                throw new \RuntimeException("Cannot read response file: {$this->file}");
            }

            return;
        }

        echo $this->body;
    }

    // --- Header value helpers ------------------------------------------------

    /** A `DateTime`-style timestamp as an RFC 9110 IMF-fixdate, always GMT. */
    private static function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }

    /** The current time, in one place so tests can reason about `setCache()`. */
    private static function now(): int
    {
        return time();
    }

    /**
     * Quote an entity tag per RFC 9110.
     *
     * An already-quoted value is passed through untouched, so a caller can hand in
     * a weak tag (`W/"abc"`) without it being re-quoted into garbage.
     */
    private static function quoteEtag(string $etag): string
    {
        $etag = trim($etag);

        if ($etag === '') {
            return '""';
        }

        if (preg_match('/^(W\/)?"[^"]*"$/D', $etag) === 1) {
            return $etag;
        }

        return '"' . str_replace('"', '', $etag) . '"';
    }

    /**
     * Strip the weak marker and surrounding quotes from an entity tag.
     *
     * `If-None-Match` uses weak comparison: `W/"x"` and `"x"` denote the same
     * representation for this direction, so both sides are normalised before they
     * are compared.
     */
    private static function weakenEtag(string $etag): string
    {
        $etag = trim($etag);

        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }

        return trim($etag, '"');
    }

    /**
     * Split an `If-None-Match` field into its tags.
     *
     * The grammar allows a comma-separated list and `*`; a tag itself cannot
     * legally contain a comma, so a plain split is exact here.
     *
     * @return list<string>
     */
    private static function parseEtagList(string $header): array
    {
        $tags = [];

        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * `Content-Disposition` for a file body.
     *
     * Both the plain `filename=` and the RFC 5987 `filename*=` forms are emitted
     * when the name has non-ASCII characters: older clients read the former, and
     * the latter is what the grammar requires for anything outside ASCII.
     */
    private static function disposition(string $name, bool $attachment): string
    {
        $type = $attachment ? 'attachment' : 'inline';

        // Strip CR/LF/quote so a crafted name cannot inject a header or break the
        // quoting. Non-ASCII runs collapse to one placeholder per run: replacing
        // per byte would turn "ä" (two UTF-8 bytes) into two underscores. The path
        // never reaches this method — only its basename does.
        $ascii = preg_replace('/[^\x20-\x7E]+|["\\\\]/u', '_', $name);

        if ($ascii === null) {
            // Invalid UTF-8: the /u pattern cannot match at all, so fall back to a
            // byte-wise pass that at least keeps the header well-formed.
            $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name) ?? 'download';
        }

        if ($ascii === $name) {
            return "{$type}; filename=\"{$ascii}\"";
        }

        return "{$type}; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * Media type from the file extension.
     *
     * A small fixed map rather than `finfo`, because the decision is "what will
     * the browser do with this download" and the extension is what it acts on; a
     * sniffed type for a `.zip` is no more correct than the extension. Anything
     * unknown falls back to `application/octet-stream`, which forces a download.
     */
    private static function fileMediaType(string $path): string
    {
        $map = [
            'css'   => 'text/css; charset=utf-8',
            'csv'   => 'text/csv; charset=utf-8',
            'gif'   => 'image/gif',
            'htm'   => 'text/html; charset=utf-8',
            'html'  => 'text/html; charset=utf-8',
            'ico'   => 'image/x-icon',
            'jpeg'  => 'image/jpeg',
            'jpg'   => 'image/jpeg',
            'js'    => 'text/javascript; charset=utf-8',
            'json'  => 'application/json',
            'md'    => 'text/markdown; charset=utf-8',
            'pdf'   => 'application/pdf',
            'png'   => 'image/png',
            'svg'   => 'image/svg+xml',
            'tar'   => 'application/x-tar',
            'txt'   => 'text/plain; charset=utf-8',
            'webp'  => 'image/webp',
            'xml'   => 'application/xml; charset=utf-8',
            'zip'   => 'application/zip',
        ];

        return $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
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
