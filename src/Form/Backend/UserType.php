<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\BranchOffice;
use App\Entity\User;
use App\Repository\BranchOfficeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'label.name',
            ])
            ->add('lastname', null, [
                'label' => 'label.lastname',
            ])
            ->add('phone', null, [
                'label' => 'label.phone',
            ])
            ->add('email', null, [
                'label' => 'label.email',
            ])
            ->add('branchOffice', EntityType::class, [
                'placeholder' => 'Sucursal preferida',
                'label' => 'label.branch_office',
                'class' => BranchOffice::class,
                'query_builder' => static function (BranchOfficeRepository $branchOfficeRepository) {
                    return $branchOfficeRepository->queryPublic();
                },
            ])
            ->add('birthday', DateType::class, [
                'label' => 'label.birthday',
                'format' => 'dd/MM',
                'widget' => 'single_text',
                'html5' => false,
            ])
            ->add('emergencyContactName', null, [
                'label' => 'label.emergency_contact_name',
            ])
            ->add('emergencyContactPhone', null, [
                'label' => 'label.emergency_contact_phone',
            ])
            ->add('enabled', null, [
                'label' => 'label.active',
                'block_prefix' => 'switch',
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
