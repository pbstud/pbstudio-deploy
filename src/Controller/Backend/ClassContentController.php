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
        $editorData = $this->normalizeEditorPayload($editorData);

        return $this->render('backend/class_content/edit.html.twig', [
            'data'         => $editorData,
            'slugs'        => ClassContentService::KNOWN_SLUGS,
            'scalarFields' => ClassContentService::SCALAR_FIELDS,
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

            $sectionsRaw = $classRaw['sections'] ?? [];
            $sections = [];

            if (is_array($sectionsRaw)) {
                foreach ($sectionsRaw as $sectionRaw) {
                    if (!is_array($sectionRaw)) {
                        continue;
                    }

                    $title = trim((string) ($sectionRaw['title'] ?? ''));
                    $itemsRaw = $sectionRaw['items'] ?? [];
                    if (!is_array($itemsRaw)) {
                        $itemsRaw = [];
                    }

                    $items = array_values(array_filter(
                        array_map(static fn ($v) => trim((string) $v), $itemsRaw),
                        static fn ($v) => '' !== $v,
                    ));

                    if ('' !== $title || count($items) > 0) {
                        $sections[] = [
                            'title' => $title,
                            'items' => $items,
                        ];
                    }
                }
            }

            $entry['sections'] = $sections;

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

    /**
     * @param array<string, mixed> $editorData
     *
     * @return array<string, mixed>
     */
    private function normalizeEditorPayload(array $editorData): array
    {
        if (!isset($editorData['classes']) || !is_array($editorData['classes'])) {
            $editorData['classes'] = [];
        }

        foreach (ClassContentService::KNOWN_SLUGS as $slug) {
            $classData = $editorData['classes'][$slug] ?? [];
            if (!is_array($classData)) {
                $classData = [];
            }

            $sections = $this->classContentService->normalizeSectionsForEditor($classData);
            if ([] === $sections) {
                $sections = [[
                    'title' => '',
                    'items' => [''],
                ]];
            }

            $classData['sections'] = $sections;
            $editorData['classes'][$slug] = $classData;
        }

        return $editorData;
    }
}
