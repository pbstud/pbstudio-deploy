<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AchievementThresholdOptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;

#[ORM\Entity(repositoryClass: AchievementThresholdOptionRepository::class)]
#[ORM\Table(name: 'achievement_threshold_option')]
#[Index(columns: ['condition_id', 'active', 'sort_order'], name: 'idx_threshold_condition')]
class AchievementThresholdOption implements TimestampableInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AchievementConditionCatalog::class, inversedBy: 'thresholdOptions')]
    #[ORM\JoinColumn(name: 'condition_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AchievementConditionCatalog $condition = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $optionValue = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $optionLabel = null;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 100])]
    private ?int $sortOrder = 100;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $active = true;

    public function __toString(): string
    {
        return (string) ($this->optionLabel ?? $this->optionValue);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCondition(): ?AchievementConditionCatalog
    {
        return $this->condition;
    }

    public function setCondition(?AchievementConditionCatalog $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function getOptionValue(): ?string
    {
        return $this->optionValue;
    }

    public function setOptionValue(string $optionValue): static
    {
        $this->optionValue = $optionValue;

        return $this;
    }

    public function getOptionLabel(): ?string
    {
        return $this->optionLabel;
    }

    public function setOptionLabel(?string $optionLabel): static
    {
        $this->optionLabel = $optionLabel;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
