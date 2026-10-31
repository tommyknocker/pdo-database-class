<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

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
        $claimed = $this->storage->claimBatch($token, $this->batchSize, $this->lockSeconds);

        $published = 0;
        $scheduledRetry = 0;
        $markedFailed = 0;

        foreach ($claimed as $message) {
            try {
                $publish($message);
                if ($this->storage->markPublished($message->id, $token, gmdate('Y-m-d\TH:i:s\Z'))) {
                    $published++;
                }
            } catch (OutboxPermanentFailureException $e) {
                if ($this->storage->markFailed($message->id, $token, $e->getMessage())) {
                    $markedFailed++;
                }
            } catch (OutboxRetryableException $e) {
                if ($this->handleRetryOrFail($message, $token, $e->getMessage(), $scheduledRetry, $markedFailed)) {
                    continue;
                }
            } catch (\Throwable $e) {
                if ($this->handleRetryOrFail($message, $token, $e->getMessage(), $scheduledRetry, $markedFailed)) {
                    continue;
                }
            }
        }

        return new OutboxProcessResult(
            count($claimed),
            $published,
            $scheduledRetry,
            $markedFailed,
        );
    }

    protected function handleRetryOrFail(
        OutboxMessage $message,
        string $token,
        string $errorMessage,
        int &$scheduledRetry,
        int &$markedFailed,
    ): bool {
        if ($message->attempts >= $this->maxAttempts) {
            if ($this->storage->markFailed($message->id, $token, $errorMessage)) {
                $markedFailed++;
            }

            return true;
        }

        $availableAt = gmdate('Y-m-d\TH:i:s\Z', time() + $this->retryDelaySeconds($message->attempts));
        if ($this->storage->markRetry($message->id, $token, $availableAt, $errorMessage)) {
            $scheduledRetry++;
        }

        return true;
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
