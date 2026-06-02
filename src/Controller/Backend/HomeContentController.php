<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\HomeContent;
use App\Repository\HomeContentRepository;
use App\Service\HomeContentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Handler\UploadHandler;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/home-contenido', name: 'backend_home_content', methods: ['GET', 'POST'])]
class HomeContentController extends AbstractController
{
    private const FAQ_ICONS = [
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

    private const FAQ_FILE      = 'var/data/faq_items.json';
    private const FEATURES_FILE  = 'var/data/feature_items.json';
    public function __construct(
        private readonly HomeContentRepository $homeContentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeContentService $homeContentService,
        private readonly UploadHandler $uploadHandler,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $homeContent = $this->homeContentRepository->findSingle();

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $homeContent);
        }

        return $this->render('backend/home_content/edit.html.twig', [
            'homeContent'    => $homeContent,
            'defaults'       => $this->getDefaults(),
            'galleryImages'  => $this->getGalleryImages(),
            'faqItems'       => $this->readFaqItems(),
            'faqIcons'       => self::FAQ_ICONS,
            'featureItems'   => $this->readFeatureItems(),
        ]);
    }

    private function handlePost(Request $request, ?HomeContent $homeContent): Response
    {
        $isNew = null === $homeContent;
        if ($isNew) {
            $homeContent = new HomeContent();
            $this->entityManager->persist($homeContent);
        }

        $raw = $request->request->all();
        $files = $request->files->all();

        // Textos de cajas y contacto
        $homeContent->setBox1Title(trim((string) ($raw['box1Title'] ?? '')));
        $homeContent->setBox1Description(trim((string) ($raw['box1Description'] ?? '')));
        $homeContent->setBox1Url(trim((string) ($raw['box1Url'] ?? '')));
        $homeContent->setBox1LinkLabel(trim((string) ($raw['box1LinkLabel'] ?? '')));

        $homeContent->setBox2Title(trim((string) ($raw['box2Title'] ?? '')));
        $homeContent->setBox2Description(trim((string) ($raw['box2Description'] ?? '')));
        $homeContent->setBox2Url(trim((string) ($raw['box2Url'] ?? '')));
        $homeContent->setBox2LinkLabel(trim((string) ($raw['box2LinkLabel'] ?? '')));

        $homeContent->setContactEmail(trim((string) ($raw['contactEmail'] ?? '')));
        $homeContent->setContactFacebook(trim((string) ($raw['contactFacebook'] ?? '')));
        $homeContent->setContactInstagram(trim((string) ($raw['contactInstagram'] ?? '')));
        $homeContent->setContactWhatsapp(trim((string) ($raw['contactWhatsapp'] ?? '')));

        // Actualizar timestamp en cada guardado (no solo al subir imagen)
        $homeContent->setUpdatedAt(new \DateTimeImmutable());

        // === FAQ ===
        $faqIcons      = array_keys(self::FAQ_ICONS);
        $faqQuestions  = (array) ($raw['faqQuestion'] ?? []);
        $faqAnswers    = (array) ($raw['faqAnswer'] ?? []);
        $faqIconsInput = (array) ($raw['faqIcon'] ?? []);
        $faqItems      = [];
        foreach ($faqQuestions as $i => $q) {
            $q = trim((string) $q);
            $a = trim((string) ($faqAnswers[$i] ?? ''));
            $icon = trim((string) ($faqIconsInput[$i] ?? ''));
            if ($q === '' && $a === '') {
                continue; // omitir filas vacías
            }
            if (!in_array($icon, $faqIcons, true)) {
                $icon = 'bi-question-circle';
            }
            $faqItems[] = ['icon' => $icon, 'question' => $q, 'answer' => $a];
        }
        $this->saveFaqItems($faqItems);

        // === FEATURES ===
        $faqIconKeys      = array_keys(self::FAQ_ICONS);
        $featTitles       = (array) ($raw['featureTitle'] ?? []);
        $featTexts        = (array) ($raw['featureText'] ?? []);
        $featIconsInput   = (array) ($raw['featureIcon'] ?? []);
        $featureItems     = [];
        foreach ($featTitles as $i => $t) {
            $t    = trim((string) $t);
            $txt  = trim((string) ($featTexts[$i] ?? ''));
            $icon = trim((string) ($featIconsInput[$i] ?? ''));
            if ($t === '' && $txt === '') {
                continue;
            }
            if (!in_array($icon, $faqIconKeys, true)) {
                $icon = 'bi-check-circle';
            }
            $featureItems[] = ['icon' => $icon, 'title' => $t, 'text' => $txt];
        }
        $this->saveFeatureItems($featureItems);

        // Imágenes — solo se actualizan si se sube un archivo nuevo
        if (!empty($files['bannerDesktopFile'])) {
            $homeContent->setBannerDesktopFile($files['bannerDesktopFile']);
            $this->uploadHandler->upload($homeContent, 'bannerDesktopFile');
        }

        if (!empty($files['bannerMobileFile'])) {
            $homeContent->setBannerMobileFile($files['bannerMobileFile']);
            $this->uploadHandler->upload($homeContent, 'bannerMobileFile');
        }

        if (!empty($files['box1ImageFile'])) {
            $homeContent->setBox1ImageFile($files['box1ImageFile']);
            $this->uploadHandler->upload($homeContent, 'box1ImageFile');
        }

        if (!empty($files['box2ImageFile'])) {
            $homeContent->setBox2ImageFile($files['box2ImageFile']);
            $this->uploadHandler->upload($homeContent, 'box2ImageFile');
        }

        // Borrar imágenes si se marcó el checkbox correspondiente
        if (!empty($raw['clearBannerDesktop'])) {
            $this->uploadHandler->remove($homeContent, 'bannerDesktopFile');
            $homeContent->setBannerDesktop(null);
        }

        if (!empty($raw['clearBannerMobile'])) {
            $this->uploadHandler->remove($homeContent, 'bannerMobileFile');
            $homeContent->setBannerMobile(null);
        }

        if (!empty($raw['clearBox1Image'])) {
            $this->uploadHandler->remove($homeContent, 'box1ImageFile');
            $homeContent->setBox1Image(null);
        }

        if (!empty($raw['clearBox2Image'])) {
            $this->uploadHandler->remove($homeContent, 'box2ImageFile');
            $homeContent->setBox2Image(null);
        }

        $this->entityManager->flush();

        // === GALERÍA ===
        $galleryDir = $this->getParameter('kernel.project_dir') . '/public/media/uploads/gallery';
        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0775, true);
        }

        // Eliminar imágenes marcadas
        foreach ((array) ($raw['deleteGallery'] ?? []) as $filename) {
            $filename = basename((string) $filename); // evitar path traversal
            $path = $galleryDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

        // Subir nuevas imágenes
        $newFiles = $request->files->get('galleryFiles') ?? [];
        if (!is_array($newFiles)) {
            $newFiles = [$newFiles];
        }
        $allowed  = ['image/jpeg', 'image/png', 'image/webp'];
        $maxBytes = 8 * 1024 * 1024; // 8 MB por imagen
        $maxFiles = 50;              // máximo de imágenes totales en la galería
        $uploaded = 0;

        foreach ($newFiles as $uploadedFile) {
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                continue;
            }

            // Validar MIME real (usa finfo internamente, no confiar en la extensión del cliente)
            $mime = $uploadedFile->getMimeType();
            if (!in_array($mime, $allowed, true)) {
                continue;
            }

            // Validar tamaño
            if ($uploadedFile->getSize() > $maxBytes) {
                continue;
            }

            // Limitar cantidad total en galería
            $existingCount = count(glob($galleryDir . '/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: []);
            if (($existingCount + $uploaded) >= $maxFiles) {
                break;
            }

            // La extensión se infiere desde el MIME (no desde el nombre del cliente)
            $ext  = $uploadedFile->guessExtension() ?: 'jpg';
            $name = uniqid('g_', true) . '.' . $ext;
            $uploadedFile->move($galleryDir, $name);
            ++$uploaded;
        }

        $this->addFlash('success', 'El contenido del home ha sido guardado.');

        return $this->redirectToRoute('backend_home_content');
    }

    private function readFaqItems(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/' . self::FAQ_FILE;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveFaqItems(array $items): void
    {
        $path = $this->getParameter('kernel.project_dir') . '/' . self::FAQ_FILE;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function readFeatureItems(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/' . self::FEATURES_FILE;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveFeatureItems(array $items): void
    {
        $path = $this->getParameter('kernel.project_dir') . '/' . self::FEATURES_FILE;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string>
     */
    private function getGalleryImages(): array
    {
        $dir = $this->getParameter('kernel.project_dir') . '/public/media/uploads/gallery';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [];
        sort($files);
        return array_map(fn(string $f) => basename($f), $files);
    }

    /**
     * @return array<string, string>
     */
    private function getDefaults(): array
    {
        return [
            'box1Title'       => HomeContentService::DEFAULT_BOX1_TITLE,
            'box1Description' => HomeContentService::DEFAULT_BOX1_DESCRIPTION,
            'box1Url'         => HomeContentService::DEFAULT_BOX1_URL,
            'box1LinkLabel'   => HomeContentService::DEFAULT_BOX1_LINK_LABEL,
            'box2Title'       => HomeContentService::DEFAULT_BOX2_TITLE,
            'box2Description' => HomeContentService::DEFAULT_BOX2_DESCRIPTION,
            'box2Url'         => HomeContentService::DEFAULT_BOX2_URL,
            'box2LinkLabel'   => HomeContentService::DEFAULT_BOX2_LINK_LABEL,
            'contactEmail'    => HomeContentService::DEFAULT_CONTACT_EMAIL,
            'contactFacebook' => HomeContentService::DEFAULT_CONTACT_FACEBOOK,
            'contactInstagram'=> HomeContentService::DEFAULT_CONTACT_INSTAGRAM,
            'contactWhatsapp' => HomeContentService::DEFAULT_CONTACT_WHATSAPP,
        ];
    }
}
