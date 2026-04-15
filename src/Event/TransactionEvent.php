<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Package;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * TransactionEvent.
 */
class TransactionEvent extends Event
{
    /**
     * Transaction Event constructor.
     *
     * @param Transaction $transaction
     */
    public function __construct(private readonly Transaction $transaction)
    {
    }

    /**
     * @return Package
     */
    public function getPackage(): Package
    {
        return $this->transaction->getPackage();
    }

    /**
     * @return Transaction
     */
    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->transaction->getUser();
    }
}
