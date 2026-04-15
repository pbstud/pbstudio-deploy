<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[Index(columns: ['place_number'], name: 'place_number_idx')]
#[Index(columns: ['session_id', 'is_available', 'place_number'], name: 'session_available_place_idx')]
#[Index(columns: ['user_id', 'is_available'], name: 'user_available_idx')]
#[Index(columns: ['is_available', 'rated_at', 'session_id'], name: 'reservation_available_rated_session_idx')]
class Reservation implements TimestampableInterface
{
    use TimestampableTrait;

    public const MAX_UNLIMITED_RESERVATIONS = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    private ?Transaction $transaction = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    private ?Session $session = null;

    #[ORM\Column]
    private ?bool $isAvailable = true;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $placeNumber = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $cancellationAt = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $attended = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ratingExercise = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ratingInstructor = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ratingClassType = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $ratedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $changedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): static
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function isIsAvailable(): ?bool
    {
        return $this->isAvailable;
    }

    public function setIsAvailable(bool $isAvailable): static
    {
        $this->isAvailable = $isAvailable;

        return $this;
    }

    public function getPlaceNumber(): ?int
    {
        return $this->placeNumber;
    }

    public function setPlaceNumber(int $placeNumber): static
    {
        $this->placeNumber = $placeNumber;

        return $this;
    }

    public function getCancellationAt(): ?\DateTimeInterface
    {
        return $this->cancellationAt;
    }

    public function setCancellationAt(?\DateTimeInterface $cancellationAt): static
    {
        $this->cancellationAt = $cancellationAt;

        return $this;
    }

    public function isAttended(): ?bool
    {
        return $this->attended;
    }

    public function setAttended(bool $attended): static
    {
        $this->attended = $attended;

        return $this;
    }

    public function getRatingExercise(): ?int
    {
        return $this->ratingExercise;
    }

    public function setRatingExercise(?int $ratingExercise): static
    {
        $this->ratingExercise = $ratingExercise;

        return $this;
    }

    public function getRatingInstructor(): ?int
    {
        return $this->ratingInstructor;
    }

    public function setRatingInstructor(?int $ratingInstructor): static
    {
        $this->ratingInstructor = $ratingInstructor;

        return $this;
    }

    public function getRatingClassType(): ?int
    {
        return $this->ratingClassType;
    }

    public function setRatingClassType(?int $ratingClassType): static
    {
        $this->ratingClassType = $ratingClassType;

        return $this;
    }

    public function getRatedAt(): ?\DateTimeInterface
    {
        return $this->ratedAt;
    }

    public function setRatedAt(?\DateTimeInterface $ratedAt): static
    {
        $this->ratedAt = $ratedAt;

        return $this;
    }

    public function hasRatings(): bool
    {
        return null !== $this->ratingExercise
            || null !== $this->ratingInstructor
            || null !== $this->ratingClassType;
    }

    public function getRatingAverage(): ?float
    {
        if (!$this->hasRatings()) {
            return null;
        }

        $ratings = array_filter([
            $this->ratingExercise,
            $this->ratingInstructor,
            $this->ratingClassType,
        ], static fn (?int $rating): bool => null !== $rating);

        $total = array_sum($ratings);

        return round($total / count($ratings), 1);
    }

    public function getUserRatingAverage(): ?float
    {
        return $this->getRatingAverage();
    }

    public function getChangedAt(): ?\DateTimeInterface
    {
        return $this->changedAt;
    }

    public function setChangedAt(?\DateTimeInterface $changedAt): static
    {
        $this->changedAt = $changedAt;

        return $this;
    }
}
