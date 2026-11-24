<?php

namespace Azera\Http;

/**
 * One-request messages shown on the page that follows a redirect.
 *
 * The POST-redirect-GET shape every form needs: an action records a message and
 * redirects; the page it lands on shows it once and the next refresh shows
 * nothing. Storage is the session, so no message is ever carried in a URL.
 *
 * ```php
 * // POST /account
 * $this->flashBag()->success('Account settings updated.');
 * return Response::redirect('/account');
 *
 * // GET /account
 * $messages = $this->flashBag()->consume();   // [['type' => 'success', 'message' => '…']]
 * ```
 *
 * ## When a message becomes visible
 *
 * Messages are queued for the **next** request, which is why this class is not a
 * plain session key: a message written while rendering would otherwise appear on
 * the page the user is already looking at, and again after the refresh. Two
 * buckets make the boundary explicit — {@see add()} (and the type helpers) writes
 * to the bucket for the next request, {@see now()} writes to the one visible
 * immediately.
 *
 * The boundary is crossed by {@see age()}, which must run **once per request**.
 * This class does that itself, the first time a session is available: when it is
 * constructed with a `Session` that is the constructor, and when it is constructed
 * without one (as `AppContext` does) it is the first read or write after
 * {@see useSessionResolver()} resolves a session. Either way the promotion happens
 * exactly once, so the order in which the application touches the bag and the
 * middleware that binds the session cannot matter.
 *
 * Without a session (a CLI task, a unit test, a middleware that runs before
 * `SessionMiddleware`) messages are kept in memory and live for the life of the
 * instance, so the same code runs unguarded.
 */
final class Flash
{
    /** Session key holding the two buckets. */
    public const SESSION_KEY = '_flash';

    /** Bucket names inside the stored state. */
    private const OLD = 'old';
    private const NEW = 'new';

    /**
     * Default CSS class per message type.
     *
     * The Bootstrap alert vocabulary, because it is what an unstyled `alert-*`
     * class is expected to mean in practice: `error` is `danger`, `notice` is
     * `info`. {@see setCssClasses()} replaces the map wholesale.
     *
     * @var array<string, string>
     */
    private const DEFAULT_CLASSES = [
        'success' => 'success',
        'error'   => 'danger',
        'warning' => 'warning',
        'notice'  => 'info',
        'info'    => 'info',
    ];

    /**
     * Default HTML wrapper. `%class%`, `%type%` and `%message%` are substituted.
     *
     * A neutral class pair rather than Bootstrap's `alert alert-*`: the framework
     * does not get to assume a CSS framework, and {@see setCssClasses()} plus
     * {@see setTemplate()} let an application adopt one in two lines.
     */
    private const DEFAULT_TEMPLATE = '<div class="%class%" role="alert">%message%</div>';

    /** @var array<string, string> type => CSS class */
    private array $cssClasses;

    private string $template = self::DEFAULT_TEMPLATE;

    private bool $autoescape = true;

    /** In-memory state when no session is available. */
    private array $local = [self::OLD => [], self::NEW => []];

    /**
     * Resolves the session on first use, when the bag was built without one.
     *
     * A middleware that runs before `SessionMiddleware` binds the session could
     * otherwise pin a session-less bag for the whole request.
     */
    private ?\Closure $sessionResolver = null;

    /**
     * Whether {@see age()} has promoted the previous request's messages already.
     */
    private bool $aged = false;

    /**
     * @param Session|null        $session    Session store, or null for in-memory.
     * @param string              $sessionKey Session key holding the two buckets.
     * @param array<string,string> $cssClasses Type => CSS class map.
     */
    public function __construct(
        private ?Session $session = null,
        private string $sessionKey = self::SESSION_KEY,
        array $cssClasses = self::DEFAULT_CLASSES,
    ) {
        $this->cssClasses = $cssClasses;
    }

    /**
     * Bind a lazily-resolved session and age the bag on first use.
     *
     * `AppContext` uses this so the bag can be built before `SessionMiddleware`
     * has run without losing the session or skipping the request boundary.
     *
     * @param \Closure(): ?Session $resolver Returns the current session, or null.
     */
    public function useSessionResolver(\Closure $resolver): static
    {
        $this->sessionResolver = $resolver;

        return $this;
    }

    // --- Request lifecycle ---------------------------------------------------

