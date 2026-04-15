<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/p/{slug}', name: 'page')]
    public function index(Post $post): Response
    {
        return $this->render('page/index.html.twig', [
            'post' => $post,
        ]);
    }
}
