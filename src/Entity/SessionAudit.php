<?php

namespace App\Entity;

use App\Repository\SessionAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SessionAuditRepository::class)]
#[ORM\Table(name: 'session_audit')]
#[ORM\Index(columns: ['session_id'], name: 'idx_session')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created')]
#[ORM\Index(columns: ['audit_type'], name: 'idx_audit_type')]
#[ORM\Index(columns: ['change_flow_id'], name: 'idx_change_flow')]
class SessionAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Session $session = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adminUserIdentifier = null;  // Username/ID del admin que realizó la acción

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userIdentifier = null;  // Username/ID del usuario que realiza acción (cancelar/cambiar)

    #[ORM\Column(length: 50)]
    private ?string $auditType = null;  // 'place_disabled', 'user_cancelled', 'user_changed', 'user_changed_from', 'user_changed_to'

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;  // Motivo del cambio (requerido para admin, opcional para usuario)

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $disabledPlaces = [];  // [1, 3, 5] - solo para place_disabled

    #[ORM\Column(type: Types::JSON)]
    private array $affectedUsers = [];  // [{id: 1, name: 'Juan', email: 'juan@...', place: 3}, ...]

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    private ?int $affectedReservationsCount = 0;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $changeFlowId = null;

    #[ORM\Column(nullable: true)]
    private ?int $reservationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $fromSessionId = null;

    #[ORM\Column(nullable: true)]
    private ?int $toSessionId = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $fromPlace = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $toPlace = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAdminUserIdentifier(): ?string
    {
        return $this->adminUserIdentifier;
    }

    public function setAdminUserIdentifier(?string $adminUserIdentifier): static
    {
        $this->adminUserIdentifier = $adminUserIdentifier;

        return $this;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(?string $userIdentifier): static
    {
        $this->userIdentifier = $userIdentifier;

        return $this;
    }

    public function getAuditType(): ?string
    {
        return $this->auditType;
    }

    public function setAuditType(string $auditType): static
    {
        $this->auditType = $auditType;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getDisabledPlaces(): array
    {
        return $this->disabledPlaces;
    }

    public function setDisabledPlaces(array $disabledPlaces): static
    {
        $this->disabledPlaces = $disabledPlaces;

        return $this;
    }

    public function getAffectedUsers(): array
    {
        return $this->affectedUsers;
    }

    public function setAffectedUsers(array $affectedUsers): static
    {
        $this->affectedUsers = $affectedUsers;

        return $this;
    }

    public function getAffectedReservationsCount(): ?int
    {
        return $this->affectedReservationsCount;
    }

    public function setAffectedReservationsCount(int $affectedReservationsCount): static
    {
        $this->affectedReservationsCount = $affectedReservationsCount;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getChangeFlowId(): ?string
    {
        return $this->changeFlowId;
    }

    public function setChangeFlowId(?string $changeFlowId): static
    {
        $this->changeFlowId = $changeFlowId;

        return $this;
    }

    public function getReservationId(): ?int
    {
        return $this->reservationId;
    }

    public function setReservationId(?int $reservationId): static
    {
        $this->reservationId = $reservationId;

        return $this;
    }

    public function getFromSessionId(): ?int
    {
        return $this->fromSessionId;
    }

    public function setFromSessionId(?int $fromSessionId): static
    {
        $this->fromSessionId = $fromSessionId;

        return $this;
    }

    public function getToSessionId(): ?int
    {
        return $this->toSessionId;
    }

    public function setToSessionId(?int $toSessionId): static
    {
        $this->toSessionId = $toSessionId;

        return $this;
    }

    public function getFromPlace(): ?int
    {
        return $this->fromPlace;
    }

    public function setFromPlace(?int $fromPlace): static
    {
        $this->fromPlace = $fromPlace;

        return $this;
    }

    public function getToPlace(): ?int
    {
        return $this->toPlace;
    }

    public function setToPlace(?int $toPlace): static
    {
        $this->toPlace = $toPlace;

        return $this;
    }
}
