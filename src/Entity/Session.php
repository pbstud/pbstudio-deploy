<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SessionRepository;
use App\Util\PackageSessionType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: '`session`')]
#[Index(columns: ['status', 'date_start', 'time_start'], name: 'session_status_date_time_idx')]
class Session implements TimestampableInterface
{
    use TimestampableTrait;

    public const NUMBER_OF_ITEMS = 20;

    public const STATUS_OPEN = 1;
    public const STATUS_FULL = 2;
    public const STATUS_CLOSED = 0;
    public const STATUS_CANCEL = -1;
    public const STATUS_NOT_CANCELED = -5;

    // Tiempo mínimo por default para cancelar (minutos).
    public const TIME_CANCEL_INDIVIDUAL = 1440;
    public const TIME_CANCEL_GROUP = 720;

    // Tiempo para cambiar (segundos).
    public const TIME_CHANGE_FROM_SECONDS = 12 * 60 * 60;
    public const TIME_CHANGE_TO_SECONDS = 2 * 60 * 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dateStart = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $timeStart = null;

    #[ORM\ManyToOne]
    private ?ExerciseRoom $exerciseRoom = null;

    #[ORM\Column]
    #[Assert\LessThanOrEqual(
        value: 36,
        message: 'La disponibilidad no puede ser mayor a 36. Ajusta la capacidad de la clase.'
    )]
    private ?int $exerciseRoomCapacity = null;

    #[ORM\Column(length: 25)]
    #[Assert\Choice(choices: PackageSessionType::TYPES)]
    private ?string $type = PackageSessionType::TYPE_GROUP;

    #[ORM\ManyToOne]
    private ?Discipline $discipline = null;

    #[ORM\ManyToOne]
    private ?Staff $instructor = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $status = self::STATUS_OPEN;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $information = null;

    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Reservation::class)]
    private Collection $reservations;

    #[ORM\OneToMany(mappedBy: 'session', targetEntity: WaitingList::class, orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $waitingList;

    #[ORM\ManyToOne]
    private ?BranchOffice $branchOffice = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $placesNotAvailable = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $seatLayout = null;

    #[ORM\Column]
    private ?int $availableCapacity = null;

    public function __construct()
    {
        $this->waitingList = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    public static function statusList(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_FULL,
            self::STATUS_CLOSED,
            self::STATUS_CANCEL,
        ];
    }

    public function isFull(): bool
    {
        return self::STATUS_FULL === $this->status;
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function isClosed(): bool
    {
        return self::STATUS_CLOSED === $this->status;
    }

    public function isIndividual(): bool
    {
        return PackageSessionType::TYPE_INDIVIDUAL === $this->type;
    }
    // Combina dateStart y timeStart en un solo DateTime, pero hay que formatear 
    // timeStart para que tenga la fecha de dateStart, ya que timeStart solo tiene la hora,
    // para evitar problemas de fechas al comparar con la fecha actual
    // y revisar warnings de timezone.
    public function getDateTimeStart(): \DateTimeInterface
    {   
        if (!$this->dateStart || !$this->timeStart) {
            throw new \LogicException('La sesión no tiene fecha u hora de inicio configurada.');
        }

        $dateStart = \DateTimeImmutable::createFromInterface($this->dateStart);
        $dateStart = $dateStart->setTime(
            (int) $this->timeStart->format('H'),
            (int) $this->timeStart->format('i')
        );

        return $dateStart;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateStart(): ?\DateTimeInterface
    {
        return $this->dateStart;
    }

    public function setDateStart(?\DateTimeInterface $dateStart): static
    {
        $this->dateStart = $dateStart;

        return $this;
    }

    public function getTimeStart(): ?\DateTimeInterface
    {
        return $this->timeStart;
    }

    public function setTimeStart(?\DateTimeInterface $timeStart): static
    {
        $this->timeStart = $timeStart;

        return $this;
    }

    public function getExerciseRoom(): ?ExerciseRoom
    {
        return $this->exerciseRoom;
    }

    public function setExerciseRoom(?ExerciseRoom $exerciseRoom): static
    {
        $this->exerciseRoom = $exerciseRoom;

        return $this;
    }

    public function getExerciseRoomCapacity(): ?int
    {
        return $this->exerciseRoomCapacity;
    }

    public function setExerciseRoomCapacity(?int $exerciseRoomCapacity): static
    {
        $this->exerciseRoomCapacity = $exerciseRoomCapacity;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

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

    public function getInstructor(): ?Staff
    {
        return $this->instructor;
    }

    public function setInstructor(?Staff $instructor): static
    {
        $this->instructor = $instructor;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getInformation(): ?string
    {
        return $this->information;
    }

    public function setInformation(?string $information): static
    {
        $this->information = $information;

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setSession($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getSession() === $this) {
                $reservation->setSession(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WaitingList>
     */
    public function getWaitingList(): Collection
    {
        return $this->waitingList;
    }

    public function addWaitingList(WaitingList $waitingList): static
    {
        if (!$this->waitingList->contains($waitingList)) {
            $this->waitingList->add($waitingList);
            $waitingList->setSession($this);
        }

        return $this;
    }

    public function removeWaitingList(WaitingList $waitingList): static
    {
        if ($this->waitingList->removeElement($waitingList)) {
            // set the owning side to null (unless already changed)
            if ($waitingList->getSession() === $this) {
                $waitingList->setSession(null);
            }
        }

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

    public function getAvailableCapacity(): ?int
    {
        return $this->availableCapacity;
    }

    public function setAvailableCapacity(int $availableCapacity): static
    {
        $this->availableCapacity = $availableCapacity;

        return $this;
    }

    public function updateAvailableCapacity(): self
    {
        $capacity = (int) ($this->exerciseRoomCapacity ?? 0);
        $notAvailable = array_values(array_unique(array_filter(
            array_map('intval', $this->getPlacesNotAvailable() ?? []),
            static fn (int $place): bool => $place >= 1 && $place <= $capacity
        )));

        $this->availableCapacity = $capacity - count($notAvailable);

        return $this;
    }
}
