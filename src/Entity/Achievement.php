<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;

#[ORM\Entity(repositoryClass: 'App\\Repository\\AchievementRepository')]
#[ORM\Table(name: 'achievement')]
#[Index(columns: ['active', 'sort_order'], name: 'idx_achievement_active_sort')]
#[Index(columns: ['category_key', 'condition_key', 'threshold_type'], name: 'idx_achievement_metric')]
#[Index(columns: ['reward_type', 'active'], name: 'idx_achievement_reward')]
class Achievement implements TimestampableInterface
{
    use TimestampableTrait;

    public const THRESHOLD_TYPE_COUNT = 'count';
    public const THRESHOLD_TYPE_AMOUNT = 'amount';
    public const THRESHOLD_TYPE_DAYS = 'days';
    public const THRESHOLD_TYPE_MONTHS = 'months';
    public const THRESHOLD_TYPE_RATING = 'rating';
    public const THRESHOLD_TYPE_PERCENT = 'percent';

    public const COMPARISON_OPERATOR_GTE = 'gte';
    public const COMPARISON_OPERATOR_GT = 'gt';
    public const COMPARISON_OPERATOR_EQ = 'eq';

    public const REWARD_TYPE_BADGE = 'badge';
    public const REWARD_TYPE_DISCOUNT_PERCENT = 'discount_percent';
    public const REWARD_TYPE_DISCOUNT_FIXED = 'discount_fixed';
    public const REWARD_TYPE_BONUS_CLASSES = 'bonus_classes';
    public const REWARD_TYPE_POINTS = 'points';
    public const REWARD_TYPE_MEMBERSHIP_LEVEL = 'membership_level';

    public const BADGE_LEVEL_BRONZE = 'bronze';
    public const BADGE_LEVEL_SILVER = 'silver';
    public const BADGE_LEVEL_GOLD = 'gold';
    public const BADGE_LEVEL_PLATINUM = 'platinum';
    public const BADGE_LEVEL_DIAMOND = 'diamond';
    public const BADGE_LEVEL_MASTER = 'master';
    public const BADGE_LEVEL_LEGEND = 'legend';
    public const BADGE_LEVEL_MAGIC_SPIRAL = 'magic_spiral';

    // Retos personalizados
    public const BADGE_LEVEL_CHALLENGE_1 = 'challenge_1';
    public const BADGE_LEVEL_CHALLENGE_2 = 'challenge_2';
    public const BADGE_LEVEL_CHALLENGE_3 = 'challenge_3';
    public const BADGE_LEVEL_CHALLENGE_4 = 'challenge_4';
    public const BADGE_LEVEL_CHALLENGE_5 = 'challenge_5';

    // Temporadas
    public const BADGE_LEVEL_SEASON_SPRING  = 'season_spring';
    public const BADGE_LEVEL_SEASON_SUMMER  = 'season_summer';
    public const BADGE_LEVEL_SEASON_FALL    = 'season_fall';
    public const BADGE_LEVEL_SEASON_WINTER  = 'season_winter';
    public const BADGE_LEVEL_SEASON_XMAS    = 'season_xmas';
    public const BADGE_LEVEL_SEASON_SPECIAL = 'season_special';

    public const PERIOD_TYPE_NONE = 'none';
    public const PERIOD_TYPE_DAYS = 'days';
    public const PERIOD_TYPE_DEADLINE = 'deadline';
    /** Explicit open/close window — period_window_start to period_deadline (for seasonal campaigns). */
    public const PERIOD_TYPE_WINDOW = 'window';

