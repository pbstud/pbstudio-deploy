<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Post;
use App\Repository\PostRepository;
use App\Service\ClassContentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ALLOWED_ROUTE_ACCESS')]
#[Route('/backend/clases-contenido', name: 'backend_class_content', methods: ['GET', 'POST'])]
class ClassContentController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassContentService $classContentService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $post = $this->postRepository->findOneBy(['slug' => ClassContentService::POST_SLUG]);

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $post);
        }

        // Cargar datos del editor: primero de BD, si no existe devuelve vacío (el seed aun no se ejecuto)
        $editorData = $this->classContentService->loadEditorPayload();

        return $this->render('backend/class_content/edit.html.twig', [
            'data'         => $editorData,
            'slugs'        => ClassContentService::KNOWN_SLUGS,
            'scalarFields' => ClassContentService::SCALAR_FIELDS,
            'arrayFields'  => ClassContentService::ARRAY_FIELDS,
            'post'         => $post,
        ]);
    }

    private function handlePost(Request $request, ?Post $post): Response
    {
        $raw = $request->request->all();

        $data = [
            'schemaVersion' => 1,
            'header'        => [
                'title'    => trim((string) ($raw['header']['title']    ?? '')),
                'subtitle' => trim((string) ($raw['header']['subtitle'] ?? '')),
            ],
            'classes' => [],
        ];

        foreach (ClassContentService::KNOWN_SLUGS as $slug) {
            $classRaw = $raw['classes'][$slug] ?? [];
            $entry    = [];

            foreach (ClassContentService::SCALAR_FIELDS as $field) {
                $entry[$field] = trim((string) ($classRaw[$field] ?? ''));
            }

            foreach (ClassContentService::ARRAY_FIELDS as $field) {
                $items = $classRaw[$field] ?? [];
                if (!is_array($items)) {
                    $items = [];
                }
                $entry[$field] = array_values(array_filter(
                    array_map(static fn ($v) => trim((string) $v), $items),
                    static fn ($v) => '' !== $v,
                ));
            }

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
}
