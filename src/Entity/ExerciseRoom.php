<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseRoomRepository;
use App\Util\PackageSessionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExerciseRoomRepository::class)]
class ExerciseRoom implements TimestampableInterface
{
    use TimestampableTrait;

    public const NUMBER_OF_ITEMS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column]
    #[Assert\GreaterThan(0)]
    private ?int $capacity = null;

    #[ORM\ManyToOne]
    private ?Discipline $discipline = null;

    #[ORM\Column(length: 25)]
    #[Assert\Choice(choices: PackageSessionType::TYPES)]
    private ?string $type = PackageSessionType::TYPE_GROUP;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\ManyToOne(inversedBy: 'exerciseRooms')]
    private ?BranchOffice $branchOffice = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $placesNotAvailable = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $seatLayout = null;

    public function __toString()
    {
        return (string) $this->name;
    }

    public function getAvailableCapacity(): int
    {
        $capacity = (int) ($this->capacity ?? 0);
        $notAvailable = $this->getPlacesNotAvailable() ?? [];

        return $capacity - count($notAvailable);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function getDiscipline(): ?Discipline
    {
        return $this->discipline;
    }

    public function setDiscipline(?Discipline $discipline): static
    {
        $this->discipline = $discipline;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getBranchOffice(): ?BranchOffice
    {
        return $this->branchOffice;
    }

    public function setBranchOffice(?BranchOffice $branchOffice): static
    {
        $this->branchOffice = $branchOffice;

        return $this;
    }

    public function getPlacesNotAvailable(): ?array
    {
        return $this->placesNotAvailable;
    }

    public function setPlacesNotAvailable(?array $placesNotAvailable): static
    {
        $this->placesNotAvailable = $placesNotAvailable;

        return $this;
    }

    public function getSeatLayout(): ?array
    {
        return $this->seatLayout;
    }

    public function setSeatLayout(?array $seatLayout): static
    {
        $this->seatLayout = $seatLayout;

        return $this;
    }
}
