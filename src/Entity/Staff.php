<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StaffRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StaffRepository::class)]
#[UniqueEntity('username')]
#[UniqueEntity('email')]
class Staff implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const NUMBER_OF_ITEMS = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(mappedBy: 'staff', cascade: ['persist', 'remove'], fetch: 'LAZY')]
    private ?StaffProfile $profile = null;

    #[ORM\ManyToMany(targetEntity: Discipline::class, inversedBy: 'instructors')]
    #[ORM\JoinTable(name: 'instructors_disciplines')]
    #[Assert\NotBlank]
    private Collection $disciplines;

    #[ORM\Column(length: 25, unique: true)]
    private ?string $username = null;

    #[ORM\Column(length: 64)]
    private ?string $password = null;

    #[ORM\Column(length: 60, unique: true, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\Column(type: Types::ARRAY)]
    #[Assert\NotBlank]
    private array $roles = ['ROLE_COLLABORATOR'];

    #[ORM\Column(type: Types::ARRAY)]
    private array $permissions = [];

    #[ORM\Column(nullable: true)]
    private ?bool $isActive = true;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $deleted = false;


    #[ORM\ManyToMany(targetEntity: BranchOffice::class)]
    private Collection $branchOffices;

    public function __construct()
    {
        $this->disciplines = new ArrayCollection();
        $this->branchOffices = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->getProfile();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfile(): ?StaffProfile
    {
        return $this->profile;
    }

    public function setProfile(?StaffProfile $profile): static
    {
        // unset the owning side of the relation if necessary
        if (null === $profile && null !== $this->profile) {
            $this->profile->setStaff(null);
        }

        // set the owning side of the relation if necessary
        if (null !== $profile && $this !== $profile->getStaff()) {
            $profile->setStaff($this);
        }

        $this->profile = $profile;

        return $this;
    }

    /**
     * @return Collection<int, Discipline>
     */
    public function getDisciplines(): Collection
    {
        return $this->disciplines;
    }

    public function addDiscipline(Discipline $discipline): static
    {
        if (!$this->disciplines->contains($discipline)) {
            $this->disciplines->add($discipline);
            $discipline->addInstructor($this);
        }

        return $this;
    }

    public function removeDiscipline(Discipline $discipline): static
    {
        if ($this->disciplines->removeElement($discipline)) {
            $discipline->removeInstructor($this);
        }

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function isEnabled(): bool
    {
        return $this->isActive;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

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

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    public function isIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(?bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isDeleted(): ?bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }


    /**
     * @return Collection<int, BranchOffice>
     */
    public function getBranchOffices(): Collection
    {
        return $this->branchOffices;
    }

    public function addBranchOffice(BranchOffice $branchOffice): static
    {
        if (!$this->branchOffices->contains($branchOffice)) {
            $this->branchOffices->add($branchOffice);
        }

        return $this;
    }

    public function removeBranchOffice(BranchOffice $branchOffice): static
    {
        $this->branchOffices->removeElement($branchOffice);

        return $this;
    }

    public function getBranchOfficesIds(): array
    {
        $ids = [];

        /** @var BranchOffice $branchOffice */
        foreach ($this->branchOffices->toArray() as $branchOffice) {
            $ids[] = $branchOffice->getId();
        }

        return $ids;
    }
}
