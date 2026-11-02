<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use Throwable;
use tommyknocker\pdodb\events\OutboxMessageFailedEvent;
use tommyknocker\pdodb\events\OutboxMessagePublishedEvent;
use tommyknocker\pdodb\events\OutboxMessageRetryEvent;
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;

/**
 * Claims outbox rows and invokes the user publisher outside claim transactions.
 */
final class OutboxWorker
{
    public function __construct(
        protected OutboxStorage $storage,
        protected string $workerId,
        protected int $batchSize = 10,
        protected int $lockSeconds = 120,
        protected int $maxAttempts = 25,
    ) {
    }

    /**
     * @param callable(OutboxMessage): void $publish Runs outside any database transaction.
     */
    public function runOnce(callable $publish): OutboxProcessResult
    {
        $token = $this->newWorkerToken();
        $claim = $this->claimWithRetry($token);
        $claimed = $claim->messages;

        $published = 0;
        $scheduledRetry = 0;
        $markedFailed = $claim->unreadable;
        $unconfirmed = 0;

        foreach ($claimed as $message) {
            // Attempts are counted at claim time: a count above the limit means the last allowed
            // attempt ended without confirmation (worker crash or lock expiry), so stop redelivering.
            if ($message->attempts > $this->maxAttempts) {
                if ($this->confirmFailed($message, 'Maximum delivery attempts exceeded without confirmation')) {
                    $markedFailed++;
                } else {
                    $unconfirmed++;
                }
                continue;
            }

            try {
                $publish($message);
                if ($this->confirmPublished($message)) {
                    $published++;
                } else {
                    $unconfirmed++;
                }
            } catch (OutboxPermanentFailureException $e) {
                if ($this->confirmFailed($message, $e->getMessage())) {
                    $markedFailed++;
                } else {
                    $unconfirmed++;
                }
            } catch (OutboxRetryableException $e) {
                if ($this->confirmRetry($message, $e->getMessage(), $scheduledRetry, $markedFailed)) {
                    continue;
                }
                $unconfirmed++;
            } catch (Throwable $e) {
                if ($this->confirmRetry($message, $e->getMessage(), $scheduledRetry, $markedFailed)) {
                    continue;
                }
                $unconfirmed++;
            }
        }

        return new OutboxProcessResult(
            count($claimed) + $claim->unreadable,
            $published,
            $scheduledRetry,
            $markedFailed,
            $unconfirmed,
        );
    }

    protected function claimWithRetry(string $token): OutboxClaim
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->storage->claimBatch($token, $this->batchSize, $this->lockSeconds);
            } catch (Throwable $e) {
                $attempt++;
                if (!OutboxTransientError::isRetryable($e) || $attempt >= 5) {
                    throw $e;
                }
                usleep(50000 * $attempt);
            }
        }
    }

    protected function confirmPublished(OutboxMessage $message): bool
    {
        $confirmed = $this->confirm(function () use ($message): bool {
            return $this->storage->markPublished($message->id, $message->lockToken);
        });
        if ($confirmed) {
            $this->storage->dispatch(new OutboxMessagePublishedEvent($message));
        }

        return $confirmed;
    }

    protected function confirmFailed(OutboxMessage $message, string $errorMessage): bool
    {
        $confirmed = $this->confirm(function () use ($message, $errorMessage): bool {
            return $this->storage->markFailed($message->id, $message->lockToken, $errorMessage);
        });
        if ($confirmed) {
            $this->storage->dispatch(new OutboxMessageFailedEvent($message, $errorMessage));
        }

        return $confirmed;
    }

    protected function confirmRetry(
        OutboxMessage $message,
        string $errorMessage,
        int &$scheduledRetry,
        int &$markedFailed,
    ): bool {
        return $this->confirm(function () use ($message, $errorMessage, &$scheduledRetry, &$markedFailed): bool {
            return $this->handleRetryOrFail($message, $message->lockToken, $errorMessage, $scheduledRetry, $markedFailed);
        });
    }

    /**
     * @param callable(): bool $operation
     */
    protected function confirm(callable $operation): bool
    {
        // A publisher that left a transaction open would make the status update commit or roll back with it.
        $this->storage->assertConnectionIdle();

        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $attempt++;
                if (!OutboxTransientError::isRetryable($e) || $attempt >= 5) {
                    return false;
                }
                usleep(50000 * $attempt);
            }
        }
    }

    protected function handleRetryOrFail(
        OutboxMessage $message,
        string $token,
        string $errorMessage,
        int &$scheduledRetry,
        int &$markedFailed,
    ): bool {
        if ($message->attempts >= $this->maxAttempts) {
            $updated = $this->storage->markFailed($message->id, $token, $errorMessage);
            if ($updated) {
                $markedFailed++;
                $this->storage->dispatch(new OutboxMessageFailedEvent($message, $errorMessage));
            }

            return $updated;
        }

        $delay = $this->retryDelaySeconds($message->attempts);
        $updated = $this->storage->markRetry($message->id, $token, $delay, $errorMessage);
        if ($updated) {
            $scheduledRetry++;
            $this->storage->dispatch(new OutboxMessageRetryEvent($message, $errorMessage, $delay));
        }

        return $updated;
    }

    protected function retryDelaySeconds(int $attempts): int
    {
        $exponent = min(max($attempts, 1), 12);
        $delay = 5 * (2 ** ($exponent - 1));

        return min(3600, $delay);
    }

    protected function newWorkerToken(): string
    {
        return $this->workerId . ':' . bin2hex(random_bytes(16));
    }
}
