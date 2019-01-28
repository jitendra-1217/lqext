<?php

namespace Jitendra\Lqstuff;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

class TransactionHandler
{
    /**
     * @var array
     */
    protected $config;

    /**
     * Stack of transactions(their connection name) in progress.
     * @var array
     */
    protected $transactions;

    /**
     * Map of transaction sequence/level and pending handlers withing them.
     * @var array
     */
    protected $pendingHandlers;

    public function __construct(Dispatcher $dispatcher, array $config)
    {
        $this->setTransactionListeners($dispatcher);
        $this->config = $config;
        $this->transactions = [];
        $this->pendingHandlers = [];
    }

    /**
     * @param  mixed   $command
     * @param  Closure $callback
     * @return mixed
     */
    public function handler(mixed $command, Closure $callback): mixed
    {
        if ($this->shouldBeSync($command)) {
            return $callback();
        } else {
            $this->pushPendingHandler($handler);
        }
    }

    /**
     * @param  Closure $callback
     * @return void
     */
    public function pushPendingHandler(Closure $callback)
    {
        $this->pendingHandlers[count($this->transactions)][] = $callback;
    }

    /**
     * @param Dispatcher $dispatcher
     */
    protected function setTransactionListeners(Dispatcher $dispatcher)
    {
        $dispatcher->listen(
            TransactionBeginning::class,
            function (TransactionBeginning $event) {
                $this->transactionBeginning($event->connection);
            }
        );
        $dispatcher->listen(
            TransactionCommitted::class,
            function (TransactionCommitted $event) {
                $this->transactionCommitted($event->connection);
            }
        );
        $dispatcher->listen(
            TransactionRolledBack::class,
            function (TransactionRolledBack $event) {
                $this->transactionRolledBack($event->connection);
            }
        );
    }

    protected function transactionBeginning(Connection $connection)
    {
        array_unshift($this->transactions, $connection->getName());
    }

    protected function transactionCommitted(Connection $connection)
    {
        $pendingHandlers = $this->pendingHandlers[count($this->transactions)];
        array_shift($this->transactions);
        // If a wrapping transaction exists with same connection name at a level
        // above, merge pending handlers of this level with that one.
        // Else invoke this level handlers.
        if (($level = array_search($connection->getName(), $this->transactions))) {
            // Because $transaction values starts at 1.
            $level++;
            $this->pendingHandlers[$level] = array_merge($this->pendingHandlers[$level], $pendingHandlers);
        } else {
            foreach ($pendingHandlers as $handler) {
                $handler();
            }
        }
    }

    protected function transactionRolledBack(Connection $connection)
    {
        unset($this->pendingHandlers[count($this->transactions)]);
        array_shift($this->transactions);
    }

    /**
     * Returns true if the event, command or mailer should be just dispatched
     * immediately, false otherwise.
     * @param  mixed $object
     * @return boolean
     */
    protected function shouldBeSync(mixed $object): bool
    {
        if ($this->isTransactionActive()) {
            $isTransactionAware = is_object($object) && in_array(TransactionAware::class, class_uses_recursive($object));
            $isWhitelisted = in_array(is_object($object) ? get_class($object) : $object, $this->config['transaction']['whitelist']);
            return ! $isTransactionAware && ! $isWhitelisted;
        } else {
            return true;
        }
    }

    /**
     * @return boolean
     */
    protected function isTransactionActive(): bool
    {
        return count($this->transactions) > 0;
    }
}