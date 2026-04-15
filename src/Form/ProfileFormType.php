<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Profile Form Type.
 */
class ProfileFormType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('lastname')
            ->add('phone')
            ->add('email')
            ->add('birthday', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('emergencyContactName')
            ->add('emergencyContactPhone')
        ;

        $builder->get('birthday')->addModelTransformer(new CallbackTransformer(
            static function (?\DateTimeInterface $birthday): string {
                if (!$birthday instanceof \DateTimeInterface) {
                    return '';
                }

                return $birthday->format('d/m');
            },
            static function (?string $birthday): ?\DateTimeInterface {
                if (null === $birthday || '' === trim($birthday)) {
                    return null;
                }

                $normalized = preg_replace('/[^0-9]/', '', $birthday ?? '');
                if (null === $normalized) {
                    throw new TransformationFailedException('Ingresa la fecha en formato dd/mm.');
                }

                if (3 === strlen($normalized)) {
                    $day = (int) substr($normalized, 0, 1);
                    $month = (int) substr($normalized, 1, 2);
                } elseif (4 === strlen($normalized)) {
                    $day = (int) substr($normalized, 0, 2);
                    $month = (int) substr($normalized, 2, 2);
                } elseif (strpos($birthday, '/') !== false || strpos($birthday, '-') !== false) {
                    $parts = preg_split('/[\/\-]+/', trim($birthday));
                    if (!is_array($parts) || count($parts) < 2) {
                        throw new TransformationFailedException('Ingresa la fecha en formato dd/mm.');
                    }

                    $day = (int) ($parts[0] ?? 0);
                    $month = (int) ($parts[1] ?? 0);
                } else {
                    throw new TransformationFailedException('Ingresa la fecha en formato dd/mm.');
                }

                if (!checkdate($month, $day, 2000)) {
                    throw new TransformationFailedException('Fecha de cumpleaños inválida.');
                }

                $parsedBirthday = \DateTimeImmutable::createFromFormat('!d/m/Y', sprintf('%02d/%02d/2000', $day, $month));
                if (!$parsedBirthday instanceof \DateTimeImmutable) {
                    throw new TransformationFailedException('Ingresa la fecha en formato dd/mm.');
                }

                return $parsedBirthday;
            }
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Profile', 'Default'],
        ]);
    }
}
