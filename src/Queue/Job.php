<?php

namespace Azera\Queue;

/**
 * Base implementation of {@see JobInterface} with sensible defaults.
 *
 * Extend this class to create a job without boilerplate:
 *
 * ```php
 * class SendWelcomeEmailJob extends Job
 * {
 *     public function __construct(private string $email) {}
 *
 *     public function handle(): void
 *     {
 *         Mailer::send($this->email, 'Welcome!');
 *     }
 * }
 * ```
 */
abstract class Job implements JobInterface
{
    public function tries(): int
    {
        return 1;
    }

    public function backoff(): int|array
    {
        return 0;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function failed(\Throwable $exception): void
    {
        // Default: do nothing. Override for cleanup / alerting.
    }

    public function id(): ?string
    {
        return null;
    }

    public function queue(): string
    {
        return 'default';
    }
}