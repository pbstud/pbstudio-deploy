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
            'homeContent' => $homeContent,
            'defaults'    => $this->getDefaults(),
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

        $this->addFlash('success', 'El contenido del home ha sido guardado.');

        return $this->redirectToRoute('backend_home_content');
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
