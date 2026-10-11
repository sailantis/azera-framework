<?php declare(strict_types=1);

namespace Azera\Http;

use RuntimeException;

/**
 * Class Request
 * A simple HTTP request handler that abstracts away PHP's superglobals and provides convenient methods to access request data.
 * It also handles method overrides, proxy headers, content negotiation, and file uploads in a consistent way.
 */
class Request
{
    private array $server;
    private array $get;
    private array $post;
    private array $files;
    private ?string $rawBody = null;
    private bool $trustProxyHeaders;
    private array $request;

    /** @var array<string, UploadedFile>|null */
    private ?array $normalizedFiles = null;

    public function __construct(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null,
        bool $trustProxyHeaders = false
    ) {
        $this->server            = $server ?? $_SERVER;
        $this->get               = $get ?? $_GET;
        $this->post              = $post ?? $_POST;
        $this->files             = $files ?? $_FILES;
        $this->trustProxyHeaders = $trustProxyHeaders;
        // GET < POST (explicit order, independent of php.ini)
        $this->request = [...$this->get, ...$this->post];
    }

    /**
     * Get the raw request body
     * Caches the body since php://input can only be read once
     * @return string
     */
    public function body(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) @file_get_contents('php://input');
        }
        return $this->rawBody;
    }

    /**
     * Set the raw request body.
     *
     * Exists for application-server workers (RoadRunner, Stokehold), where the
     * request does not arrive through the SAPI and `php://input` is not the
     * body. In a worker, stdin carries the server's own protocol frames, so
     * letting {@see body()} fall back to `php://input` would consume protocol
     * bytes and desynchronise the stream permanently. A worker adapter injects
     * the body it was handed, and {@see body()} then never reads the pipe.
     *
     * Set it even when the body is empty. `body()` only falls back when the
     * value is null, so an empty string is what stops the read — a bodyless
     * GET in a worker is exactly the case that must not touch `php://input`.
     */
    public function setRawBody(string $body): static
    {
        $this->rawBody = $body;

        return $this;
    }

    /**
     * Get and parse JSON request body
     * @param bool $assoc When true, returns associative arrays. When false, returns objects
     * @return mixed Returns the parsed JSON data, or null on error
     * @throws \RuntimeException if the JSON body cannot be parsed
     */
    public function jsonBody(bool $assoc = true): mixed
    {
        $body = $this->body();
        if ($body === '') {
            return null;
        }
        try {
            $data = json_decode($body, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse JSON body: ' . $e->getMessage(), 0, $e);
        }
        return $data;
    }

    /**
     * Get an input parameter from the request (POST takes precedence over GET)
     * @param ?string $name
     * @param mixed $default
     * @return mixed
     */
    public function input(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->request;
        }
        return $this->request[$name] ?? $default;
    }

    /**
     * Get a query parameter from the request
     * @param ?string $name
     * @param mixed $default
     * @return mixed
     */
    public function query(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->get;
        }
        return $this->get[$name] ?? $default;
    }

    /**
     * Get a GET parameter from the request
     * @param ?string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->get;
        }
        return $this->get[$name] ?? $default;
    }

    /**
     * Get a POST parameter from the request
     * @param ?string $name
     * @param mixed $default
     * @return mixed
     */
    public function post(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->post;
        }
        return $this->post[$name] ?? $default;
    }

    /**
     * Get a server variable from the request
     * @param ?string $name
     * @param mixed $default
     * @return mixed
     */
    public function server(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->server;
        }
        return $this->server[$name] ?? $default;
    }

    /**
     * Get a request header value
     * @param string $name The name of the header (case-insensitive)
     * @param mixed $default The default value to return if the header is not present
     * @return mixed The header value, or the default if not present
     */
    public function header(string $name, mixed $default = null): mixed
    {
        // Convert header name to server key format (e.g. "Content-Type" -> "HTTP_CONTENT_TYPE")
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    // -------------------------
    // Has checks
    // -------------------------
    public function hasInput(string $name): bool
    {
        return isset($this->request[$name]);
    }

    public function hasQuery(string $name): bool
    {
        return isset($this->get[$name]);
    }

    public function hasPost(string $name): bool
    {
        return isset($this->post[$name]);
    }

    /**
     * Get the HTTP method of the request, accounting for method overrides in POST requests
     * @return string
     */
    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Header override (common variants)
        $override = $this->server['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $this->server['X_HTTP_METHOD_OVERRIDE'] ?? $this->server['HTTP_X_METHOD_OVERRIDE'] ?? null;
        if ($method === 'POST' && $override) {
            return strtoupper($override);
        }

        // Body override (e.g. _method)
        if ($method === 'POST' && isset($this->post['_method'])) {
            return strtoupper((string) $this->post['_method']);
        }

        return $method;
    }

    /**
     * Checks whether the request method is POST
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Get the request scheme (http or https)
     * @return string
     */
    public function scheme(): string
    {
        $https = $this->server['HTTPS'] ?? null;
        if ($https && strtolower($https) !== 'off') {
            return 'https';
        }
        if ($this->trustProxyHeaders && !empty($this->server['HTTP_X_FORWARDED_PROTO'])) {
            return explode(',', $this->server['HTTP_X_FORWARDED_PROTO'])[0];
        }
        return 'http';
    }

    /**
     * Checks whether request has been made using HTTPS
     * @return bool
     */
    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Get the host name of the request, accounting for proxy headers and Host header
     * @return string
     */
    public function host(): string
    {
        if ($this->trustProxyHeaders && !empty($this->server['HTTP_X_FORWARDED_HOST'])) {
            return explode(',', $this->server['HTTP_X_FORWARDED_HOST'])[0];
        }
        if (!empty($this->server['HTTP_HOST'])) {
            return $this->server['HTTP_HOST'];
        }
        if (!empty($this->server['SERVER_NAME'])) {
            return $this->server['SERVER_NAME'];
        }
        return 'localhost';
    }

    /**
     * Get the port number of the request, accounting for proxy headers and Host header
     * @return int
     */
    public function port(): int
    {
        // Host header may include port
        $host = $this->server['HTTP_HOST'] ?? '';
        if ($host !== '' && str_contains($host, ':')) {
            $parts = explode(':', $host);
            $port  = (int) end($parts);
            if ($port > 0) {
                return $port;
            }
        }

        if ($this->trustProxyHeaders && !empty($this->server['HTTP_X_FORWARDED_PORT'])) {
            return (int) explode(',', $this->server['HTTP_X_FORWARDED_PORT'])[0];
        }

        return (int) ($this->server['SERVER_PORT'] ?? ($this->scheme() === 'https' ? 443 : 80));
    }

    /**
     * Get the full URI of the request
     * @return string
     */
    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * Get the path component of the request URI (without query string)
     * @return string
     */
    public function path(): string
    {
        $path = parse_url($this->uri(), PHP_URL_PATH);
        return $path === false || $path === null ? '/' : $path;
    }

    /**
     * Get the client IP address, accounting for proxy headers if trusted
     * @param bool $trustForwarded
     * @return string|false
     */
    public function clientIp(bool $trustForwarded = false): string|false
    {
        // 1) Proxy headers allowed?
        if ($trustForwarded && $this->trustProxyHeaders) {

            // RFC 7239: Forwarded: for=...
            if (!empty($this->server['HTTP_FORWARDED'])) {
                $ip = $this->extractFromForwarded($this->server['HTTP_FORWARDED']);
                if ($ip) {
                    return $ip;
                }
            }

            // X-Forwarded-For: may contain multiple IPs
            $xff = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;

            if ($xff) {
                // First hop
                $first = trim(explode(',', $xff)[0]);

                // IPv6 with port: [2001:db8::1]:1234
                if (str_starts_with($first, '[')) {
                    $end = strpos($first, ']');
                    if ($end !== false) {
                        return substr($first, 1, $end - 1);
                    }
                }

                // Remove trailing :port if present
                return preg_replace('/:\d+$/', '', $first);
            }

            // Fallback: HTTP_X_REAL_IP
            $ip = $this->server['HTTP_X_REAL_IP'] ?? null;
        }

        // 2) Default: REMOTE_ADDR
        if (!isset($ip)) {
            $ip = $this->server['REMOTE_ADDR'] ?? null;
        }

        return $ip ? trim($ip) : false;
    }

    private function extractFromForwarded(string $header): ?string
    {
        // Split multiple forwarded entries: for=1.2.3.4;proto=https, for=5.6.7.8
        $parts = explode(',', $header);

        // We only care about the first hop
        $first = trim($parts[0]);

        // Extract key-value pairs (for=..., proto=..., host=...)
        // Example: for="[2001:db8::1]:1234";proto=https
        foreach (explode(';', $first) as $pair) {
            $pair = trim($pair);

            if (stripos($pair, 'for=') === 0) {
                $value = trim(substr($pair, 4)); // remove "for="

                $value = trim($value, "\"'");

                // IPv6 in brackets: [2001:db8::1]:1234
                if (str_starts_with($value, '[')) {
                    $end = strpos($value, ']');
                    if ($end !== false) {
                        return substr($value, 1, $end - 1);
                    }
                }

                // Remove trailing :port if present
                return preg_replace('/:\d+$/', '', $value);
            }
        }

        return null;
    }

    /**
     * Get the list of acceptable content types from the Accept header, with quality factors
     * @param bool $sort Whether to sort by quality (highest first)
     * @return array An array of ['accept' => string, 'quality' => float, ...] entries
     */
    public function acceptableContent(bool $sort = false): array
    {
        return $this->parseQualityHeader('HTTP_ACCEPT', 'accept', $sort);
    }

    /**
     * Get the best acceptable content type from the Accept header
     * @return string
     */
    public function bestAccept(): string
    {
        return $this->acceptableContent(true)[0]['accept'] ?? '';
    }

    /**
     * Get the list of acceptable languages from the Accept-Language header, with quality factors
     * @param bool $sort Whether to sort by quality (highest first)
     * @return array An array of ['language' => string, 'quality' => float, ...] entries
     */
    public function languages(bool $sort = false): array
    {
        return $this->parseQualityHeader('HTTP_ACCEPT_LANGUAGE', 'language', $sort);
    }

    /**
     * Get the best acceptable language from the Accept-Language header
     * @return string
     */
    public function bestLanguage(): string
    {
        return $this->languages(true)[0]['language'] ?? '';
    }

    /**
     * Get the list of acceptable encodings from the Accept-Encoding header, with quality factors
     * @param bool $sort Whether to sort by quality (highest first)
     * @return array An array of ['encoding' => string, 'quality' => float, ...] entries
     */
    public function encodings(bool $sort = false): array
    {
        return $this->parseQualityHeader('HTTP_ACCEPT_ENCODING', 'encoding', $sort);
    }

    /**
     * Get the best acceptable encoding from the Accept-Encoding header
     * @return string
     */
    public function bestEncoding(): string
    {
        return $this->encodings(true)[0]['encoding'] ?? '';
    }

    /**
     * Get the list of acceptable charsets from the Accept-Charset header, with quality factors
     * @param bool $sort Whether to sort by quality (highest first)
     * @return array An array of ['charset' => string, 'quality' => float, ...] entries
     */
    public function charsets(bool $sort = false): array
    {
        return $this->parseQualityHeader('HTTP_ACCEPT_CHARSET', 'charset', $sort);
    }

    /**
     * Get the best acceptable charset from the Accept-Charset header
     * @return string
     */
    public function bestCharset(): string
    {
        return $this->charsets(true)[0]['charset'] ?? '';
    }

    private function parseQualityHeader(string $serverKey, string $name, bool $sort): array
    {
        $result = [];
        $header = $this->server[$serverKey] ?? '';
        if ($header === '') {
            return $result;
        }

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode(';', $part);
            $value    = array_shift($segments);
            $quality  = 1.0;
            $params   = [];
            foreach ($segments as $seg) {
                $seg = trim($seg);
                if (str_starts_with($seg, 'q=')) {
                    $quality = (float) substr($seg, 2);
                } elseif (str_contains($seg, '=')) {
                    [$k, $v] = explode('=', $seg, 2);
                    $params[trim($k)] = trim($v);
                }
            }
            $entry = [...['quality' => $quality, $name => $value], ...$params];
            $result[] = $entry;
        }

        if ($sort) {
            usort($result, fn($a, $b) => $b['quality'] <=> $a['quality']);
        }

        return $result;
    }

    /**
     * Checks whether the request expects a JSON response based on Content-Type or Accept headers
     * @return bool
     */
    public function isJson(): bool
    {
        $ct = $this->server['CONTENT_TYPE'] ?? '';
        if ($ct !== '' && str_contains($ct, 'application/json')) {
            return true;
        }
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return true;
        }
        return false;
    }

    /**
     * Checks whether the request is an AJAX request based on X-Requested-With header or if it expects JSON
     * @return bool
     */
    public function isAjax(): bool
    {
        if ($this->isJson()) {
            return true;
        }
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Get Basic Auth credentials from the request, accounting for different server configurations
     * @return array|null Returns ['username' => string, 'password' => string] or null if not present
     */
    public function basicAuth(): ?array
    {
        // Default: PHP_AUTH_USER / PHP_AUTH_PW (Apache, built-in server)
        if (isset($this->server['PHP_AUTH_USER'], $this->server['PHP_AUTH_PW'])) {
            return [
                'username' => $this->server['PHP_AUTH_USER'],
                'password' => $this->server['PHP_AUTH_PW'],
            ];
        }

        // Fallback: Authorization Header (often Nginx + PHP-FPM)
        $auth = $this->server['HTTP_AUTHORIZATION']
            ?? $this->server['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;

        if ($auth && str_starts_with(strtolower($auth), 'basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);

            if ($decoded && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                return [
                    'username' => $user,
                    'password' => $pass,
                ];
            }
        }

        return null;
    }

    /**
     * Get any HTTP auth scheme from the request
     * @return array|null Returns ['scheme' => string, 'token' => string] or null if not present
     */
    public function authorization(): ?array
    {
        $auth = $this->server['HTTP_AUTHORIZATION']
            ?? $this->server['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;

        if (!$auth) {
            return null;
        }

        $parts = explode(' ', $auth, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'scheme' => $parts[0],
            'token'  => $parts[1],
        ];
    }

    /**
     * Get the User-Agent string from the request headers
     * @return string
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Get the Content-Type header from the request
     * @return string
     */
    public function contentType(): string
    {
        return $this->server['CONTENT_TYPE'] ?? '';
    }

    // -------------------------
    // Files handling
    // -------------------------

    private function normalizeFiles(): array
    {
        if ($this->normalizedFiles !== null) {
            return $this->normalizedFiles;
        }

        $normalized = [];
        foreach ($this->files as $field => $data) {
            if (!isset($data['name'])) {
                continue;
            }
            if (is_array($data['name'])) {
                $items = [];
                $count = count($data['name']);
                for ($i = 0; $i < $count; $i++) {
                    $items[] = new UploadedFile(
                        $data['name'][$i] ?? '',
                        $data['type'][$i] ?? '',
                        $data['tmp_name'][$i] ?? '',
                        $data['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        $data['size'][$i] ?? 0
                    );
                }
                $normalized[$field] = $items;
            } else {
                $normalized[$field] = new UploadedFile(
                    $data['name'],
                    $data['type'] ?? '',
                    $data['tmp_name'] ?? '',
                    $data['error'] ?? UPLOAD_ERR_NO_FILE,
                    $data['size'] ?? 0
                );
            }
        }

        $this->normalizedFiles = $normalized;
        return $normalized;
    }

    /**
     * Get the first uploaded file for a given field name, or null if not present
     * @param string $key
     * @return UploadedFile|null
     */
    public function file(string $key): ?UploadedFile
    {
        $files = $this->normalizeFiles();
        $value = $files[$key] ?? null;
        if ($value instanceof UploadedFile) {
            return $value;
        }
        if (is_array($value) && count($value) > 0) {
            return $value[0];
        }
        return null;
    }

    /**
     * Get all uploaded files for a given field name, or an empty array if not present
     * @param string $key
     * @return UploadedFile[]
     */
    public function files(string $key): array
    {
        $files = $this->normalizeFiles();
        $value = $files[$key] ?? null;
        if ($value === null) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }
}