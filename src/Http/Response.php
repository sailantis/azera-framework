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
     * @param int    $status  HTTP status code.
     * @param list<string> $headers Complete header lines.
     * @param string $body    Response body.
     */
    public function __construct(
        protected int $status = 200,
        /**
         * Header lines in wire format, e.g. `"Content-Type: application/json"`.
         *
         * @var list<string>
         */
        protected array $headers = [],
        protected string $body = ''
    ) {}

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
     * Replace a header line with the same field name, or append if none exists.
     *
     * @param string $line Complete header line, e.g. "Content-Type: application/json".
     * @return $this
     */
    public function replaceHeader(string $line): static
    {
        $prefix = strstr($line, ':', true);
        if ($prefix === false) {
            throw new \InvalidArgumentException("Invalid header line: {$line}");
        }
        $prefix .= ':';
        $length = strlen($prefix);

        foreach ($this->headers as $index => $existing) {
            if (
                strlen($existing) > $length
                    && substr_compare($existing, $prefix, 0, $length, true) === 0
            ) {
                $this->headers[$index] = $line;
                return $this;
            }
        }
        $this->headers[] = $line;
        return $this;
    }

    /**
     * Replace several header lines, each with {@see replaceHeader()} semantics.
     *
     * @param list<string> $lines Complete header lines.
     * @return $this
     */
    public function replaceHeaders(array $lines): static
    {
        foreach ($lines as $line) {
            $this->replaceHeader((string) $line);
        }

        return $this;
    }

    /**
     * Append a header line without replacing existing lines of the same name.
     *
     * This is what {@see Cookies::applyTo()} and {@see addCookie()} use, because a
     * second `Set-Cookie` must not overwrite the first.
     *
     * @param string $line Complete header line, e.g. "Set-Cookie: theme=dark".
     * @return $this
     */
    public function addHeader(string $line): static
    {
        $this->headers[] = $line;
        return $this;
    }

    /**
     * Append several header lines, each with {@see addHeader()} semantics.
     *
     * @param list<string> $lines Complete header lines.
     * @return $this
     */
    public function addHeaders(array $lines): static
    {
        $this->headers = [...$this->headers, ...$lines];
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
        $this->addHeader('Set-Cookie: ' . $cookie->toHeader());
        $cookie->markClean();
        return $this;
    }

    /**
     * Get all header lines in transmission order, including repeated names.
     *
     * Each entry is a complete `Name: value` line. Use {@see getHeader()} for the
     * value of one header.
     *
     * @return list<string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the value of the first header line matching the given name.
     *
     * Returns the text after `name:`, trimmed. A header present with an empty
     * value returns `''`; an absent header returns `null`. The full line is
     * available from {@see getHeaders()}.
     *
     * @param string $name Header field name (matched case-insensitively).
     * @return string|null The header value, or null when not set.
     */
    public function getHeader(string $name): ?string
    {
        $prefix = "{$name}:";
        $length = strlen($prefix);
        foreach ($this->headers as $line) {
            if (strlen($line) >= $length && substr_compare($line, $prefix, 0, $length, true) === 0) {
                return ltrim(substr($line, $length));
            }
        }
        return null;
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
        http_response_code($this->status);

        foreach ($this->headers as $line) {
            header($line);
        }

        echo $this->body;
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
            headers: ['Content-Type: application/json'],
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
            headers: ['Content-Type: text/plain; charset=utf-8'],
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
            headers: ['Content-Type: text/html; charset=utf-8'],
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
            headers: ["Location: {$url}"],
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