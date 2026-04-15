<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Discipline;
use App\Entity\ExerciseRoom;
use App\Entity\Session;
use App\Entity\Staff;
use App\Repository\ExerciseRoomRepository;
use App\Util\Schedule;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Session Type.
 */
class SessionType extends AbstractType
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Session $session */
        $session = $builder->getData();
        $useFlexibleTimeStart = (bool) ($options['use_flexible_time_start'] ?? false);

        $builder
            ->add('dateStart', DateType::class, [
                'label' => 'label.date_start',
                'format' => 'dd/MM/yyyy',
                'widget' => 'single_text',
                'html5' => false,
            ])
            ->add('information', null, [
                'label' => 'label.information',
            ])
            ->add('branchOffice', null, [
                'label' => 'label.branch_office',
            ])
        ;

        if ($useFlexibleTimeStart) {
            $builder->add('timeStart', TimeType::class, [
                'label' => 'label.time_start',
                'widget' => 'single_text',
                'with_seconds' => false,
                'input' => 'datetime',
                'attr' => [
                    'step' => 60,
                ],
            ]);
        } else {
            $builder->add('timeStart', ChoiceType::class, [
                'label' => 'label.time_start',
                'choices' => $this->schedule->getSchedules(),
            ]);

            $builder->get('timeStart')
                ->addModelTransformer(new CallbackTransformer(
                    static function ($timeStart) {
                        return $timeStart ? $timeStart->format('H:i') : $timeStart;
                    },
                    static function ($timeStart) {
                        return $timeStart ? \DateTime::createFromFormat('H:i', $timeStart) : null;
                    }
                ))
            ;
        }

        if ($session->getId()) {
            $builder
                ->add('exerciseRoomCapacity', null, [
                    'label' => 'label.exercise_room_capacity',
                ])
                ->add('placesNotAvailable', TextType::class, [
                    'label' => 'label.places_not_available',
                    'required' => false,
                    'attr' => [
                        'data-role' => 'tagsinput',
                    ],
                ])
            ;

            $builder->get('placesNotAvailable')
                ->addModelTransformer(new CallbackTransformer(
                    static function ($arrayAsString) {
                        return implode(',', is_array($arrayAsString) ? $arrayAsString : []);
                    },
                    static function ($stringAsArray) {
                        return !empty($stringAsArray) ? explode(',', $stringAsArray) : [];
                    }
                ))
            ;
        }

        $branchOfficeModifier = static function (FormInterface $form, int $branchOfficeId = null) {
            $options = [
                'label' => 'label.exercise_room',
                'class' => ExerciseRoom::class,
                'placeholder' => '',
            ];

            if ($branchOfficeId) {
                $options['query_builder'] = static function (ExerciseRoomRepository $exerciseRoomRepository) use ($branchOfficeId) {
                    return $exerciseRoomRepository->createQueryBuilder('er')
                        ->where('er.branchOffice = :branchOffice')
                        ->andWhere('er.isActive = :isActive')
                        ->orderBy('er.name')

                        ->setParameter('branchOffice', $branchOfficeId)
                        ->setParameter('isActive', true);
                };
            } else {
                $options['choices'] = [];
            }

            $form->add('exerciseRoom', EntityType::class, $options);
        };

        $em = $this->em;

        $formExerciseRoomModifier = static function (FormInterface $form, int $exerciseRoomId = null) use ($em) {
            /** @var ExerciseRoom $exerciseRoom */
            $exerciseRoom = null;

            if ($exerciseRoomId) {
                $exerciseRoom = $em->getRepository(ExerciseRoom::class)->getById($exerciseRoomId);
            }

            /** @var Discipline $discipline */
            $discipline = $exerciseRoom ? $exerciseRoom->getDiscipline() : null;

            /** @var Collection $instructors */
            $instructors = $discipline ? $discipline->getInstructors() : [];

            if ($instructors) {
                $instructors = $instructors->filter(static function (Staff $staff) {
                    return $staff->isEnabled() && !$staff->isDeleted();
                });
            }

            $discipline = $discipline ? [$discipline] : [];

            $form->add('discipline', EntityType::class, [
                'label' => 'label.discipline',
                'class' => Discipline::class,
                'choices' => $discipline,
                'placeholder' => null,
            ]);

            $form->add('instructor', EntityType::class, [
                'label' => 'label.instructor',
                'class' => Staff::class,
                'choices' => $instructors,
                'placeholder' => null,
            ]);
        };

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (FormEvent $event) use ($branchOfficeModifier, $formExerciseRoomModifier) {
                /** @var Session $data */
                $data = $event->getData();
                $form = $event->getForm();

                $branchOfficeId = $data->getBranchOffice()?->getId();
                $exerciseRoomId = $data->getExerciseRoom()?->getId();

                $branchOfficeModifier($form, $branchOfficeId);
                $formExerciseRoomModifier($form, $exerciseRoomId);
            }
        );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event) use ($branchOfficeModifier, $formExerciseRoomModifier) {
                $data = $event->getData();

                $branchOfficeModifier($event->getForm(), !empty($data['branchOffice']) ? (int) $data['branchOffice'] : null);
                $formExerciseRoomModifier($event->getForm(), !empty($data['exerciseRoom']) ? (int) $data['exerciseRoom'] : null);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Session::class,
            'use_flexible_time_start' => false,
        ]);

        $resolver->setAllowedTypes('use_flexible_time_start', 'bool');
    }
}
