<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Achievement;
use App\Form\Backend\AchievementType;
use App\Repository\AchievementBadgeRepository;
use App\Repository\AchievementConditionCatalogRepository;
use App\Repository\AchievementRepository;
use App\Repository\DisciplineRepository;
use App\Repository\PackageRepository;
use App\Repository\StaffRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/backend/achievement')]
class AchievementController extends AbstractController
{
    #[Route('/', name: 'backend_achievement', methods: ['GET'])]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        AchievementRepository $achievementRepository,
        AchievementConditionCatalogRepository $conditionCatalogRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $search = trim($request->query->getString('q', ''));
        $pagination = $paginator->paginate(
            $achievementRepository->paginate($request->query->has('sort'), $search !== '' ? $search : null),
            $page,
            20,
        );

        // Mapa conditionKey → conditionLabel para mostrar etiquetas legibles
        $conditionLabels = [];
        foreach ($conditionCatalogRepository->findAll() as $catalog) {
            $conditionLabels[$catalog->getConditionKey()] = $catalog->getConditionLabel();
        }

        return $this->render('backend/achievement/index.html.twig', [
            'pagination'         => $pagination,
            'searchQuery'        => $search,
            'conditionLabels'    => $conditionLabels,
            'categoryLabels'     => Achievement::categoryChoices(),
            'rewardTypeLabels'   => Achievement::rewardTypeChoices(),
            'badgeLevelLabels'   => Achievement::badgeLevelChoices(),
            'thresholdTypeLabels'=> Achievement::thresholdTypeChoices(),
        ]);
    }

    #[Route('/{id}/show', name: 'backend_achievement_show', methods: ['GET'])]
    public function show(
        Achievement $achievement,
        Request $request,
        UserRepository $userRepository,
        AchievementConditionCatalogRepository $conditionRepo,
        PaginatorInterface $paginator,
        PackageRepository $packageRepository,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $allUsers = $userRepository->findByEarnedAchievementId((int) $achievement->getId());

        // Extraer la fecha en que cada usuario ganó este logro
        $earnedAtMap = [];
        foreach ($allUsers as $user) {
            foreach ($user->getEarnedAchievements() as $entry) {
                if ((int) ($entry['achievementId'] ?? 0) === (int) $achievement->getId()) {
                    $earnedAtMap[$user->getId()] = $entry['earnedAt'] ?? null;
                    break;
                }
            }
        }

        $pagination = $paginator->paginate($allUsers, $page, 20);

        // Labels de condiciones desde el catálogo
        $conditionLabels = [];
        foreach ($conditionRepo->findAll() as $cond) {
            $conditionLabels[$cond->getConditionKey()] = $cond->getConditionLabel();
        }

        // Mapa packageId → label para mostrar en el detalle cuando aplica
        $packageLabels = [];
        $ctx = $achievement->getConditionContext();
        if (!empty($ctx['packageIds'])) {
            foreach ($packageRepository->getAllActive() as $pkg) {
                $classes = $pkg->isIsUnlimited() ? 'Ilimitado' : (($pkg->getTotalClasses() ?? '?') . ' clases');
                $packageLabels[$pkg->getId()] = trim($classes . ($pkg->getAltText() ? ' – ' . $pkg->getAltText() : ''));
            }
        }

        // Mapa disciplineId → nombre para mostrar en el detalle cuando aplica
        $disciplineLabels = [];
        if (!empty($ctx['disciplineIds'])) {
            foreach ($disciplineRepository->getAllActives() as $disc) {
                $disciplineLabels[$disc->getId()] = $disc->getName();
            }
        }

        // Mapa instructorId → nombre completo para mostrar en el detalle cuando aplica
        $instructorLabels = [];
        if (!empty($ctx['instructorIds'])) {
            foreach ($staffRepository->getAllActiveInstructors() as $staff) {
                $instructorLabels[$staff->getId()] = trim(sprintf('%s %s',
                    $staff->getProfile()?->getFirstname(),
                    $staff->getProfile()?->getPaternalSurname()
                )) ?: (string) $staff;
            }
        }

        return $this->render('backend/achievement/show.html.twig', [
            'achievement'        => $achievement,
            'pagination'         => $pagination,
            'totalUsers'         => count($allUsers),
            'earnedAtMap'        => $earnedAtMap,
            'categoryLabels'     => Achievement::categoryChoices(),
            'conditionLabels'    => $conditionLabels,
            'badgeLevelLabels'   => Achievement::badgeLevelChoices(),
            'thresholdTypeLabels'=> Achievement::thresholdTypeChoices(),
            'comparisonLabels'   => Achievement::comparisonOperatorChoices(),
            'packageLabels'      => $packageLabels,
            'disciplineLabels'   => $disciplineLabels,
            'instructorLabels'   => $instructorLabels,
        ]);
    }

    #[Route('/new', name: 'backend_achievement_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        AchievementConditionCatalogRepository $conditionRepo,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        PackageRepository $packageRepository,
        AchievementBadgeRepository $badgeRepository,
    ): Response
    {
        $achievement = new Achievement();
        $form = $this->createForm(AchievementType::class, $achievement);

        // El wizard envía un POST con campos raw — procesarlo manualmente sin pasar por el form
        if ($request->isMethod('POST') && $request->request->has('achievement')) {
            $payload        = $request->request->all('achievement');
            $validBadgeKeys = array_keys($badgeRepository->findAllActiveIndexed());
            $error          = $this->hydrateAchievementFromWizard($achievement, $payload, $validBadgeKeys);

            if ($error === null) {
                $achievement->setActive(true);
                $em->persist($achievement);
                $em->flush();
                $this->addFlash('success', 'El logro ha sido creado.');

                return $this->redirectToRoute('backend_achievement_show', [
                    'id' => $achievement->getId(),
                ]);
            }

            $this->addFlash('error', $error);
        }

        // Para el wizard, obtener catálogos de condiciones en estructura JSON-safe
        $conditions = $conditionRepo->findBy(['active' => true], ['sortOrder' => 'ASC', 'categoryKey' => 'ASC']);
        $conditionsByCategory = [];
        foreach ($conditions as $cond) {
            $cat = $cond->getCategoryKey();
            if (!isset($conditionsByCategory[$cat])) {
                $conditionsByCategory[$cat] = [];
            }

            $thresholdOptions = [];
            foreach ($cond->getThresholdOptions() as $option) {
                if (!$option->isActive()) {
                    continue;
                }

                $thresholdOptions[] = [
                    'value' => $option->getOptionValue(),
                    'label' => $option->getOptionLabel(),
                ];
            }

            $conditionsByCategory[$cat][] = [
                'conditionKey' => $cond->getConditionKey(),
                'conditionLabel' => $cond->getConditionLabel(),
                'thresholdType' => $cond->getThresholdType(),
                'allowsCustomValue' => $cond->allowsCustomValue(),
                'minValue' => $cond->getMinValue(),
                'maxValue' => $cond->getMaxValue(),
                'thresholdOptions' => $thresholdOptions,
            ];
        }

        $activeDisciplines = array_map(
            static fn($discipline): array => [
                'id' => $discipline->getId(),
                'name' => $discipline->getName(),
            ],
            $disciplineRepository->getAllActives()
        );

        $activeInstructors = array_map(
            static fn($staff): array => [
                'id' => $staff->getId(),
                'name' => trim(sprintf('%s %s', $staff->getProfile()?->getFirstname(), $staff->getProfile()?->getPaternalSurname())) ?: (string) $staff,
            ],
            $staffRepository->getAllActiveInstructors()
        );

        $activePackages = array_map(
            static function ($pkg): array {
                $classes = $pkg->isIsUnlimited() ? 'Ilimitado' : (($pkg->getTotalClasses() ?? '?') . ' clases');
                $label   = trim($classes . ($pkg->getAltText() ? ' – ' . $pkg->getAltText() : ''));
                return ['id' => $pkg->getId(), 'name' => $label, 'type' => $pkg->getType()];
            },
            $packageRepository->getAllActive()
        );

        return $this->render('backend/achievement/new_wizard.html.twig', [
            'achievement'          => $achievement,
            'form'                 => $form,
            'conditionsByCategory' => $conditionsByCategory,
            'activeDisciplines'    => $activeDisciplines,
            'activeInstructors'    => $activeInstructors,
            'activePackages'       => $activePackages,
            'badgesData'           => $this->buildBadgesData($badgeRepository),
            'isEditMode'           => false,
            'preloadData'          => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_achievement_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Achievement $achievement,
        EntityManagerInterface $em,
        AchievementConditionCatalogRepository $conditionRepo,
        DisciplineRepository $disciplineRepository,
        StaffRepository $staffRepository,
        PackageRepository $packageRepository,
        AchievementBadgeRepository $badgeRepository,
    ): Response {
        $form = $this->createForm(AchievementType::class, $achievement);

        // El wizard envía un POST con campos raw — procesarlo igual que en new()
        if ($request->isMethod('POST') && $request->request->has('achievement')) {
            $payload = $request->request->all('achievement');

            $validBadgeKeys = array_keys($badgeRepository->findAllActiveIndexed());
            $error = $this->hydrateAchievementFromWizard($achievement, $payload, $validBadgeKeys);

            if ($error === null) {
                $em->flush();
                $this->addFlash('success', 'El logro ha sido actualizado.');

                return $this->redirectToRoute('backend_achievement_show', [
                    'id' => $achievement->getId(),
                ]);
            }

            $this->addFlash('error', $error);
        }

        // Catálogos para el wizard (igual que en new())
        $conditions = $conditionRepo->findBy(['active' => true], ['sortOrder' => 'ASC', 'categoryKey' => 'ASC']);
        $conditionsByCategory = [];
        foreach ($conditions as $cond) {
            $cat = $cond->getCategoryKey();
            if (!isset($conditionsByCategory[$cat])) {
                $conditionsByCategory[$cat] = [];
            }
            $thresholdOptions = [];
            foreach ($cond->getThresholdOptions() as $option) {
                if (!$option->isActive()) {
                    continue;
                }
                $thresholdOptions[] = [
                    'value' => $option->getOptionValue(),
                    'label' => $option->getOptionLabel(),
                ];
            }
            $conditionsByCategory[$cat][] = [
                'conditionKey'    => $cond->getConditionKey(),
                'conditionLabel'  => $cond->getConditionLabel(),
                'thresholdType'   => $cond->getThresholdType(),
                'allowsCustomValue' => $cond->allowsCustomValue(),
                'minValue'        => $cond->getMinValue(),
                'maxValue'        => $cond->getMaxValue(),
                'thresholdOptions' => $thresholdOptions,
            ];
        }

        $activeDisciplines = array_map(
            static fn($discipline): array => [
                'id'   => $discipline->getId(),
                'name' => $discipline->getName(),
            ],
            $disciplineRepository->getAllActives()
        );

        $activeInstructors = array_map(
            static fn($staff): array => [
                'id'   => $staff->getId(),
                'name' => trim(sprintf('%s %s', $staff->getProfile()?->getFirstname(), $staff->getProfile()?->getPaternalSurname())) ?: (string) $staff,
            ],
            $staffRepository->getAllActiveInstructors()
        );

        // Datos pre-cargados para el wizard en modo edición
        $contextData = $achievement->getConditionContext() ?? [];

        $periodType = $achievement->getPeriodType() ?? 'none';
        $periodDays = $achievement->getPeriodDays();
        $periodWizardKey = 'sin-limite';
        $conditionKey = $achievement->getConditionKey() ?? '';
        if ($periodType === 'days') {
            if ($periodDays === 1)       $periodWizardKey = '1-dia';
            elseif ($periodDays === 7)   $periodWizardKey = '7-dias';
            elseif ($periodDays === 30)  $periodWizardKey = '30-dias';
            elseif ($conditionKey === 'weekly_frequency') $periodWizardKey = 'custom-semanas';
            else                         $periodWizardKey = 'custom-dias';
        } elseif ($periodType === 'deadline') {
            $periodWizardKey = 'fecha-limite';
        } elseif ($periodType === 'window') {
            $periodWizardKey = 'ventana-fija';
        }

        $preloadData = [
            'cat'            => $achievement->getCategoryKey() ?? '',
            'cond'           => $achievement->getConditionKey() ?? '',
            'threshold'      => (float) ($achievement->getTargetValue() ?? 0),
            'badge'          => $achievement->getBadgeLevel() ?? '',
            'customIcon'     => $achievement->getBadgeIcon() ?? '',
            'customLabel'    => $achievement->getBadgeLabel() ?? '',
            'customPts'      => $achievement->getRewardValue() !== null ? (string) $achievement->getRewardValue() : '',
            'name'           => $achievement->getName() ?? '',
            'desc'           => $achievement->getDescription() ?? '',
            'period'         => $periodWizardKey,
            'periodDays'        => $periodWizardKey === 'custom-semanas' ? (int) round(($periodDays ?? 0) / 7) : $periodDays,
            'periodDeadline'    => $achievement->getPeriodDeadline()?->format('Y-m-d'),
            'periodWindowStart' => $achievement->getPeriodWindowStart()?->format('Y-m-d'),
            'disciplineIds'            => $contextData['disciplineIds'] ?? [],
            'disciplineAttendances'       => $contextData['attendancesRequired'] ?? null,
            'disciplineAttendancesScope'  => $contextData['attendancesScope'] ?? 'total',
            'instructorIds'  => $contextData['instructorIds'] ?? [],
            'timeSlotIds'    => $contextData['timeSlotIds'] ?? [],
            'packageIds'     => $contextData['packageIds'] ?? [],
            'difficulty'     => $achievement->getDifficulty(),
            'active'         => $achievement->isActive(),
            'opts'           => [
                'notif'      => $achievement->isNotifyInApp(),
                'visible'    => $achievement->isVisibleProfile(),
                'celebrar'   => $achievement->isNotifySpecial(),
                'progreso'      => $achievement->isShowProgress(),
                'retroactivo'   => $achievement->isIncludeHistoricalData(),
                'destacar'   => false,
            ],
        ];

        $activePackages = array_map(
            static function ($pkg): array {
                $classes = $pkg->isIsUnlimited() ? 'Ilimitado' : (($pkg->getTotalClasses() ?? '?') . ' clases');
                $label   = trim($classes . ($pkg->getAltText() ? ' – ' . $pkg->getAltText() : ''));
                return ['id' => $pkg->getId(), 'name' => $label, 'type' => $pkg->getType()];
            },
            $packageRepository->getAllActive()
        );

        return $this->render('backend/achievement/new_wizard.html.twig', [
            'achievement'          => $achievement,
            'form'                 => $form,
            'conditionsByCategory' => $conditionsByCategory,
            'activeDisciplines'    => $activeDisciplines,
            'activeInstructors'    => $activeInstructors,
            'activePackages'       => $activePackages,
            'badgesData'           => $this->buildBadgesData($badgeRepository),
            'isEditMode'           => true,
            'preloadData'          => $preloadData,
        ]);
    }
    #[Route('/{id}/delete', name: 'backend_achievement_delete', methods: ['POST'])]
    public function delete(Request $request, Achievement $achievement, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_achievement_'.$achievement->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad invalido.');

            return $this->redirectToRoute('backend_achievement_edit', [
                'id' => $achievement->getId(),
            ]);
        }

        $em->remove($achievement);
        $em->flush();

        $this->addFlash('success', 'El logro ha sido eliminado.');

        return $this->redirectToRoute('backend_achievement');
    }

    /**
     * Hidrata una entidad Achievement desde el payload raw del wizard.
     * Devuelve null si todo OK, o un string con el mensaje de error.
     *
     * @param string[] $validBadgeKeys  Claves válidas cargadas desde la DB.
     *                                  Si está vacío, se usa el listado estático como fallback.
     */
    private function hydrateAchievementFromWizard(Achievement $a, array $p, array $validBadgeKeys = []): ?string
    {
        // Campos requeridos
        $name = trim($p['name'] ?? '');
        if ($name === '') {
            return 'El nombre del logro es requerido.';
        }

        $categoryKey  = $p['categoryKey']  ?? '';
        $conditionKey = $p['conditionKey'] ?? '';
        $targetValue  = $p['targetValue']  ?? '';
        $badgeLevel   = $p['badgeLevel']   ?? '';
        $periodType   = $p['periodType']   ?? 'none';
        $thresholdType = $p['thresholdType'] ?? 'count';

        $validCategories  = array_keys(Achievement::categoryChoices());
        $validThresholds  = array_keys(Achievement::thresholdTypeChoices());
        // Preferir claves de DB; si no hay, usar fallback estático
        $validBadgeLevels = $validBadgeKeys !== [] ? $validBadgeKeys : array_keys(Achievement::badgeLevelChoices());
        $validPeriodTypes = array_keys(Achievement::periodTypeChoices());

        if (!in_array($categoryKey, $validCategories, true))   return 'Categoría no válida.';
        if (!in_array($thresholdType, $validThresholds, true)) return 'Tipo de umbral no válido.';
        if (!in_array($badgeLevel, $validBadgeLevels, true))   return 'Nivel de badge no válido.';
        if (!in_array($periodType, $validPeriodTypes, true))   return 'Tipo de período no válido.';
        if ($targetValue === '' || !is_numeric($targetValue)) return 'El valor objetivo es requerido.';

        $a->setName($name);
        $a->setDescription($p['description'] ?? null ?: null);
        $a->setCategoryKey($categoryKey);
        $a->setConditionKey($conditionKey);
        $a->setThresholdType($thresholdType);
        $a->setTargetValue($targetValue);
        $a->setComparisonOperator(Achievement::COMPARISON_OPERATOR_GTE);
        $a->setRewardType(Achievement::REWARD_TYPE_BADGE);
        $a->setBadgeLevel($badgeLevel);
        $a->setBadgeColor(Achievement::resolveBadgeHexColor($badgeLevel));

        // Icono y puntos personalizados (vacío = usar defecto del nivel)
        $customIcon = trim($p['badgeIcon'] ?? '');
        $a->setBadgeIcon($customIcon !== '' ? $customIcon : null);
        $customLabel = trim($p['badgeLabel'] ?? '');
        $a->setBadgeLabel($customLabel !== '' ? $customLabel : null);
        $customPts = $p['rewardValue'] ?? '';
        $a->setRewardValue($customPts !== '' && is_numeric($customPts) ? $customPts : null);
        $a->setPeriodType($periodType);
        $a->setSortOrder((int) ($p['sortOrder'] ?? 100));

        // Booleanos (presencia = true, ausencia = false)
        $a->setNotifyInApp(isset($p['notifyInApp']));
        $a->setVisibleProfile(isset($p['visibleProfile']));
        $a->setNotifySpecial(isset($p['notifySpecial']));
        $a->setShowProgress(isset($p['showProgress']));
        $a->setIncludeHistoricalData(isset($p['includeHistoricalData']));
        // Active: siempre enviado como valor '1' o '0'
        $a->setActive(($p['active'] ?? '1') === '1');

        // Período
        if ($periodType === Achievement::PERIOD_TYPE_DAYS) {
            $days = (int) ($p['periodDays'] ?? 0);
            $a->setPeriodDays($days > 0 ? $days : null);
            $a->setPeriodDeadline(null);
            $a->setPeriodWindowStart(null);
        } elseif ($periodType === Achievement::PERIOD_TYPE_DEADLINE) {
            $a->setPeriodDays(null);
            $a->setPeriodWindowStart(null);
            $raw = $p['periodDeadline'] ?? '';
            if ($raw !== '') {
                $date = \DateTime::createFromFormat('d/m/Y', $raw);
                $a->setPeriodDeadline($date ?: null);
            } else {
                $a->setPeriodDeadline(null);
            }
        } elseif ($periodType === Achievement::PERIOD_TYPE_WINDOW) {
            $a->setPeriodDays(null);
            $rawStart = $p['periodWindowStart'] ?? '';
            $rawEnd   = $p['periodDeadline']    ?? '';
            $a->setPeriodWindowStart($rawStart !== '' ? (\DateTime::createFromFormat('d/m/Y', $rawStart) ?: null) : null);
            $a->setPeriodDeadline($rawEnd   !== '' ? (\DateTime::createFromFormat('d/m/Y', $rawEnd)   ?: null) : null);
        } else {
            // PERIOD_TYPE_NONE o cualquier valor desconocido
            $a->setPeriodDays(null);
            $a->setPeriodDeadline(null);
            $a->setPeriodWindowStart(null);
        }

        // Contexto (JSON de disciplinas / instructores / horarios)
        $contextRaw = $p['conditionContext'] ?? '';
        $a->setConditionContext($contextRaw !== '' ? $contextRaw : null);

        // Dificultad (reto)
        $difficulty = $p['difficulty'] ?? '';
        $validDifficulties = array_keys(Achievement::DIFFICULTIES);
        $a->setDifficulty(in_array($difficulty, $validDifficulties, true) ? $difficulty : null);

        return null;
    }

    /**
     * Construye el array de datos de badges desde la DB para pasarlo al wizard.
     *
     * Devuelve:
     *   'groups'  → estructura para renderBadges() en JS
     *   'icons'   → {key: icon}          para badgeIcons en JS
     *   'labels'  → {key: 'icon name'}   para badgeLabels en JS
     *   'pts'     → {key: pts}           para badgePts en JS
     */
    private function buildBadgesData(AchievementBadgeRepository $repo): array
    {
        $grouped = $repo->findAllActiveGrouped();

        $groups = [];
        $icons  = [];
        $labels = [];
        $pts    = [];

        foreach ($grouped as $groupLabel => $badges) {
            $badgeItems = [];
            foreach ($badges as $badge) {
                $key = $badge->getBadgeKey();
                $icons[$key]  = $badge->getIcon();
                $labels[$key] = $badge->getIcon() . ' ' . $badge->getName();
                $pts[$key]    = $badge->getDefaultPts();
                $badgeItems[] = [
                    'key'   => $key,
                    'icon'  => $badge->getIcon(),
                    'name'  => $badge->getName(),
                    'pts'   => $badge->getDefaultPts(),
                    'color' => $badge->getColor(),
                ];
            }
            $groups[] = ['label' => $groupLabel, 'badges' => $badgeItems];
        }

        return [
            'groups' => $groups,
            'icons'  => $icons,
            'labels' => $labels,
            'pts'    => $pts,
        ];
    }
}