    public const DIFFICULTY_EASY   = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD   = 'hard';
    public const DIFFICULTY_EXPERT = 'expert';

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY   => 'Fácil',
        self::DIFFICULTY_MEDIUM => 'Media',
        self::DIFFICULTY_HARD   => 'Difícil',
        self::DIFFICULTY_EXPERT => 'Experto',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 64)]
    private ?string $categoryKey = null;

    #[ORM\Column(length: 64)]
    private ?string $conditionKey = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $conditionContext = null;

    #[ORM\Column(length: 16)]
    private ?string $thresholdType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $targetValue = null;

    #[ORM\Column(length: 3, options: ['default' => self::COMPARISON_OPERATOR_GTE])]
    private ?string $comparisonOperator = self::COMPARISON_OPERATOR_GTE;

    #[ORM\Column(length: 32)]
    private ?string $rewardType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $rewardValue = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $badgeLevel = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $badgeColor = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $badgeIcon = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $badgeLabel = null;

    #[ORM\Column(length: 16, options: ['default' => self::PERIOD_TYPE_NONE])]
    private ?string $periodType = self::PERIOD_TYPE_NONE;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $periodDays = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $periodDeadline = null;

    /**
     * Used by PERIOD_TYPE_WINDOW only.
     * Defines the explicit start date of a campaign/seasonal window.
     * The end date is shared with period_deadline.
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $periodWindowStart = null;

    #[ORM\Column(name: 'is_visible_profile', options: ['default' => true])]
    private ?bool $visibleProfile = true;

    #[ORM\Column(name: 'notify_in_app', options: ['default' => true])]
    private ?bool $notifyInApp = true;

    #[ORM\Column(name: 'notify_special', options: ['default' => false])]
    private ?bool $notifySpecial = false;

    #[ORM\Column(name: 'show_progress', options: ['default' => false])]
    private ?bool $showProgress = false;

    #[ORM\Column(name: 'include_historical_data', options: ['default' => false])]
    private bool $includeHistoricalData = false;

    /** NULL = logro normal; NOT NULL = es un reto contable por challenges_completed */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $difficulty = null;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 100])]
    private ?int $sortOrder = 100;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $active = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public static function badgeLevelChoices(): array
    {
        return [
            // Progresión estándar
            self::BADGE_LEVEL_BRONZE   => 'Bronce',
            self::BADGE_LEVEL_SILVER   => 'Plata',
            self::BADGE_LEVEL_GOLD     => 'Oro',
            self::BADGE_LEVEL_PLATINUM => 'Platino',
            self::BADGE_LEVEL_DIAMOND  => 'Diamante',
            self::BADGE_LEVEL_MASTER   => 'Maestro',
            self::BADGE_LEVEL_LEGEND        => 'Leyenda',
            self::BADGE_LEVEL_MAGIC_SPIRAL  => 'Espiral Mágica',
            // Retos personalizados
            self::BADGE_LEVEL_CHALLENGE_1 => 'Reto 1',
            self::BADGE_LEVEL_CHALLENGE_2 => 'Reto 2',
            self::BADGE_LEVEL_CHALLENGE_3 => 'Reto 3',
            self::BADGE_LEVEL_CHALLENGE_4 => 'Reto 4',
            self::BADGE_LEVEL_CHALLENGE_5 => 'Reto 5',
            // Temporadas
            self::BADGE_LEVEL_SEASON_SPRING  => 'Primavera',
            self::BADGE_LEVEL_SEASON_SUMMER  => 'Verano',
            self::BADGE_LEVEL_SEASON_FALL    => 'Otoño',
            self::BADGE_LEVEL_SEASON_WINTER  => 'Invierno',
            self::BADGE_LEVEL_SEASON_XMAS    => 'Navidad',
            self::BADGE_LEVEL_SEASON_SPECIAL => 'Especial',
        ];
    }

    public static function categoryChoices(): array
    {
        return [
            'asistencia' => 'Asistencia',
            'compras' => 'Compras',
            'antiguedad' => 'Antiguedad',
            'racha' => 'Racha',
            'disciplina' => 'Disciplina',
            'calificacion' => 'Calificacion',
            'instructor' => 'Instructor',
            'comunidad' => 'Comunidad',
            'habitos' => 'Habitos',
        ];
    }

    public static function thresholdTypeChoices(): array
    {
        return [
            self::THRESHOLD_TYPE_COUNT   => 'Conteo',
            self::THRESHOLD_TYPE_AMOUNT  => 'Monto',
            self::THRESHOLD_TYPE_DAYS    => 'Dias',
            self::THRESHOLD_TYPE_MONTHS  => 'Meses',
            self::THRESHOLD_TYPE_RATING  => 'Calificacion',
            self::THRESHOLD_TYPE_PERCENT => 'Porcentaje',
        ];
    }

    public static function comparisonOperatorChoices(): array
    {
        return [
            self::COMPARISON_OPERATOR_GTE => 'Mayor o igual',
            self::COMPARISON_OPERATOR_GT => 'Mayor que',
            self::COMPARISON_OPERATOR_EQ => 'Igual a',
        ];
    }

    public static function rewardTypeChoices(): array
    {
        return [
            self::REWARD_TYPE_BADGE => 'Badge',
            self::REWARD_TYPE_DISCOUNT_PERCENT => 'Descuento %',
            self::REWARD_TYPE_DISCOUNT_FIXED => 'Descuento fijo',
            self::REWARD_TYPE_BONUS_CLASSES => 'Clases bono',
            self::REWARD_TYPE_POINTS => 'Puntos',
            self::REWARD_TYPE_MEMBERSHIP_LEVEL => 'Nivel de membresia',
        ];
    }

    public static function periodTypeChoices(): array
    {
        return [
            self::PERIOD_TYPE_NONE     => 'Sin limite',
            self::PERIOD_TYPE_DAYS     => 'Dias (ventana rolling)',
            self::PERIOD_TYPE_DEADLINE => 'Fecha limite (desde publicacion)',
            self::PERIOD_TYPE_WINDOW   => 'Ventana fija (fecha inicio – fecha cierre)',
        ];
    }

    /**
     * Mapa oficial de paleta por tier (inspirado en progresion competitiva estilo LoL).
     *
     * @return array<string, string>
     */
    public static function badgeHexPaletteByLevel(): array
    {
        return [
            self::BADGE_LEVEL_BRONZE   => '#8f5d43',
            self::BADGE_LEVEL_SILVER   => '#8f9db1',
            self::BADGE_LEVEL_GOLD     => '#c9a227',
            self::BADGE_LEVEL_PLATINUM => '#3f7f93',
            self::BADGE_LEVEL_DIAMOND  => '#3f78c8',
            self::BADGE_LEVEL_MASTER   => '#7a42b5',
            self::BADGE_LEVEL_LEGEND        => '#bfa24e',
            self::BADGE_LEVEL_MAGIC_SPIRAL  => '#5e35b1',
            // Retos personalizados
            self::BADGE_LEVEL_CHALLENGE_1 => '#e05c2a',
            self::BADGE_LEVEL_CHALLENGE_2 => '#d4287e',
            self::BADGE_LEVEL_CHALLENGE_3 => '#1a7a6e',
            self::BADGE_LEVEL_CHALLENGE_4 => '#5b6e8c',
            self::BADGE_LEVEL_CHALLENGE_5 => '#2e3d6b',
            // Temporadas
            self::BADGE_LEVEL_SEASON_SPRING  => '#4caf50',
            self::BADGE_LEVEL_SEASON_SUMMER  => '#f9a825',
            self::BADGE_LEVEL_SEASON_FALL    => '#bf6c1a',
            self::BADGE_LEVEL_SEASON_WINTER  => '#5b9bd5',
            self::BADGE_LEVEL_SEASON_XMAS    => '#c0392b',
            self::BADGE_LEVEL_SEASON_SPECIAL => '#8e44ad',
        ];
    }

    /**
     * Tokens CSS usados por frontend para mantener estilo escalable por tier.
     *
     * @return array<string, string>
     */
    public static function badgeCssTokenByLevel(): array
    {
        return [
            self::BADGE_LEVEL_BRONZE          => 'badge-tier-bronze',
            self::BADGE_LEVEL_SILVER          => 'badge-tier-silver',
            self::BADGE_LEVEL_GOLD            => 'badge-tier-gold',
            self::BADGE_LEVEL_PLATINUM        => 'badge-tier-platinum',
            self::BADGE_LEVEL_DIAMOND         => 'badge-tier-diamond',
            self::BADGE_LEVEL_MASTER          => 'badge-tier-master',
            self::BADGE_LEVEL_LEGEND          => 'badge-tier-legend',
            self::BADGE_LEVEL_MAGIC_SPIRAL     => 'badge-tier-magic-spiral',
            self::BADGE_LEVEL_CHALLENGE_1     => 'badge-tier-challenge-1',
            self::BADGE_LEVEL_CHALLENGE_2     => 'badge-tier-challenge-2',
            self::BADGE_LEVEL_CHALLENGE_3     => 'badge-tier-challenge-3',
            self::BADGE_LEVEL_CHALLENGE_4     => 'badge-tier-challenge-4',
            self::BADGE_LEVEL_CHALLENGE_5     => 'badge-tier-challenge-5',
            self::BADGE_LEVEL_SEASON_SPRING   => 'badge-tier-season-spring',
            self::BADGE_LEVEL_SEASON_SUMMER   => 'badge-tier-season-summer',
            self::BADGE_LEVEL_SEASON_FALL     => 'badge-tier-season-fall',
            self::BADGE_LEVEL_SEASON_WINTER   => 'badge-tier-season-winter',
            self::BADGE_LEVEL_SEASON_XMAS     => 'badge-tier-season-xmas',
            self::BADGE_LEVEL_SEASON_SPECIAL  => 'badge-tier-season-special',
        ];
    }

    public static function resolveBadgeHexColor(?string $badgeLevel): ?string
    {
        if (null === $badgeLevel) {
            return null;
        }

        return self::badgeHexPaletteByLevel()[$badgeLevel] ?? null;
    }

    public static function resolveBadgeCssToken(?string $badgeLevel): ?string
    {
        if (null === $badgeLevel) {
            return null;
        }

        return self::badgeCssTokenByLevel()[$badgeLevel] ?? null;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
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

    public function getConditionContext(): ?array
    {
        return $this->conditionContext;
    }

    public function setConditionContext(array|string|null $conditionContext): static
    {
        if (is_string($conditionContext)) {
            $conditionContext = trim($conditionContext);
            if ($conditionContext === '') {
                $this->conditionContext = null;

                return $this;
            }

            $decoded = json_decode($conditionContext, true);
            $this->conditionContext = is_array($decoded) ? $decoded : null;

            return $this;
        }

        $this->conditionContext = $conditionContext;

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

    public function getTargetValue(): ?string
    {
        return $this->targetValue;
    }

    public function setTargetValue(string $targetValue): static
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function getComparisonOperator(): ?string
    {
        return $this->comparisonOperator;
    }

    public function setComparisonOperator(string $comparisonOperator): static
    {
        $this->comparisonOperator = $comparisonOperator;

        return $this;
    }

    public function getRewardType(): ?string
    {
        return $this->rewardType;
    }

    public function setRewardType(string $rewardType): static
    {
        $this->rewardType = $rewardType;

        return $this;
    }

    public function getRewardValue(): ?string
    {
        return $this->rewardValue;
    }

    public function setRewardValue(?string $rewardValue): static
    {
        $this->rewardValue = $rewardValue;

        return $this;
    }

    public function getBadgeLevel(): ?string
    {
        return $this->badgeLevel;
    }

    public function setBadgeLevel(?string $badgeLevel): static
    {
        $this->badgeLevel = $badgeLevel;

        return $this;
    }

    public function getBadgeColor(): ?string
    {
        return $this->badgeColor;
    }

    public function setBadgeColor(?string $badgeColor): static
    {
        $this->badgeColor = $badgeColor;

        return $this;
    }

    public function getBadgeIcon(): ?string
    {
        return $this->badgeIcon;
    }

    public function setBadgeIcon(?string $badgeIcon): static
    {
        $this->badgeIcon = $badgeIcon;

        return $this;
    }

    public function getBadgeLabel(): ?string
    {
        return $this->badgeLabel;
    }

    public function setBadgeLabel(?string $badgeLabel): static
    {
        $this->badgeLabel = $badgeLabel;

        return $this;
    }

    public function getPeriodType(): ?string
    {
        return $this->periodType;
    }

    public function setPeriodType(string $periodType): static
    {
        $this->periodType = $periodType;

        return $this;
    }

    public function getPeriodDays(): ?int
    {
        return $this->periodDays;
    }

    public function setPeriodDays(?int $periodDays): static
    {
        $this->periodDays = $periodDays;

        return $this;
    }

    public function getPeriodDeadline(): ?\DateTimeInterface
    {
        return $this->periodDeadline;
    }

    public function setPeriodDeadline(?\DateTimeInterface $periodDeadline): static
    {
        $this->periodDeadline = $periodDeadline;

        return $this;
    }

    public function getPeriodWindowStart(): ?\DateTimeInterface
    {
        return $this->periodWindowStart;
    }

    public function setPeriodWindowStart(?\DateTimeInterface $periodWindowStart): static
    {
        $this->periodWindowStart = $periodWindowStart;

        return $this;
    }

    public function isVisibleProfile(): ?bool
    {
        return $this->visibleProfile;
    }

    public function setVisibleProfile(bool $visibleProfile): static
    {
        $this->visibleProfile = $visibleProfile;

        return $this;
    }

    public function isNotifyInApp(): ?bool
    {
        return $this->notifyInApp;
    }

    public function setNotifyInApp(bool $notifyInApp): static
    {
        $this->notifyInApp = $notifyInApp;

        return $this;
    }

    public function isNotifySpecial(): ?bool
    {
        return $this->notifySpecial;
    }

    public function setNotifySpecial(bool $notifySpecial): static
    {
        $this->notifySpecial = $notifySpecial;

        return $this;
    }

    public function isShowProgress(): ?bool
    {
        return $this->showProgress;
    }

    public function setShowProgress(bool $showProgress): static
    {
        $this->showProgress = $showProgress;

        return $this;
    }

    public function isIncludeHistoricalData(): bool
    {
        return $this->includeHistoricalData;
    }

    public function setIncludeHistoricalData(bool $includeHistoricalData): static
    {
        $this->includeHistoricalData = $includeHistoricalData;

        return $this;
    }

    public function getDifficulty(): ?string
    {
        return $this->difficulty;
    }

    public function setDifficulty(?string $difficulty): static
    {
        $this->difficulty = $difficulty;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