    /**
     * Promote the messages queued by the previous request, discarding the ones
     * that were already visible during it.
     *
     * Call once per request, before the application reads or writes a message.
     * `AppContext::flash()` does it when it builds the service.
     */
    public static function age(?Session $session, string $sessionKey = self::SESSION_KEY): void
    {
        if ($session === null) {
            return;
        }

        $state = $session->get($sessionKey);
        $next  = is_array($state) && is_array($state[self::NEW] ?? null) ? $state[self::NEW] : [];

        if ($next === []) {
            $session->remove($sessionKey);

            return;
        }

        $session->set($sessionKey, [self::OLD => array_values($next), self::NEW => []]);
    }

    // --- Writing -------------------------------------------------------------

    /**
     * Queue a message for the next request.
     *
     * @param string $type    Message type; an unknown type is allowed and uses its
     *   own name as the CSS class.
     * @param string $message Untrusted text is escaped on output unless
     *   {@see setAutoescape()} turned that off.
     */
    public function add(string $type, string $message): void
    {
        $state            = $this->read();
        $state[self::NEW][] = ['type' => $type, 'message' => $message];

        $this->write($state);
    }

    /**
     * Show a message on this request's page as well as queueing it for the next.
     *
     * Use it when the action renders instead of redirecting (a re-rendered form
     * with a validation error). The message is consumed with the current request,
     * so it is not shown twice.
     */
    public function now(string $type, string $message): void
    {
        $state            = $this->read();
        $state[self::OLD][] = ['type' => $type, 'message' => $message];

        $this->write($state);
    }

    /** Queue a success message for the next request. */
    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    /** Queue an error message for the next request. */
    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    /** Queue a warning message for the next request. */
    public function warning(string $message): void
    {
        $this->add('warning', $message);
    }

    /** Queue a notice message for the next request. */
    public function notice(string $message): void
    {
        $this->add('notice', $message);
    }

    /** Queue an informational message for the next request. */
    public function info(string $message): void
    {
        $this->add('info', $message);
    }

    /**
     * Keep the messages visible this request available for one more.
     *
     * Moves them back into the next-request bucket, which is what "show this
     * again on the page I am redirecting to" needs.
     */
    public function reflash(): void
    {
        $state            = $this->read();
        $state[self::NEW] = array_merge($state[self::NEW], $state[self::OLD]);
        $state[self::OLD] = [];

        $this->write($state);
    }

    // --- Reading -------------------------------------------------------------

