<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\NotificationDispatcherInterface;

/**
 * Despacha la notificación in-app cuando un usuario desbloquea un logro.
 *
 * Debe ser llamado desde el evaluador de logros (AchievementEvaluatorService, SCRUM-288)
 * y desde cualquier flujo manual que otorgue un logro.
 *
 * Tipos de notificación:
 *  - achievement_unlocked          → notificación estándar (notifyInApp=true, notifySpecial=false)
 *  - achievement_unlocked_special  → notificación especial (notifySpecial=true): muestra el overlay
 *                                    animado de "logro desbloqueado" en el frontend además del
 *                                    registro en el centro de notificaciones.
 *
 * Prioridad:
 *  - notifySpecial=true  → HIGH (aparece destacado con borde dorado en el panel)
 *  - notifySpecial=false → MEDIUM
 */
final class AchievementNotificationService
{
    /** Mapeo de badgeLevel → emoji del badge */
    private const BADGE_ICONS = [
        'bronze'          => '🥉',
        'silver'          => '🥈',
        'gold'            => '🥇',
        'platinum'        => '💠',
        'diamond'         => '💎',
        'master'          => '👑',
        'legend'          => '🏆',
        'magic_spiral'    => '🌀',
        'challenge_1'     => '🎯',
        'challenge_2'     => '⚡',
        'challenge_3'     => '🔥',
        'challenge_4'     => '💪',
        'challenge_5'     => '🛡️',
        'season_spring'   => '🌸',
        'season_summer'   => '☀️',
        'season_fall'     => '🍂',
        'season_winter'   => '❄️',
        'season_xmas'     => '🎄',
        'season_special'  => '✨',
    ];

    /** Mapeo de badgeLevel → etiqueta en español */
    private const BADGE_LABELS = [
        'bronze'          => 'Bronce',
        'silver'          => 'Plata',
        'gold'            => 'Oro',
        'platinum'        => 'Platino',
        'diamond'         => 'Diamante',
        'master'          => 'Maestro',
        'legend'          => 'Leyenda',
        'magic_spiral'    => 'Especial',
        'challenge_1'     => 'Reto 1',
        'challenge_2'     => 'Reto 2',
        'challenge_3'     => 'Reto 3',
        'challenge_4'     => 'Reto 4',
        'challenge_5'     => 'Reto 5',
        'season_spring'   => 'Primavera',
        'season_summer'   => 'Verano',
        'season_fall'     => 'Otoño',
        'season_winter'   => 'Invierno',
        'season_xmas'     => 'Navidad',
        'season_special'  => 'Especial',
    ];

    public function __construct(
        private readonly NotificationDispatcherInterface $dispatcher,
    ) {}

    /**
     * Despacha la notificación de logro desbloqueado para el usuario.
     * Si el Achievement no tiene notifyInApp activado, no envía nada.
     */
    public function notifyUnlocked(User $user, Achievement $achievement, bool $isReconquest = false): void
    {
        if (!$achievement->isNotifyInApp()) {
            return;
        }

        $badgeLevel  = $achievement->getBadgeLevel() ?? 'bronze';
        $badgeIcon   = $achievement->getBadgeIcon() ?: (self::BADGE_ICONS[$badgeLevel] ?? '🏅');
        // Prefer the achievement's own badge_label; fall back to the static map; show nothing if unknown.
        $badgeLabel  = ($achievement->getBadgeLabel() ?: null)
            ?? (self::BADGE_LABELS[$badgeLevel] ?? null);
        $isSpecial   = (bool) $achievement->isNotifySpecial();

        $type     = $isSpecial ? 'achievement_unlocked_special' : 'achievement_unlocked';
        $priority = $isSpecial ? Notification::PRIORITY_HIGH : Notification::PRIORITY_MEDIUM;

        $title = $isReconquest ? '¡Logro reconquistado!' : '¡Logro desbloqueado!';
        $body  = $achievement->getName() ?? '';

        // Use a fixed resource_key so the duplicate guard prevents re-sending
        // the same unlock notification for the same achievement.
        $resourceKey = 'achievement_' . $achievement->getId();

        $payload = [
            'achievement_id'   => $achievement->getId(),
            'badge_level'      => $badgeLevel,
            'badge_level_label'=> $badgeLabel,
            'badge_icon'       => $badgeIcon,
            'special'          => $isSpecial,
            'is_reconquest'    => $isReconquest,
            'resource_key'     => $resourceKey,
            'description'      => $achievement->getDescription() ?: null,
        ];

        $this->dispatcher->dispatch($type, $user, $title, $body, $payload, $priority);
    }
}
