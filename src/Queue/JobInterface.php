<?php

namespace Azera\Queue;

use Throwable;

/**
 * A unit of work that can be pushed onto a queue.
 *
 * Implementations encapsulate the work in {@see handle()}. The default
 * retry configuration (tries, backoff) can be overridden by implementing
 * the corresponding methods.
 *
 * Example:
 * ```php
 * class SyncStripeSeatsJob implements JobInterface
 * {
 *     public function __construct(private string $subscriptionId) {}
 *
 *     public function handle(): void
 *     {
 *         $sub = Stripe::retrieve($this->subscriptionId);
 *         // ... proration logic
 *     }
 *
 *     public function tries(): int { return 3; }
 * }
 * ```
 */
interface JobInterface
{
    /**
     * Perform the job's work.
     *
     * @throws Throwable On failure. The worker will retry if attempts remain.
     */
    public function handle(): void;

    /**
     * Maximum number of attempts (including the first).
     *
     * @return int Default 1 (no retries).
     */
    public function tries(): int;

    /**
     * Seconds (or array of seconds for progressive backoff) to wait
     * before each retry.
     *
     * @return int|array<int> Default 0 (no delay).
     */
    public function backoff(): int|array;

    /**
     * Retry until a specific time or condition.
     *
     * Return a Unix timestamp to cap retries, or null to rely on
     * {@see tries()} only.
     *
     * @return int|null Default null.
     */
    public function retryUntil(): ?int;

    /**
     * Called when the job fails all attempts.
     *
     * @param Throwable $exception The last exception thrown.
     */
    public function failed(Throwable $exception): void;

    /**
     * A unique identifier for the job, used for idempotency and
     * deduplication. Return null to disable deduplication.
     *
     * @return string|null
     */
    public function id(): ?string;

    /**
     * The queue name to push the job onto.
     *
     * @return string Default 'default'.
     */
    public function queue(): string;
}