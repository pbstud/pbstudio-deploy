<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\BranchOffice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BranchOfficeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nombre',
            ])
            ->add('public', null, [
                'label' => 'Publica',
                'block_prefix' => 'switch',
            ])
            ->add('place', null, [
                'label' => 'Sede / Ciudad',
            ])
            ->add('zone', null, [
                'label' => 'Zona',
                'required' => false,
            ])
            ->add('plaza', null, [
                'label' => 'Plaza / Centro',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Direccion completa',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'class' => 'js-autogrow',
                    'style' => 'resize: none; overflow: hidden;',
                ],
            ])
            ->add('phone', null, [
                'label' => 'Telefono',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BranchOffice::class,
        ]);
    }
}
