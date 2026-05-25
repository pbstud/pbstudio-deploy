<?php

namespace App\Entity;

use App\Repository\AchievementBadgeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AchievementBadgeRepository::class)]
#[ORM\Table(name: 'achievement_badge')]
class AchievementBadge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Clave única usada en el wizard y guardada en Achievement (e.g. 'bronze', 'challenge_1') */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $badgeKey;

    /** Emoji o URL del ícono */
    #[ORM\Column(type: 'string', length: 32)]
    private string $icon;

    /** Nombre visible (sin emoji), e.g. 'Bronce' */
    #[ORM\Column(type: 'string', length: 64)]
    private string $name;

    /** Puntos por defecto que otorga este nivel */
    #[ORM\Column(type: 'integer')]
    private int $defaultPts = 0;

    /** Color hex usado en el wizard, e.g. '#8f5d43' */
    #[ORM\Column(type: 'string', length: 16)]
    private string $color;

    /** Nombre del grupo en el selector, e.g. 'Progresión estándar' */
    #[ORM\Column(type: 'string', length: 64)]
    private string $badgeGroup;

    /** Orden de aparición dentro del grupo */
    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getBadgeKey(): string { return $this->badgeKey; }
    public function setBadgeKey(string $v): static { $this->badgeKey = $v; return $this; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $v): static { $this->icon = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDefaultPts(): int { return $this->defaultPts; }
    public function setDefaultPts(int $v): static { $this->defaultPts = $v; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $v): static { $this->color = $v; return $this; }

    public function getBadgeGroup(): string { return $this->badgeGroup; }
    public function setBadgeGroup(string $v): static { $this->badgeGroup = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
}
