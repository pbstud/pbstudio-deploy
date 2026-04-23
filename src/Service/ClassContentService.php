<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PostRepository;

/**
 * Resuelve el contenido editable de la pagina /clases.
 *
 * Lee el Post con slug "clases-contenido" (tipo static) desde la BD.
 * El campo `content` almacena un JSON con la estructura:
 *
 * {
 *   "header": { "title": "...", "subtitle": "..." },
 *   "classes": {
 *     "pilates-reformer":  { <campos> },
 *     "beastformer":       { <campos> },
 *     "dual-pilates":      { <campos> },
 *     "clase-privada":     { <campos> }
 *   }
 * }
 *
 * Si el Post no existe o el JSON es invalido, los metodos devuelven null / el fallback sin cambios.
 */
class ClassContentService
{
    public const POST_SLUG = 'clases-contenido';

    /** Slugs canonicos editables (orden de aparicion en el editor). */
    public const KNOWN_SLUGS = [
        'pilates-reformer',
        'beastformer',
        'dual-pilates',
        'clase-privada',
    ];

    public const SCALAR_FIELDS = ['audience', 'duration', 'intensity', 'focus', 'summary', 'description'];
    public const ARRAY_FIELDS  = ['bestFor', 'keyPostures', 'guidedFlow', 'benefits', 'tips'];
    public const ARRAY_SECTION_TITLE_FIELDS = [
        'bestFor' => 'bestForTitle',
        'keyPostures' => 'keyPosturesTitle',
        'guidedFlow' => 'guidedFlowTitle',
        'benefits' => 'benefitsTitle',
        'tips' => 'tipsTitle',
    ];
    public const ARRAY_SECTION_TITLE_DEFAULTS = [
        'bestFor' => 'Ideal para ti si...',
        'keyPostures' => 'Posturas y guias clave',
        'guidedFlow' => 'Como se estructura la clase',
        'benefits' => 'Beneficios esperados',
        'tips' => 'Recomendaciones previas',
    ];

    private const MAX_SCALAR_LENGTH = 400;

    public function __construct(private readonly PostRepository $postRepository)
    {
    }

    /**
     * Devuelve el encabezado de pagina desde la BD, o null si no existe / esta vacio.
     *
     * @return array{title: string, subtitle: string}|null
     */
    public function getHeader(): ?array
    {
        $payload = $this->loadPayload();
        if (null === $payload) {
            return null;
        }

        $header = $payload['header'] ?? null;
        if (!is_array($header)) {
            return null;
        }

        $title    = isset($header['title'])    && is_string($header['title'])    ? trim($header['title'])    : '';
        $subtitle = isset($header['subtitle']) && is_string($header['subtitle']) ? trim($header['subtitle']) : '';

        if ('' === $title && '' === $subtitle) {
            return null;
        }

        return ['title' => $title, 'subtitle' => $subtitle];
    }

    /**
     * Combina el contenido de la BD con el fallback hardcoded para una clase concreta.
     *
     * @param array<string, mixed> $fallback     Datos del CLASS_CONTENT_MAP del controller.
     * @param string               $contentSlug  Slug canonico de la clase.
     *
     * @return array<string, mixed>
     */
    public function mergeClassContent(array $fallback, string $contentSlug): array
    {
        $payload = $this->loadPayload();
        if (null === $payload) {
            $fallback['sections'] = $this->buildLegacySections($fallback);
            return $fallback;
        }

        $classData = ($payload['classes'] ?? [])[$contentSlug] ?? null;
        if (!is_array($classData)) {
            $fallback['sections'] = $this->buildLegacySections($fallback);
            return $fallback;
        }

        $merged = $fallback;

        foreach (self::SCALAR_FIELDS as $field) {
            $val = $classData[$field] ?? null;
            if (is_string($val)) {
                $val = trim($val);
                if ('' !== $val && mb_strlen($val) <= self::MAX_SCALAR_LENGTH) {
                    $merged[$field] = $val;
                }
            }
        }

        $sections = $this->normalizeSections($classData['sections'] ?? null);

        if ([] === $sections) {
            foreach (self::ARRAY_SECTION_TITLE_FIELDS as $titleField) {
                $val = $classData[$titleField] ?? null;
                if (is_string($val)) {
                    $val = trim($val);
                    if ('' !== $val && mb_strlen($val) <= self::MAX_SCALAR_LENGTH) {
                        $merged[$titleField] = $val;
                    }
                }
            }

            foreach (self::ARRAY_FIELDS as $field) {
                $items = $classData[$field] ?? null;
                if (is_array($items)) {
                    $clean = array_values(array_filter(
                        array_map(static fn ($v) => is_string($v) ? trim($v) : '', $items),
                        static fn ($v) => '' !== $v,
                    ));
                    if (count($clean) > 0) {
                        $merged[$field] = $clean;
                    }
                }
            }

            $sections = $this->buildLegacySections($merged);
        }

        $merged['sections'] = $sections;

        return $merged;
    }

    /**
     * @param array<string, mixed> $classData
     *
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    public function normalizeSectionsForEditor(array $classData): array
    {
        $sections = $this->normalizeSections($classData['sections'] ?? null);
        if ([] !== $sections) {
            return $sections;
        }

        return $this->buildLegacySections($classData);
    }

    /**
     * @param mixed $rawSections
     *
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    private function normalizeSections(mixed $rawSections): array
    {
        if (!is_array($rawSections)) {
            return [];
        }

        $sections = [];

        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            if (mb_strlen($title) > self::MAX_SCALAR_LENGTH) {
                $title = mb_substr($title, 0, self::MAX_SCALAR_LENGTH);
            }

            $itemsRaw = $section['items'] ?? [];
            if (!is_array($itemsRaw)) {
                $itemsRaw = [];
            }

            $items = array_values(array_filter(
                array_map(static fn ($v) => is_string($v) ? trim($v) : '', $itemsRaw),
                static fn ($v) => '' !== $v,
            ));

            if ('' !== $title || count($items) > 0) {
                $sections[] = [
                    'title' => $title,
                    'items' => $items,
                ];
            }
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<int, array{title: string, items: array<int, string>}>
     */
    private function buildLegacySections(array $source): array
    {
        $sections = [];

        foreach (self::ARRAY_FIELDS as $field) {
            $titleField = self::ARRAY_SECTION_TITLE_FIELDS[$field] ?? null;
            $title = '';
            if (null !== $titleField && isset($source[$titleField]) && is_string($source[$titleField])) {
                $title = trim($source[$titleField]);
            }
            if ('' === $title) {
                $title = self::ARRAY_SECTION_TITLE_DEFAULTS[$field] ?? 'Seccion';
            }

            $itemsRaw = $source[$field] ?? [];
            if (!is_array($itemsRaw)) {
                $itemsRaw = [];
            }

            $items = array_values(array_filter(
                array_map(static fn ($v) => is_string($v) ? trim($v) : '', $itemsRaw),
                static fn ($v) => '' !== $v,
            ));

            $sections[] = [
                'title' => $title,
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * Carga el payload completo de la BD para el editor del backend.
     * Retorna array vacio si no existe aun.
     *
     * @return array<string, mixed>
     */
    public function loadEditorPayload(): array
    {
        return $this->loadPayload() ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPayload(): ?array
    {
        $post = $this->postRepository->findOneBy([
            'slug'     => self::POST_SLUG,
            'isActive' => true,
        ]);

        if (null === $post) {
            return null;
        }

        $content = $post->getContent();
        if (null === $content || '' === $content) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
