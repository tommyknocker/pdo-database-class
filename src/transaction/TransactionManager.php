<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\transaction;

use RuntimeException;
use Throwable;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\events\SavepointCreatedEvent;
use tommyknocker\pdodb\events\SavepointReleasedEvent;
use tommyknocker\pdodb\events\SavepointRolledBackEvent;
use tommyknocker\pdodb\exceptions\TransactionException;
use tommyknocker\pdodb\PdoDb;

/**
 * Transactions for one PdoDb: begin/commit/rollback, nested transactions through savepoints, and the callbacks that
 * run after a commit or a rollback. PdoDb delegates its transaction methods here.
 */
final class TransactionManager
{
    /** @var array<string> Stack of active savepoint names */
    private array $savepointStack = [];

    /**
     * Commit and rollback callbacks stacked with the transaction and its savepoints.
     *
     * @var list<array{commit: list<callable(): void>, rollback: list<callable(): void>}>
     */
    private array $transactionCallbackFrames = [];

    private int $transactionSavepointCounter = 0;

    /**
     * @param \Closure(): ConnectionInterface $connection Current connection of the PdoDb (it can change)
     * @param \Closure(): ?ConnectionRouter $router
     */
    public function __construct(
        private readonly PdoDb $db,
        private readonly \Closure $connection,
        private readonly \Closure $router,
    ) {
    }

    private function connection(): ConnectionInterface
    {
        return ($this->connection)();
    }

    private function router(): ?ConnectionRouter
    {
        return ($this->router)();
    }

    /**
     * Starts a transaction.
     */
    public function startTransaction(): void
    {
        $conn = $this->connection();
        if (!$conn->inTransaction()) {
            // Clear savepoint stack when starting a new transaction
            $this->savepointStack = [];
            $this->resetTransactionCallbackFrames();
            $conn->transaction();
            if ($this->router() !== null) {
                $this->router()->setTransactionState(true);
            }
        }
    }

    /**
     * Commits the transaction.
     */
    public function commit(): void
    {
        $conn = $this->connection();
        if ($conn->inTransaction()) {
            $conn->commit();
            // Clear savepoint stack when committing the main transaction
            $this->savepointStack = [];
            $this->flushTransactionCallbacks(true);
            if ($this->router() !== null) {
                $this->router()->setTransactionState(false);
            }
        }
    }

    /**
     * Rolls back the transaction.
     */
    public function rollback(): void
    {
        $conn = $this->connection();
        if ($conn->inTransaction()) {
            $conn->rollBack();
            // Clear savepoint stack when rolling back the main transaction
            $this->savepointStack = [];
            $this->flushTransactionCallbacks(false);
            if ($this->router() !== null) {
                $this->router()->setTransactionState(false);
            }
        }
    }

    /**
     * Checks if a transaction is currently active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->connection()->inTransaction();
    }

    /**
     * Run a callback after the current transaction commits.
     *
     * Outside a transaction the callback runs immediately. Nested savepoints keep
     * the callback until the outermost commit. A rollback of that savepoint drops it.
     *
     * @param callable(): mixed $callback
     */
    public function afterCommit(callable $callback): void
    {
        if (!$this->inTransaction()) {
            $callback();
            return;
        }

        $this->appendTransactionCallback('commit', $callback);
    }

    /**
     * Run a callback if the current transaction or savepoint rolls back.
     *
     * Used to restore in-memory model state when an outer transaction undoes a
     * nested save. The callback is dropped when the transaction commits.
     *
     * @param callable(): mixed $callback
     */
    public function afterRollback(callable $callback): void
    {
        if (!$this->inTransaction()) {
            return;
        }

        $this->appendTransactionCallback('rollback', $callback);
    }

    /** Open a nested callback scope that can be kept or dropped without a savepoint. */
    public function beginAfterCommitScope(): void
    {
        $this->ensureTransactionCallbackFrame();
        $this->transactionCallbackFrames[] = ['commit' => [], 'rollback' => []];
    }

    /** Keep callbacks registered since beginAfterCommitScope() on the parent scope. */
    public function acceptAfterCommitScope(): void
    {
        $this->mergeTransactionCallbackFrame();
    }

    /** Drop callbacks registered since beginAfterCommitScope() without running them. */
    public function rejectAfterCommitScope(): void
    {
        $this->discardTransactionCallbackFrame(false);
    }

