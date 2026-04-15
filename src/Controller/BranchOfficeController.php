<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BranchOfficeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class BranchOfficeController extends AbstractController
{
    public function all(BranchOfficeRepository $branchOfficeRepository): Response
    {
        return $this->render('branch_office/_all.html.twig', [
            'branch_offices' => $branchOfficeRepository->getPublic(),
        ]);
    }
}
