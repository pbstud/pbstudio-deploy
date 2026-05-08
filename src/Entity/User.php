<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity('email')]
#[UniqueEntity('phone')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TimestampableInterface
{
    use TimestampableTrait;

    public const NUMBER_OF_ITEMS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(groups: ['Registration', 'Profile'])]
    #[Assert\Length(min: 3, max: 50, groups: ['Registration', 'Profile'])]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\NotBlank(groups: ['Registration', 'Profile'])]
    #[Assert\Length(max: 50, groups: ['Registration', 'Profile'])]
    private ?string $lastname = null;

    #[ORM\Column(length: 15, nullable: true)]
    #[Assert\NotBlank(groups: ['Profile'])]
    #[Assert\Length(min: 10, max: 10, groups: ['Profile'])]
    #[Assert\Type(type: 'digit', message: 'Solo se aceptan números', groups: ['Profile'])]
    private ?string $phone = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Assert\NotBlank(message: 'El cumpleaños es obligatorio.')]
    private ?\DateTimeInterface $birthday = null;

    #[ORM\Column(type: Types::JSON)]
    private array $anniversaryTransactionHistory = [];

    #[ORM\Column(type: Types::JSON)]
    private array $anniversaryClassHistory = [];

    #[ORM\Column(type: Types::JSON)]
    private array $anniversaryWindowHistory = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(min: 3, max: 100, groups: ['Profile'])]
    private ?string $emergencyContactName = null;

    #[ORM\Column(length: 15, nullable: true)]
    #[Assert\Length(min: 8, max: 10, groups: ['Profile'])]
    #[Assert\Type(type: 'digit', message: 'Solo se aceptan números', groups: ['Profile'])]
    private ?string $emergencyContactPhone = null;

    #[ORM\Column]
    private ?bool $freeSession = false;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $conektaId = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Transaction::class)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $transactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Reservation::class)]
    private Collection $reservations;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WaitingList::class, orphanRemoval: true)]
    private Collection $waitingLists;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'El correo es obligatorio.')]
    #[Assert\Email(message: 'El correo no es válido.')]
    private ?string $email = null;

    #[ORM\Column]
    private ?bool $enabled = false;

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $confirmationToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $passwordRequestedAt = null;

    #[ORM\Column(type: Types::ARRAY)]
    private array $roles = ['ROLE_USER'];

    #[Assert\NotBlank(groups: ['Registration', 'ResetPassword', 'ChangePassword'])]
    private ?string $plainPassword = null;

    #[ORM\ManyToOne]
    #[Assert\NotBlank(groups: ['Registration'])]
    private ?BranchOffice $branchOffice = null;

    public function __construct()
    {
        $this->waitingLists = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->name, $this->lastname);
    }

    public static function enabledChoices(): array
    {
        return [
            true => 'label.active',
            false => 'label.inactive',
        ];
    }

    public function isPasswordRequestNonExpired(int $ttl): bool
    {
        return $this->passwordRequestedAt instanceof \DateTime
            && $this->passwordRequestedAt->getTimestamp() + $ttl > time();
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

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getBirthday(): ?\DateTimeInterface
    {
        return $this->birthday;
    }

    public function setBirthday(?\DateTimeInterface $birthday): static
    {
        $this->birthday = $birthday;

        return $this;
    }

    public function getAnniversaryTransactionHistory(): array
    {
        return $this->anniversaryTransactionHistory;
    }

    public function setAnniversaryTransactionHistory(array $anniversaryTransactionHistory): static
    {
        $this->anniversaryTransactionHistory = array_values(array_filter(array_map('intval', $anniversaryTransactionHistory), static fn (int $year): bool => $year > 0));

        return $this;
    }

    public function getAnniversaryClassHistory(): array
    {
        return $this->anniversaryClassHistory;
    }

    public function setAnniversaryClassHistory(array $anniversaryClassHistory): static
    {
        $this->anniversaryClassHistory = array_values(array_filter(array_map('intval', $anniversaryClassHistory), static fn (int $year): bool => $year > 0));

        return $this;
    }

    public function getAnniversaryWindowHistory(): array
    {
        return $this->anniversaryWindowHistory;
    }

    public function setAnniversaryWindowHistory(array $anniversaryWindowHistory): static
    {
        $this->anniversaryWindowHistory = array_values(array_filter($anniversaryWindowHistory, static function ($row): bool {
            if (!is_array($row)) {
                return false;
            }

            $window = (string) ($row['window'] ?? '');
            $type = (string) ($row['type'] ?? '');
            $year = (int) ($row['year'] ?? 0);

            return in_array($window, ['this_week', 'this_month'], true)
                && in_array($type, ['transaction', 'class', 'joined'], true)
                && $year > 0;
        }));

        return $this;
    }

    public function getEmergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function setEmergencyContactName(?string $emergencyContactName): static
    {
        $this->emergencyContactName = $emergencyContactName;

        return $this;
    }

    public function getEmergencyContactPhone(): ?string
    {
        return $this->emergencyContactPhone;
    }

    public function setEmergencyContactPhone(?string $emergencyContactPhone): static
    {
        $this->emergencyContactPhone = $emergencyContactPhone;

        return $this;
    }

    public function isFreeSession(): ?bool
    {
        return $this->freeSession;
    }

    public function setFreeSession(bool $freeSession): static
    {
        $this->freeSession = $freeSession;

        return $this;
    }

    public function getConektaId(): ?string
    {
        return $this->conektaId;
    }

    public function setConektaId(?string $conektaId): static
    {
        $this->conektaId = $conektaId;

        return $this;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setUser($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }

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
            $reservation->setUser($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getUser() === $this) {
                $reservation->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WaitingList>
     */
    public function getWaitingLists(): Collection
    {
        return $this->waitingLists;
    }

    public function addWaitingList(WaitingList $waitingList): static
    {
        if (!$this->waitingLists->contains($waitingList)) {
            $this->waitingLists->add($waitingList);
            $waitingList->setUser($this);
        }

        return $this;
    }

    public function removeWaitingList(WaitingList $waitingList): static
    {
        if ($this->waitingLists->removeElement($waitingList)) {
            // set the owning side to null (unless already changed)
            if ($waitingList->getUser() === $this) {
                $waitingList->setUser(null);
            }
        }

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getLastLogin(): ?\DateTimeInterface
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeInterface $lastLogin): static
    {
        $this->lastLogin = $lastLogin;

        return $this;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken(?string $confirmationToken): static
    {
        $this->confirmationToken = $confirmationToken;

        return $this;
    }

    public function getPasswordRequestedAt(): ?\DateTimeInterface
    {
        return $this->passwordRequestedAt;
    }

    public function setPasswordRequestedAt(?\DateTimeInterface $passwordRequestedAt): static
    {
        $this->passwordRequestedAt = $passwordRequestedAt;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
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
}
