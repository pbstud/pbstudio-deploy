<?php

declare(strict_types=1);

namespace App\Service;

final class NotificationThemeService
{
    /**
     * @return array<string, array<string, string>>
     */
    public function getDefaults(): array
    {
        return [
            'welcome' => $this->preset('sistema', 'bi-person-check', 'Cuenta creada', 'Tu cuenta fue creada correctamente.', '#1976D2', '#1976D2', '#FFFFFF', '#2C2C2C', '#555555'),
            'password_changed' => $this->preset('sistema', 'bi-shield-check', 'Contrasena actualizada', 'Tu credencial fue cambiada con exito.', '#8E24AA', '#8E24AA', '#FFFFFF', '#2C2C2C', '#555555'),
            'payment_confirmed' => $this->preset('payment', 'bi-credit-card', 'Pago confirmado', 'Tu pago fue procesado correctamente.', '#2E7D32', '#2E7D32', '#FFFFFF', '#2C2C2C', '#555555'),
            'transaction_expired' => $this->preset('cancel', 'bi-hourglass-split', 'Paquete vencido', 'Tu paquete vencio y requiere renovacion.', '#EF5350', '#EF5350', '#FFFFFF', '#2C2C2C', '#555555'),
            'transaction_expiring_soon' => $this->preset('payment', 'bi-hourglass-split', 'Paquete por vencer', 'Tu paquete vence pronto.', '#F57C00', '#F57C00', '#FFFFFF', '#2C2C2C', '#555555'),
            'reservation_changed' => $this->preset('update', 'bi-pencil-square', 'Reserva actualizada', 'Los datos de tu reserva fueron actualizados.', '#F57C00', '#F57C00', '#FFFFFF', '#2C2C2C', '#555555'),
            'reservation_confirmed' => $this->preset('reserva', 'bi-calendar-check', 'Reserva confirmada', 'Tu clase fue confirmada correctamente.', '#C17A6A', '#C17A6A', '#FFFFFF', '#2C2C2C', '#555555'),
            'reservation_cancelled' => $this->preset('cancel', 'bi-x-circle', 'Reserva cancelada', 'Tu reserva fue cancelada exitosamente.', '#EF5350', '#EF5350', '#FFFFFF', '#2C2C2C', '#555555'),
            'waiting_list_confirmed' => $this->preset('waitlist', 'bi-clock-history', 'Ingreso a lista de espera', 'Te avisaremos cuando se libere un lugar.', '#2E7D32', '#2E7D32', '#FFFFFF', '#2C2C2C', '#555555'),
            'waiting_list_denied' => $this->preset('waitlist', 'bi-exclamation-circle', 'No fue posible reservar', 'No pudimos asignarte un lugar disponible.', '#D32F2F', '#D32F2F', '#FFFFFF', '#2C2C2C', '#555555'),
            'waiting_list_removed' => $this->preset('waitlist', 'bi-clock-history', 'Salida de lista de espera', 'Tu registro en lista de espera fue retirado.', '#455A64', '#455A64', '#FFFFFF', '#2C2C2C', '#555555'),
            'waiting_list_promoted' => $this->preset('waitlist', 'bi-calendar-check', 'Lugar asignado', 'Se te asigno un lugar desde lista de espera.', '#2E7D32', '#2E7D32', '#FFFFFF', '#2C2C2C', '#555555'),
            'waiting_list_expired' => $this->preset('waitlist', 'bi-hourglass-split', 'Lista de espera expirada', 'Tu solicitud en lista de espera expiro.', '#607D8B', '#607D8B', '#FFFFFF', '#2C2C2C', '#555555'),
            'session_canceled' => $this->preset('cancel', 'bi-calendar-x', 'Clase cancelada', 'La clase fue cancelada y tu pase fue devuelto.', '#EF5350', '#EF5350', '#FFFFFF', '#2C2C2C', '#555555'),
            'session_reminder' => $this->preset('reminder', 'bi-alarm', 'Clase proxima', 'Tu clase empieza pronto.', '#1976D2', '#1976D2', '#FFFFFF', '#2C2C2C', '#555555'),
            'rating_pending' => $this->preset('update', 'bi-chat-dots', 'Calificacion pendiente', 'Tienes clases pendientes por calificar.', '#5E35B1', '#5E35B1', '#FFFFFF', '#2C2C2C', '#555555'),
            'birthday_greetings' => $this->preset('sistema', 'bi-stars', 'Feliz cumpleanos', 'Te deseamos un excelente dia.', '#EC407A', '#EC407A', '#FFFFFF', '#2C2C2C', '#555555'),
            'achievement_unlocked' => $this->preset('logro', 'bi-trophy', 'Logro desbloqueado', 'Desbloqueaste un nuevo logro.', '#7B1FA2', '#7B1FA2', '#FFFFFF', '#2C2C2C', '#555555'),
            'achievement_unlocked_special' => $this->preset('logro', 'bi-stars', 'Logro especial desbloqueado', 'Has desbloqueado un logro especial.', '#C9A227', '#C9A227', '#FFFFFF', '#2C2C2C', '#555555'),
            'sistema_general' => $this->preset('sistema', 'bi-info-circle', 'Notificacion del sistema', 'Hay una actualizacion importante para ti.', '#9E9E9E', '#9E9E9E', '#FFFFFF', '#2C2C2C', '#555555'),
            '_default' => $this->preset('sistema', 'bi-bell', 'Nueva notificacion', 'Tienes una notificacion nueva.', '#9E9E9E', '#9E9E9E', '#FFFFFF', '#2C2C2C', '#555555'),
        ];
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, array<string, string>>
     */
    public function mergeWithDefaults(array $stored): array
    {
        $defaults = $this->getDefaults();
        $normalizedStored = $this->normalizeStoredKeys($stored);

        foreach ($defaults as $type => $defaultConfig) {
            $candidate = $normalizedStored[$type] ?? [];
            if (!is_array($candidate)) {
                $candidate = [];
            }

            $defaults[$type] = [
                'toastClass' => $this->sanitizeToken((string) ($candidate['toastClass'] ?? $defaultConfig['toastClass']), $defaultConfig['toastClass']),
                'icon' => $this->sanitizeToken((string) ($candidate['icon'] ?? $defaultConfig['icon']), $defaultConfig['icon']),
                'previewTitle' => $this->sanitizeText((string) ($candidate['previewTitle'] ?? $defaultConfig['previewTitle']), $defaultConfig['previewTitle'], 60),
                'previewBody' => $this->sanitizeText((string) ($candidate['previewBody'] ?? $defaultConfig['previewBody']), $defaultConfig['previewBody'], 120),
                'barColor' => $this->sanitizeHexColor((string) ($candidate['barColor'] ?? $defaultConfig['barColor']), $defaultConfig['barColor']),
                'iconColor' => $this->sanitizeHexColor((string) ($candidate['iconColor'] ?? $defaultConfig['iconColor']), $defaultConfig['iconColor']),
                'backgroundColor' => $this->sanitizeHexColor((string) ($candidate['backgroundColor'] ?? $defaultConfig['backgroundColor']), $defaultConfig['backgroundColor']),
                'titleColor' => $this->sanitizeHexColor((string) ($candidate['titleColor'] ?? $defaultConfig['titleColor']), $defaultConfig['titleColor']),
                'textColor' => $this->sanitizeHexColor((string) ($candidate['textColor'] ?? $defaultConfig['textColor']), $defaultConfig['textColor']),
            ];
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function normalizeStoredKeys(array $stored): array
    {
        $normalized = $stored;

        foreach ($this->getLegacyTypeAliases() as $legacy => $current) {
            if (!array_key_exists($current, $normalized) && array_key_exists($legacy, $stored)) {
                $normalized[$current] = $stored[$legacy];
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function getLegacyTypeAliases(): array
    {
        return [
            'reserva_modificada' => 'reservation_changed',
            'reserva_confirmada' => 'reservation_confirmed',
            'reserva_cancelada' => 'reservation_cancelled',
            'lista_espera_entrada' => 'waiting_list_confirmed',
            'lista_espera_salida' => 'waiting_list_removed',
            'lista_espera_turno' => 'waiting_list_denied',
            'pago_confirmado' => 'payment_confirmed',
            'recordatorio_clase' => 'session_reminder',
            'recordatorio_pago' => 'transaction_expiring_soon',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getIconOptions(): array
    {
        return [
            'bi-bell' => 'Campana',
            'bi-bell-fill' => 'Campana llena',
            'bi-info-circle' => 'Informacion',
            'bi-exclamation-circle' => 'Alerta',
            'bi-check-circle' => 'Confirmacion',
            'bi-x-circle' => 'Cancelacion',
            'bi-calendar-check' => 'Reserva confirmada',
            'bi-calendar-plus' => 'Nueva reserva',
            'bi-calendar-event' => 'Cambio de horario',
            'bi-pencil-square' => 'Edicion',
            'bi-clock-history' => 'Lista de espera',
            'bi-alarm' => 'Recordatorio',
            'bi-hourglass-split' => 'Pendiente',
            'bi-credit-card' => 'Pago',
            'bi-credit-card-2-back' => 'Pago fallido',
            'bi-person-gear' => 'Cuenta',
            'bi-person-check' => 'Usuario validado',
            'bi-person-workspace' => 'Sesion privada',
            'bi-chat-dots' => 'Mensaje',
            'bi-headset' => 'Soporte',
            'bi-megaphone' => 'Anuncio',
            'bi-geo-alt' => 'Ubicacion',
            'bi-stars' => 'Especial',
            'bi-trophy' => 'Logro',
            'bi-award' => 'Premio',
            'bi-fire' => 'Prioridad alta',
            'bi-lightning-charge' => 'Urgente',
            'bi-heart-pulse' => 'Salud',
            'bi-wallet2' => 'Pago/cartera',
            'bi-shield-check' => 'Seguridad',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function preset(
        string $toastClass,
        string $icon,
        string $previewTitle,
        string $previewBody,
        string $barColor,
        string $iconColor,
        string $backgroundColor,
        string $titleColor,
        string $textColor,
    ): array {
        return [
            'toastClass' => $toastClass,
            'icon' => $icon,
            'previewTitle' => $previewTitle,
            'previewBody' => $previewBody,
            'barColor' => $barColor,
            'iconColor' => $iconColor,
            'backgroundColor' => $backgroundColor,
            'titleColor' => $titleColor,
            'textColor' => $textColor,
        ];
    }

    private function sanitizeHexColor(string $value, string $fallback): string
    {
        $v = strtoupper(trim($value));

        if (preg_match('/^#[0-9A-F]{6}$/', $v) === 1) {
            return $v;
        }

        return $fallback;
    }

    private function sanitizeToken(string $value, string $fallback): string
    {
        $v = trim($value);
        if ($v !== '' && preg_match('/^[a-zA-Z0-9\-_]+$/', $v) === 1) {
            return $v;
        }

        return $fallback;
    }

    private function sanitizeText(string $value, string $fallback, int $maxLength): string
    {
        $v = trim($value);
        if ($v === '') {
            return $fallback;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($v) > $maxLength) {
                return mb_substr($v, 0, $maxLength);
            }

            return $v;
        }

        if (strlen($v) > $maxLength) {
            return substr($v, 0, $maxLength);
        }

        return $v;
    }
}
