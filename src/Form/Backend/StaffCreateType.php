<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\BranchOffice;
use App\Entity\Staff;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType as CorePasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CreateType.
 */
class StaffCreateType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('roles', ChoiceType::class, [
                'choices' => [
                    'Administrador' => 'ROLE_ADMIN',
                    'Colaborador' => 'ROLE_COLLABORATOR',
                ],
            ])
            ->add('permissions', ChoiceType::class, [
                'choices' => $options['permissions'],
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('branchOffices', EntityType::class, [
                'class' => BranchOffice::class,
                'multiple' => true,
                'expanded' => true,
            ])
        ;

        $builder->get('roles')
            ->addModelTransformer(new CallbackTransformer(
                function ($rolesAsArray) {
                    return implode(', ', $rolesAsArray);
                },
                function ($rolesAsString) {
                    return explode(', ', $rolesAsString);
                }
            ))
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $instructor = $event->getData();
            $form = $event->getForm();

            if (!$instructor || null === $instructor->getId()) {
                $form
                    ->add('username')
                    ->add('password', RepeatedType::class, [
                        'type' => CorePasswordType::class,
                    ])
                ;
            } else {
                $form
                    ->add('isActive')
                ;
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setDefaults([
                'data_class' => Staff::class,
                'permissions' => [],
            ])
            ->setAllowedTypes('permissions', 'array')
        ;
    }
}
