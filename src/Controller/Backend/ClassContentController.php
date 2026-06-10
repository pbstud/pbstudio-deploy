<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Post;
use App\Repository\DisciplineRepository;
use App\Repository\PostRepository;
use App\Service\ClassContentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/clases-contenido', name: 'backend_class_content', methods: ['GET', 'POST'])]
class ClassContentController extends AbstractController
{
    private const INFOBOX_ICONS = [
        // Personas / comunidad
        'bi-person-check'           => 'Persona con check',
        'bi-person-arms-up'         => 'Persona activa',
        'bi-person-walking'         => 'Persona caminando',
        'bi-person-raised-hand'     => 'Persona levantando mano',
        'bi-people'                 => 'Grupo / comunidad',
        'bi-people-fill'            => 'Grupo sólido',
        'bi-person-workspace'       => 'Sesión privada',
        'bi-person-badge'           => 'Instructor / certificado',
        'bi-person-heart'           => 'Persona con corazón',
        // Fitness / salud
        'bi-heart-pulse'            => 'Salud / pulso',
        'bi-heart-pulse-fill'       => 'Pulso sólido',
        'bi-activity'               => 'Actividad / ritmo',
        'bi-bicycle'                => 'Bicicleta',
        'bi-lungs'                  => 'Respiración',
        'bi-lungs-fill'             => 'Pulmones sólido',
        'bi-bandaid'                => 'Lesión / cuidado',
        'bi-capsule'                => 'Suplemento',
        'bi-clipboard-heart'        => 'Plan de salud',
        'bi-droplet'                => 'Hidratación',
        'bi-fire'                   => 'Quema de calorías',
        'bi-lightning'              => 'Energía / intensidad',
        'bi-lightning-charge'       => 'Alta intensidad',
        'bi-wind'                   => 'Cardio / aire libre',
        'bi-stopwatch'              => 'Cronómetro',
        'bi-trophy'                 => 'Logro / resultado',
        'bi-trophy-fill'            => 'Logro sólido',
        'bi-award'                  => 'Premio',
        'bi-award-fill'             => 'Premio sólido',
        // Clases / horarios
        'bi-calendar-check'         => 'Calendario',
        'bi-calendar-week'          => 'Semana / horario',
        'bi-calendar-event'         => 'Evento / clase',
        'bi-calendar2-heart'        => 'Calendario corazón',
        'bi-clock'                  => 'Reloj / horario',
        'bi-clock-fill'             => 'Reloj sólido',
        'bi-alarm'                  => 'Alarma',
        'bi-repeat'                 => 'Clases recurrentes',
        // Estudio / instalaciones
        'bi-house'                  => 'Sucursal / estudio',
        'bi-house-heart'            => 'Estudio favorito',
        'bi-building'               => 'Edificio',
        'bi-geo-alt'                => 'Ubicación',
        'bi-geo-alt-fill'           => 'Ubicación sólida',
        'bi-pin-map'                => 'Mapa / pin',
        'bi-door-open'              => 'Acceso / entrada',
        'bi-door-closed'            => 'Acceso cerrado',
        // Ropa / equipo
        'bi-bag'                    => 'Bolsa / ropa',
        'bi-bag-heart'              => 'Bolsa favorita',
        'bi-box2-heart'             => 'Kit de inicio',
        'bi-tools'                  => 'Equipamiento',
        'bi-music-note-beamed'      => 'Música / ambiente',
        'bi-water'                  => 'Piscina / agua',
        // Precios / pagos
        'bi-cash-coin'              => 'Precios / pago',
        'bi-cash-stack'             => 'Paquetes / tarifas',
        'bi-credit-card'            => 'Tarjeta de crédito',
        'bi-wallet2'                => 'Cartera / saldo',
        'bi-gift'                   => 'Regalo / promo',
        'bi-tag'                    => 'Etiqueta / precio',
        'bi-percent'                => 'Descuento',
        // Comunicación / soporte
        'bi-chat-dots'              => 'Chat / contacto',
        'bi-chat-heart'             => 'Chat amigable',
        'bi-envelope'               => 'Email',
        'bi-envelope-heart'         => 'Email personalizado',
        'bi-phone'                  => 'Teléfono / reserva',
        'bi-whatsapp'               => 'WhatsApp',
        'bi-headset'                => 'Soporte / atención',
        'bi-megaphone'              => 'Anuncio / novedad',
        // Información / ayuda
        'bi-question-circle'        => 'Pregunta',
        'bi-question-circle-fill'   => 'Pregunta sólida',
        'bi-info-circle'            => 'Información',
        'bi-info-circle-fill'       => 'Información sólida',
        'bi-lightbulb'              => 'Consejo / tip',
        'bi-lightbulb-fill'         => 'Consejo sólido',
        'bi-book'                   => 'Guía / manual',
        'bi-journals'               => 'Contenido / blog',
        'bi-patch-check'            => 'Verificado',
        'bi-patch-check-fill'       => 'Verificado sólido',
        // Seguridad / confianza
        'bi-shield-check'           => 'Seguridad',
        'bi-shield-fill-check'      => 'Seguridad sólida',
        'bi-lock'                   => 'Privacidad',
        'bi-key'                    => 'Acceso / membresía',
        // Bienestar / estado de ánimo
        'bi-emoji-smile'            => 'Bienestar',
        'bi-emoji-heart-eyes'       => 'Encantado',
        'bi-emoji-laughing'         => 'Diversión',
        'bi-emoji-sunglasses'       => 'Actitud',
        'bi-heart'                  => 'Corazón',
        'bi-heart-fill'             => 'Corazón sólido',
        'bi-stars'                  => 'Estrellas',
        'bi-star'                   => 'Estrella',
        'bi-star-fill'              => 'Estrella sólida',
        'bi-sun'                    => 'Energía / mañana',
        'bi-moon-stars'             => 'Sesión nocturna',
        // Nutrición / bienestar
        'bi-egg-fried'              => 'Nutrición',
        'bi-cup-hot'                => 'Nutrición caliente',
        'bi-apple'                  => 'Alimentación sana',
        // Contenido digital / app
        'bi-camera-video'           => 'Clases en video',
        'bi-play-circle'            => 'Reproducir / contenido',
        'bi-wifi'                   => 'Acceso online',
        'bi-broadcast'              => 'Streaming / en vivo',
        'bi-phone-vibrate'          => 'App / notificaciones',
        'bi-qr-code'                => 'QR / check-in',
    ];

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassContentService $classContentService,
        private readonly DisciplineRepository $disciplineRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $post       = $this->postRepository->findOneBy(['slug' => ClassContentService::POST_SLUG]);
        $disciplines = $this->disciplineRepository->getAllActives();

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $post, $disciplines);
        }

        $editorData = $this->classContentService->loadEditorPayload();
        $editorData = $this->normalizeEditorPayload($editorData, $disciplines);

        return $this->render('backend/class_content/edit.html.twig', [
            'data'         => $editorData,
            'disciplines'  => $disciplines,
            'post'         => $post,
            'infoBoxIcons' => self::INFOBOX_ICONS,
        ]);
    }

    /**
     * @param array<int, \App\Entity\Discipline> $disciplines
     */
    private function handlePost(Request $request, ?Post $post, array $disciplines): Response
    {
        $raw       = $request->request->all();
        $filesRaw  = $request->files->all()['classes'] ?? [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/media/uploads/class-panels';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $data = [
            'schemaVersion' => 2,
            'header'        => [
                'title'    => trim((string) ($raw['header']['title']    ?? '')),
                'subtitle' => trim((string) ($raw['header']['subtitle'] ?? '')),
            ],
            'infoBox1' => $this->parseInfoBox($raw['infoBox1'] ?? []),
            'infoBox2' => $this->parseInfoBox($raw['infoBox2'] ?? []),
            'classes'  => [],
        ];

        foreach ($disciplines as $discipline) {
            $slug     = self::disciplineSlug((string) $discipline);
            $classRaw = is_array($raw['classes'][$slug] ?? null) ? $raw['classes'][$slug] : [];
            $entry    = [];

            // ── Scalar fields ────────────────────────────────────────────────
            foreach ([
                'queEs', 'paraQuienEs', 'duration',
                'queEsIcon', 'queEsLabel',
                'benefitsIcon', 'benefitsLabel',
                'paraQuienEsIcon', 'paraQuienEsLabel',
                'durationIcon', 'durationLabel',
            ] as $field) {
                $entry[$field] = trim((string) ($classRaw[$field] ?? ''));
            }

            // ── Panel image upload ───────────────────────────────────────────
            $entry['panelImage'] = $this->processImageUpload(
                uploadDir:     $uploadDir,
                slug:          $slug,
                uploadedFile:  is_array($filesRaw[$slug] ?? null) ? ($filesRaw[$slug]['panelImage'] ?? null) : null,
                currentImage:  trim((string) ($classRaw['panelImageCurrent'] ?? '')),
                deleteFlag:    ($classRaw['panelImageDelete'] ?? '') === '1',
            );

            // ── Benefits (lista simple) ──────────────────────────────────────
            $benefitsRaw = is_array($classRaw['benefits'] ?? null) ? $classRaw['benefits'] : [];
            $entry['benefits'] = array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), $benefitsRaw),
                static fn ($v) => '' !== $v,
            ));

            // ── Secciones adicionales ────────────────────────────────────────
            $sectionsRaw = is_array($classRaw['sections'] ?? null) ? $classRaw['sections'] : [];
            $entry['sections'] = $this->parseSections($sectionsRaw);

            $data['classes'][$slug] = $entry;
        }

        $json = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if (null === $post) {
            $post = new Post();
            $post->setType(Post::TYPE_STATIC);
            $post->setTitle('Clases Contenido');
            $post->setIsActive(true);
            $this->entityManager->persist($post);
        }

        $post->setContent($json);
        $this->entityManager->flush();

        $this->addFlash('success', 'El contenido de la página de clases ha sido guardado.');

        return $this->redirectToRoute('backend_class_content');
    }

    private function processImageUpload(
        string $uploadDir,
        string $slug,
        mixed $uploadedFile,
        string $currentImage,
        bool $deleteFlag,
    ): string {
        if ($uploadedFile instanceof UploadedFile && $uploadedFile->isValid()) {
            $ext         = $uploadedFile->guessExtension() ?? 'jpg';
            $newFilename = $slug . '-panel-' . uniqid() . '.' . $ext;
            $uploadedFile->move($uploadDir, $newFilename);

            if ('' !== $currentImage && file_exists($uploadDir . '/' . $currentImage)) {
                unlink($uploadDir . '/' . $currentImage);
            }

            return $newFilename;
        }

        if ($deleteFlag) {
            if ('' !== $currentImage && file_exists($uploadDir . '/' . $currentImage)) {
                unlink($uploadDir . '/' . $currentImage);
            }

            return '';
        }

        return $currentImage;
    }

    /**
     * @param array<mixed> $sectionsRaw
     *
     * @return array<int, array{icon: string, title: string, text: string}>
     */
    private function parseSections(array $sectionsRaw): array
    {
        $sections = [];

        foreach ($sectionsRaw as $sectionRaw) {
            if (!is_array($sectionRaw)) {
                continue;
            }

            $title = trim((string) ($sectionRaw['title'] ?? ''));
            $icon  = trim((string) ($sectionRaw['icon']  ?? ''));
            $text  = trim((string) ($sectionRaw['text']  ?? ''));

            if ('' !== $title || '' !== $text) {
                $sections[] = ['icon' => $icon, 'title' => $title, 'text' => $text];
            }
        }

        return $sections;
    }

    /**
     * Asegura que cada disciplina tenga su entrada en editorData con valores vacíos por defecto.
     *
     * @param array<string, mixed>               $editorData
     * @param array<int, \App\Entity\Discipline> $disciplines
     *
     * @return array<string, mixed>
     */
    private function normalizeEditorPayload(array $editorData, array $disciplines): array
    {
        // Defaults para info boxes si no existen
        $infoBoxDefaults = [
            'infoBox1' => ['icon' => 'bi-people-fill',    'title' => 'Clase Grupal',    'text' => 'Grupos reducidos para garantizar atención personalizada.'],
            'infoBox2' => ['icon' => 'bi-calendar-check', 'title' => 'Reserva tu clase', 'text' => 'Consulta horarios disponibles y reserva en línea.'],
        ];
        foreach ($infoBoxDefaults as $key => $default) {
            if (!isset($editorData[$key]) || !is_array($editorData[$key])) {
                $editorData[$key] = $default;
            }
        }

        if (!isset($editorData['classes']) || !is_array($editorData['classes'])) {
            $editorData['classes'] = [];
        }

        foreach ($disciplines as $discipline) {
            $slug      = self::disciplineSlug((string) $discipline);
            $classData = is_array($editorData['classes'][$slug] ?? null) ? $editorData['classes'][$slug] : [];

            foreach (['queEs', 'paraQuienEs', 'duration', 'panelImage'] as $field) {
                $classData[$field] = is_string($classData[$field] ?? null) ? $classData[$field] : '';
            }

            // Defaults para íconos y etiquetas editables de las secciones fijas
            $iconLabelDefaults = [
                'queEsIcon'        => 'bi-info-circle',
                'queEsLabel'       => '¿Qué es?',
                'benefitsIcon'     => 'bi-check-square',
                'benefitsLabel'    => 'Beneficios',
                'paraQuienEsIcon'  => 'bi-people',
                'paraQuienEsLabel' => '¿Para quién es?',
                'durationIcon'     => 'bi-clock',
                'durationLabel'    => 'Duración',
            ];
            foreach ($iconLabelDefaults as $field => $default) {
                if (!isset($classData[$field]) || '' === $classData[$field]) {
                    $classData[$field] = $default;
                }
            }

            $classData['benefits'] = $this->classContentService->normalizeBenefitsForEditor($classData);
            $classData['sections'] = $this->classContentService->normalizeSectionsForEditor($classData);

            $editorData['classes'][$slug] = $classData;
        }

        return $editorData;
    }

    /**
     * @param mixed $raw
     *
     * @return array{icon: string, title: string, text: string}
     */
    private function parseInfoBox(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['icon' => '', 'title' => '', 'text' => ''];
        }

        return [
            'icon'  => trim((string) ($raw['icon']  ?? '')),
            'title' => trim((string) ($raw['title'] ?? '')),
            'text'  => trim((string) ($raw['text']  ?? '')),
        ];
    }

    /**
     * Genera un slug desde el nombre de una disciplina, idéntico a la lógica del home template Twig.
     *   {{ discipline.name|lower|replace({' ': '-', 'ñ': 'n', 'á': 'a', ...}) }}
     */
    public static function disciplineSlug(string $name): string
    {
        $map  = ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', ' ' => '-'];
        $name = mb_strtolower($name);

        return str_replace(array_keys($map), array_values($map), $name);
    }
}