    /**
     * Whether any message is visible on this request.
     *
     * @param string|null $type Restrict the check to one type.
     */
    public function has(?string $type = null): bool
    {
        foreach ($this->read()[self::OLD] as $message) {
            if ($type === null || ($message['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The messages visible on this request.
     *
     * @param string|null $type    Restrict to one type.
     * @param bool        $consume Remove them, so a refresh shows nothing. On by
     *   default, because that is what makes a flash a flash.
     * @return list<array{type: string, message: string}>
     */
    public function consume(?string $type = null, bool $consume = true): array
    {
        $state    = $this->read();
        $messages = [];

        foreach ($state[self::OLD] as $message) {
            if ($type === null || ($message['type'] ?? null) === $type) {
                $messages[] = $message;
            }
        }

        if ($consume && $messages !== []) {
            $state[self::OLD] = $type === null
                ? []
                : array_values(array_filter(
                    $state[self::OLD],
                    static fn(array $message): bool => ($message['type'] ?? null) !== $type
                ));
            $this->write($state);
        }

        return $messages;
    }

    /**
     * The messages queued for the next request, without consuming them.
     *
     * @return list<array{type: string, message: string}>
     */
    public function pending(): array
    {
        return $this->read()[self::NEW];
    }

    /**
     * The first visible message, or null.
     *
     * Unlike {@see consume()}, this removes only the message it returns, so
     * `first()` followed by `consume()` still yields the rest.
     *
     * @param string|null $type    Restrict to one type.
     * @param bool        $consume Remove it from the store.
     * @return array{type: string, message: string}|null
     */
    public function first(?string $type = null, bool $consume = true): ?array
    {
        $state = $this->read();
        $index = null;

        foreach ($state[self::OLD] as $i => $message) {
            if ($type === null || ($message['type'] ?? null) === $type) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $message = $state[self::OLD][$index];

        if ($consume) {
            unset($state[self::OLD][$index]);
            $state[self::OLD] = array_values($state[self::OLD]);
            $this->write($state);
        }

        return $message;
    }

    /** Drop every message, visible and queued. */
    public function clear(): void
    {
        $this->session?->remove($this->sessionKey);

        $this->local = [self::OLD => [], self::NEW => []];
    }

    // --- Rendering -----------------------------------------------------------

    /**
     * Render messages as HTML.
     *
     * @param list<array{type: string, message: string}>|null $messages Messages to
     *   render; the visible ones are consumed when omitted.
     * @return string One block per message, in order.
     */
    public function render(?array $messages = null): string
    {
        $messages ??= $this->consume();
        $html     = '';

        foreach ($messages as $message) {
            $type = (string) ($message['type'] ?? '');
            $text = (string) ($message['message'] ?? '');

            $html .= strtr($this->template, [
                '%class%'   => $this->cssClass($type),
                '%type%'    => $type,
                '%message%' => $this->autoescape
                    ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $text,
            ]);
        }

        return $html;
    }

    /**
     * The CSS class for a message type.
     *
     * Falls back to the type itself, so `add('primary', …)` works without touching
     * the map.
     */
    public function cssClass(string $type): string
    {
        return $this->cssClasses[$type] ?? $type;
    }

    /** The type => CSS class map in effect. */
    public function cssClasses(): array
    {
        return $this->cssClasses;
    }

    /**
     * Replace the type => CSS class map.
     *
     * @param array<string,string> $classes Type => CSS class.
     */
    public function setCssClasses(array $classes): static
    {
        $this->cssClasses = $classes;

        return $this;
    }

    /**
     * Replace the HTML wrapper.
     *
     * `%class%`, `%type%` and `%message%` are substituted; everything else is
     * emitted verbatim, so the template is trusted markup, not data.
     */
    public function setTemplate(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    /** The HTML wrapper in effect. */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Whether messages are escaped before rendering.
     *
     * On by default. Turn it off only for messages the application built itself
     * and deliberately marked up — never for anything a user typed.
     */
    public function setAutoescape(bool $autoescape): static
    {
        $this->autoescape = $autoescape;

        return $this;
    }

    // --- Storage -------------------------------------------------------------

    /**
     * The session backing this bag, resolving it on first use.
     *
     * Ages the bag exactly once at the moment the session first becomes
     * available, which is what makes it irrelevant whether the bag or the
     * session middleware ran first.
     */
    private function session(): ?Session
    {
        if ($this->session === null && $this->sessionResolver !== null) {
            $this->session = ($this->sessionResolver)();
            $this->ageOnce();
        }

        return $this->session;
    }

    /**
     * Promote the previous request's messages, at most once per instance.
     *
     * A no-op while no session is available, so the promotion still happens when
     * the session arrives later in the request.
     */
    private function ageOnce(): void
    {
        if ($this->aged || $this->session === null) {
            return;
        }

        $this->aged = true;
        self::age($this->session, $this->sessionKey);
    }

    /**
     * Read both buckets, normalising anything a foreign writer left behind.
     *
     * @return array{old: list<array{type: string, message: string}>, new: list<array{type: string, message: string}>}
     */
    private function read(): array
    {
        $session = $this->session();

        $state = $session === null
            ? $this->local
            : $session->get($this->sessionKey);

        if (!is_array($state)) {
            $state = [];
        }

        return [
            self::OLD => is_array($state[self::OLD] ?? null) ? array_values($state[self::OLD]) : [],
            self::NEW => is_array($state[self::NEW] ?? null) ? array_values($state[self::NEW]) : [],
        ];
    }

    /**
     * Write both buckets, dropping the session key entirely once both are empty.
     *
     * A response that consumed the last message leaves no `_flash` behind, so an
     * idle session does not carry an empty array forever.
     *
     * @param array{old: list<array>, new: list<array>} $state
     */
    private function write(array $state): void
    {
        if ($state[self::OLD] === [] && $state[self::NEW] === []) {
            $this->session()?->remove($this->sessionKey);
            $this->local = [self::OLD => [], self::NEW => []];

            return;
        }

        $session = $this->session();

        if ($session === null) {
            $this->local = $state;

            return;
        }

        $session->set($this->sessionKey, $state);
    }
}
