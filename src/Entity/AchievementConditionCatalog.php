<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;

#[ORM\Entity(repositoryClass: 'App\\Repository\\AchievementConditionCatalogRepository')]
#[ORM\Table(name: 'achievement_condition_catalog', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uq_condition', columns: ['category_key', 'condition_key'])])]
#[Index(columns: ['active', 'category_key', 'sort_order'], name: 'idx_condition_active')]
class AchievementConditionCatalog implements TimestampableInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $categoryKey = null;

    #[ORM\Column(length: 64)]
    private ?string $conditionKey = null;

    #[ORM\Column(length: 128)]
    private ?string $conditionLabel = null;

    #[ORM\Column(length: 16)]
    private ?string $thresholdType = null;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $allowsCustomValue = true;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $minValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $maxValue = null;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $active = true;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 100])]
    private ?int $sortOrder = 100;

    #[ORM\OneToMany(mappedBy: 'condition', targetEntity: 'App\\Entity\\AchievementThresholdOption', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $thresholdOptions;

    public function __construct()
    {
        $this->thresholdOptions = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->conditionLabel;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategoryKey(): ?string
    {
        return $this->categoryKey;
    }

    public function setCategoryKey(string $categoryKey): static
    {
        $this->categoryKey = $categoryKey;

        return $this;
    }

    public function getConditionKey(): ?string
    {
        return $this->conditionKey;
    }

    public function setConditionKey(string $conditionKey): static
    {
        $this->conditionKey = $conditionKey;

        return $this;
    }

    public function getConditionLabel(): ?string
    {
        return $this->conditionLabel;
    }

    public function setConditionLabel(string $conditionLabel): static
    {
        $this->conditionLabel = $conditionLabel;

        return $this;
    }

    public function getThresholdType(): ?string
    {
        return $this->thresholdType;
    }

    public function setThresholdType(string $thresholdType): static
    {
        $this->thresholdType = $thresholdType;

        return $this;
    }

    public function allowsCustomValue(): ?bool
    {
        return $this->allowsCustomValue;
    }

    public function setAllowsCustomValue(bool $allowsCustomValue): static
    {
        $this->allowsCustomValue = $allowsCustomValue;

        return $this;
    }

    public function getMinValue(): ?string
    {
        return $this->minValue;
    }

    public function setMinValue(?string $minValue): static
    {
        $this->minValue = $minValue;

        return $this;
    }

    public function getMaxValue(): ?string
    {
        return $this->maxValue;
    }

    public function setMaxValue(?string $maxValue): static
    {
        $this->maxValue = $maxValue;

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

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * @return Collection<int, AchievementThresholdOption>
     */
    public function getThresholdOptions(): Collection
    {
        return $this->thresholdOptions;
    }

    public function addThresholdOption(\App\Entity\AchievementThresholdOption $thresholdOption): static
    {
        if (!$this->thresholdOptions->contains($thresholdOption)) {
            $this->thresholdOptions->add($thresholdOption);
            $thresholdOption->setCondition($this);
        }

        return $this;
    }

    public function removeThresholdOption(\App\Entity\AchievementThresholdOption $thresholdOption): static
    {
        if ($this->thresholdOptions->removeElement($thresholdOption) && $thresholdOption->getCondition() === $this) {
            $thresholdOption->setCondition(null);
        }

        return $this;
    }
}
