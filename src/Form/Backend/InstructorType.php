<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Discipline;
use App\Entity\Staff;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * InstructorType.
 */
class InstructorType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', null, [
                'label' => 'label.email',
            ])
            ->add('isActive', null, [
                'label' => 'label.is_active',
                'block_prefix' => 'switch',
            ])
            ->add('profile', InstructorProfileType::class)
            ->add('disciplines', EntityType::class, [
                'label' => 'label.disciplines',
                'class' => Discipline::class,
                'multiple' => 'true',
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $instructor = $event->getData();
            $form = $event->getForm();
            if (!$instructor || null === $instructor->getId()) {
                $form
                    ->add('username', null, [
                        'label' => 'label.username',
                    ])
                    ->add('password', RepeatedType::class, [
                        'type' => PasswordType::class,
                        'first_options' => [
                            'label' => 'label.password',
                        ],
                        'second_options' => [
                            'label' => 'label.repeat_password',
                        ],
                    ]);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Staff::class]);
    }
}