    /**
     * Executes a callback within a transaction.
     *
     * @param callable(PdoDb): mixed $callback The callback to be executed.
     *
     * @return mixed The result of the callback.
     * @throws Throwable If the callback throws an exception, it will be rethrown after rolling back the transaction.
     */
    public function transaction(callable $callback): mixed
    {
        $connection = $this->connection();
        $dialect = $connection->getDialect();
        $nested = $connection->inTransaction();
        $savepoint = null;

        if ($nested) {
            if (!$dialect->supportsNestedTransactions()) {
                throw new TransactionException(
                    'Nested transactions are not supported for driver ' . $connection->getDriverName() . '.'
                );
            }
            do {
                $savepoint = 'pdodb_tx_' . ++$this->transactionSavepointCounter;
            } while (in_array($savepoint, $this->savepointStack, true));
            $connection->query($dialect->buildCreateSavepointSql($savepoint));
            $this->savepointStack[] = $savepoint;
            $this->pushTransactionCallbackFrame();
        } else {
            $this->startTransaction();
        }

        try {
            $result = $callback($this->db);
            if ($this->connection() !== $connection || !$connection->inTransaction()) {
                throw new TransactionException('The transaction connection or state changed inside the callback.');
            }
            if ($nested) {
                if ($dialect->releasesSavepointOnNestedCommit()) {
                    $connection->query($dialect->buildReleaseSavepointSql($savepoint));
                }
                $this->savepointStack = array_values(array_diff($this->savepointStack, [$savepoint]));
                $this->mergeTransactionCallbackFrame();
            } else {
                $this->commit();
            }
            return $result;
        } catch (Throwable $e) {
            try {
                if ($connection->inTransaction()) {
                    if ($nested) {
                        $connection->query($dialect->buildRollbackToSavepointSql($savepoint));
                        if ($dialect->releasesSavepointOnNestedRollback()) {
                            $connection->query($dialect->buildReleaseSavepointSql($savepoint));
                        }
                        $this->savepointStack = array_values(array_diff($this->savepointStack, [$savepoint]));
                        $this->discardTransactionCallbackFrame(true);
                    } elseif ($this->connection() === $connection) {
                        $this->rollback();
                    } else {
                        $connection->rollBack();
                    }
                }
            } catch (Throwable $rollbackError) {
                throw new RuntimeException('Transaction rollback failed: ' . $rollbackError->getMessage(), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Create a savepoint within the current transaction.
     *
     * @param string $name Savepoint name
     *
     * @throws TransactionException If not in a transaction or invalid savepoint name
     */
    public function savepoint(string $name): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new TransactionException('Cannot create savepoint: not in a transaction');
        }

        // Validate savepoint name (prevent SQL injection)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new TransactionException(
                'Invalid savepoint name. Must be a valid identifier (letters, numbers, underscore, starting with letter/underscore)'
            );
        }

        $dialect = $this->connection()->getDialect();
        $sql = $dialect->buildCreateSavepointSql($name);
        $this->connection()->query($sql);
        $this->savepointStack[] = $name;
        $this->pushTransactionCallbackFrame();

        // Dispatch savepoint created event
        $dispatcher = $this->connection()->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->dispatch(new SavepointCreatedEvent(
                $name,
                $this->connection()->getDriverName()
            ));
        }
    }

    /**
     * Rollback to a specific savepoint.
     *
     * @param string $name Savepoint name
     *
     * @throws TransactionException If savepoint doesn't exist or not in a transaction
     */
    public function rollbackToSavepoint(string $name): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new TransactionException('Cannot rollback to savepoint: not in a transaction');
        }

        // Validate savepoint name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new TransactionException(
                'Invalid savepoint name. Must be a valid identifier (letters, numbers, underscore, starting with letter/underscore)'
            );
        }

        // Check if savepoint exists in stack
        $index = array_search($name, $this->savepointStack, true);
        if ($index === false || !is_int($index)) {
            throw new TransactionException("Savepoint '{$name}' not found");
        }

        $dialect = $this->connection()->getDialect();
        $sql = $dialect->buildRollbackToSavepointSql($name);
        $this->connection()->query($sql);

        // Remove all savepoints created after this one from the stack
        // Keep the savepoint we rolled back to (it still exists in database)
        $this->savepointStack = array_slice($this->savepointStack, 0, $index + 1);
        $this->rollbackTransactionCallbackFramesTo($index);

        // Dispatch savepoint rolled back event
        $dispatcher = $this->connection()->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->dispatch(new SavepointRolledBackEvent(
                $name,
                $this->connection()->getDriverName()
            ));
        }
    }

    /**
     * Release a savepoint (remove it without rolling back).
     *
     * @param string $name Savepoint name
     *
     * @throws TransactionException If savepoint doesn't exist or not in a transaction
     */
    public function releaseSavepoint(string $name): void
    {
        if (!$this->connection()->inTransaction()) {
            throw new TransactionException('Cannot release savepoint: not in a transaction');
        }

        // Validate savepoint name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new TransactionException(
                'Invalid savepoint name. Must be a valid identifier (letters, numbers, underscore, starting with letter/underscore)'
            );
        }

        // Check if savepoint exists in stack
        $index = array_search($name, $this->savepointStack, true);
        if ($index === false || !is_int($index)) {
            throw new TransactionException("Savepoint '{$name}' not found");
        }

        $dialect = $this->connection()->getDialect();
        $sql = $dialect->buildReleaseSavepointSql($name);
        $this->connection()->query($sql);

        // Remove this savepoint from the stack (but keep earlier savepoints)
        array_splice($this->savepointStack, $index, 1);
        $this->releaseTransactionCallbackFrameAt($index);

        // Dispatch savepoint released event
        $dispatcher = $this->connection()->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->dispatch(new SavepointReleasedEvent(
                $name,
                $this->connection()->getDriverName()
            ));
        }
    }

    /**
     * Get the list of active savepoints.
     *
     * @return array<string> Array of savepoint names in creation order
     */
    public function getSavepoints(): array
    {
        return $this->savepointStack;
    }

    /**
     * Check if a savepoint exists.
     *
     * @param string $name Savepoint name
     *
     * @return bool True if savepoint exists
     */
    public function hasSavepoint(string $name): bool
    {
        return in_array($name, $this->savepointStack, true);
    }

    private function resetTransactionCallbackFrames(): void
    {
        $this->transactionCallbackFrames = [['commit' => [], 'rollback' => []]];
    }

    private function ensureTransactionCallbackFrame(): void
    {
        if ($this->transactionCallbackFrames === []) {
            $this->resetTransactionCallbackFrames();
        }
    }

    private function pushTransactionCallbackFrame(): void
    {
        $this->ensureTransactionCallbackFrame();
        $this->transactionCallbackFrames[] = ['commit' => [], 'rollback' => []];
    }

    /**
     * @param 'commit'|'rollback' $event
     * @param callable(): mixed $callback
     */
    private function appendTransactionCallback(string $event, callable $callback): void
    {
        $this->ensureTransactionCallbackFrame();
        $frames = $this->transactionCallbackFrames;
        $index = count($frames) - 1;
        $wrapped = static function () use ($callback): void {
            $callback();
        };
        $frame = $frames[$index];
        $commit = $frame['commit'];
        $rollback = $frame['rollback'];
        if ($event === 'commit') {
            $commit[] = $wrapped;
        } else {
            $rollback[] = $wrapped;
        }
        $frames[$index] = ['commit' => $commit, 'rollback' => $rollback];
        $this->transactionCallbackFrames = $frames;
    }

    private function mergeTransactionCallbackFrame(): void
    {
        $frames = $this->transactionCallbackFrames;
        if (count($frames) < 2) {
            return;
        }

        $top = array_pop($frames);
        if (!is_array($top)) {
            return;
        }
        $index = count($frames) - 1;
        $frames[$index]['commit'] = [...$frames[$index]['commit'], ...$top['commit']];
        $frames[$index]['rollback'] = [...$frames[$index]['rollback'], ...$top['rollback']];
        $this->transactionCallbackFrames = $frames;
    }

    private function discardTransactionCallbackFrame(bool $runRollbacks): void
    {
        if (count($this->transactionCallbackFrames) < 2) {
            return;
        }

        $top = array_pop($this->transactionCallbackFrames);
        if ($runRollbacks && is_array($top)) {
            foreach ($top['rollback'] as $callback) {
                $callback();
            }
        }
    }

    private function releaseTransactionCallbackFrameAt(int $savepointIndex): void
    {
        $frameIndex = $savepointIndex + 1;
        if (!isset($this->transactionCallbackFrames[$frameIndex])) {
            return;
        }

        $frame = $this->transactionCallbackFrames[$frameIndex];
        array_splice($this->transactionCallbackFrames, $frameIndex, 1);
        $parent = $frameIndex - 1;
        if (!isset($this->transactionCallbackFrames[$parent])) {
            return;
        }
        foreach ($frame['commit'] as $callback) {
            $this->transactionCallbackFrames[$parent]['commit'][] = $callback;
        }
        foreach ($frame['rollback'] as $callback) {
            $this->transactionCallbackFrames[$parent]['rollback'][] = $callback;
        }
    }

    private function rollbackTransactionCallbackFramesTo(int $savepointIndex): void
    {
        $this->ensureTransactionCallbackFrame();
        $discarded = array_splice($this->transactionCallbackFrames, $savepointIndex + 1);
        foreach ($discarded as $frame) {
            foreach ($frame['rollback'] as $callback) {
                $callback();
            }
        }
        $this->transactionCallbackFrames[] = ['commit' => [], 'rollback' => []];
    }

    private function flushTransactionCallbacks(bool $commit): void
    {
        $frames = $this->transactionCallbackFrames;
        $this->transactionCallbackFrames = [];
        $key = $commit ? 'commit' : 'rollback';
        foreach ($frames as $frame) {
            foreach ($frame[$key] as $callback) {
                $callback();
            }
        }
    }
}
