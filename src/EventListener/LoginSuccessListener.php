<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Staff;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final readonly class LoginSuccessListener
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccessEvent(LoginSuccessEvent $event): void
    {
        /** @var User|Staff $user */
        $user = $event->getUser();
        $user->setLastLogin(new \DateTime());

        $this->em->persist($user);
        $this->em->flush();
    }
}
