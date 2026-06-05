<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;
use App\Service\NotificationThemeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/backend/settings/notificaciones-tema', name: 'backend_notification_theme', methods: ['GET', 'POST'])]
class NotificationThemeController extends AbstractController
{
    private const MODULE = 'notification_theme';

    public function __construct(
        private readonly ConfigurationRepository $configurationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationThemeService $notificationThemeService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $current = $this->configurationRepository->findOneBy(['module' => self::MODULE]);
        $stored = $current?->getData();
        $storedTypes = is_array($stored['types'] ?? null) ? $stored['types'] : [];
        $types = $this->notificationThemeService->mergeWithDefaults($storedTypes);

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $current, $types);
        }

        $selectedType = (string) $request->query->get('type', 'reserva_confirmada');
        if (!isset($types[$selectedType])) {
            $selectedType = array_key_first($types) ?: 'reserva_confirmada';
        }

        return $this->render('backend/notification_theme/index.html.twig', [
            'types' => $types,
            'selectedType' => $selectedType,
            'iconOptions' => $this->notificationThemeService->getIconOptions(),
        ]);
    }

    private const VALID_TOAST_CLASSES = ['sistema', 'payment', 'cancel', 'update', 'reserva', 'waitlist', 'reminder', 'logro'];

    private const TEXT_MAX_LENGTHS = [
        'previewTitle' => 60,
        'previewBody' => 120,
    ];

    private const COLOR_FIELDS = ['barColor', 'iconColor', 'backgroundColor', 'titleColor', 'textColor'];

    /**
     * @param array<string, array<string, string>> $currentTypes
     */
    private function handlePost(Request $request, ?Configuration $current, array $currentTypes): Response
    {
        $type = trim((string) $request->request->get('type', ''));
        if ($type === '' || !isset($currentTypes[$type])) {
            $this->addFlash('danger', 'Tipo de notificacion invalido.');

            return $this->redirectToRoute('backend_notification_theme');
        }

        // Validar icono contra la lista blanca del servicio
        $icon = trim((string) $request->request->get('icon', ''));
        $validIcons = array_keys($this->notificationThemeService->getIconOptions());
        if ($icon === '' || !in_array($icon, $validIcons, true)) {
            $this->addFlash('danger', 'Icono no valido.');
            return $this->redirectToRoute('backend_notification_theme', ['type' => $type]);
        }

        // Validar colores hex
        foreach (self::COLOR_FIELDS as $field) {
            $color = strtoupper(trim((string) $request->request->get($field, '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
                $this->addFlash('danger', sprintf('Color invalido en campo "%s".', $field));
                return $this->redirectToRoute('backend_notification_theme', ['type' => $type]);
            }
        }

        // Validar campos de texto (longitud maxima y sin etiquetas HTML)
        foreach (self::TEXT_MAX_LENGTHS as $field => $maxLen) {
            $value = trim((string) $request->request->get($field, ''));
            if (mb_strlen($value) > $maxLen) {
                $this->addFlash('danger', sprintf('El campo "%s" excede %d caracteres.', $field, $maxLen));
                return $this->redirectToRoute('backend_notification_theme', ['type' => $type]);
            }
            // Rechazar etiquetas HTML/JS
            if (preg_match('/<[^>]*>/', $value)) {
                $this->addFlash('danger', sprintf('El campo "%s" contiene contenido no permitido.', $field));
                return $this->redirectToRoute('backend_notification_theme', ['type' => $type]);
            }
        }

        $payload = [
            'toastClass' => (string) ($currentTypes[$type]['toastClass'] ?? 'sistema'),
            'icon' => $icon,
            'previewTitle' => mb_substr(trim((string) $request->request->get('previewTitle', '')), 0, 60),
            'previewBody' => mb_substr(trim((string) $request->request->get('previewBody', '')), 0, 120),
            'barColor' => strtoupper(trim((string) $request->request->get('barColor', ''))),
            'iconColor' => strtoupper(trim((string) $request->request->get('iconColor', ''))),
            'backgroundColor' => strtoupper(trim((string) $request->request->get('backgroundColor', ''))),
            'titleColor' => strtoupper(trim((string) $request->request->get('titleColor', ''))),
            'textColor' => strtoupper(trim((string) $request->request->get('textColor', ''))),
        ];

        $nextTypes = $currentTypes;
        $nextTypes[$type] = $payload;
        $nextTypes = $this->notificationThemeService->mergeWithDefaults($nextTypes);

        if (!$current) {
            $current = new Configuration();
            $current->setModule(self::MODULE);
            $this->entityManager->persist($current);
        }

        $current->setData(['types' => $nextTypes]);
        $this->entityManager->flush();

        $this->addFlash('success', 'Tema de notificaciones actualizado.');

        return $this->redirectToRoute('backend_notification_theme', ['type' => $type]);
    }
}
