<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class UserToIdTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (!$value instanceof User) {
            throw new \LogicException('El valor esperado para el campo user debe ser una instancia de User.');
        }

        return (string) $value->getId();
    }

    public function reverseTransform(mixed $value): ?User
    {
        $userId = trim((string) $value);

        if ('' === $userId) {
            return null;
        }

        if (!ctype_digit($userId)) {
            throw new TransformationFailedException('Usuario invalido.');
        }

        $user = $this->userRepository->findOneBy([
            'id' => (int) $userId,
            'enabled' => true,
        ]);

        if (!$user instanceof User) {
            throw new TransformationFailedException('Usuario no encontrado o inactivo.');
        }

        return $user;
    }
}
