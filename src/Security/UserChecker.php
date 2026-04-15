<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Staff;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User Checker.
 *
 * @author JCHR <car.chr@gmail.com>
 */
class UserChecker implements UserCheckerInterface
{
    /**
     * {@inheritdoc}
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isEnabled()) {
            throw new CustomUserMessageAccountStatusException('Invalid login details.');
        }

        if ($user instanceof Staff && !$user->isIsActive()) {
            throw new CustomUserMessageAccountStatusException('Invalid login details.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkPostAuth(UserInterface $user): void
    {
    }
}
