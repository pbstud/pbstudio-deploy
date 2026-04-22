<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\BranchOffice;
use App\Entity\Package;
use App\Entity\Transaction;
use App\Form\DataTransformer\UserToIdTransformer;
use App\Model\TransactionModel;
use App\Util\PackageSessionType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * TransactionType.
 */
class TransactionType extends AbstractType
{
    public function __construct(
        private readonly UserToIdTransformer $userToIdTransformer,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('branchOffice', EntityType::class, [
                'label' => 'label.branch_office',
                'class' => BranchOffice::class,
                'placeholder' => 'placeholder.select',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('bo')
                        ->where('bo.public = :public')
                        ->setParameter('public', true)
                        ->orderBy('bo.name', 'ASC');
                },
            ])
            ->add('user', HiddenType::class, [
                'label' => 'label.user',
                'invalid_message' => 'Selecciona un usuario valido.',
            ])
            ->add('package', EntityType::class, [
                'label' => 'label.package',
                'class' => Package::class,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('p')
                        ->where('p.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('p.amount')
                    ;
                },
                'choice_label' => function (Package $package) {
                    $label = sprintf(
                        '%s clase%s',
                        $package->isIsUnlimited() ? '∞' : $package->getTotalClasses(),
                        $package->isIsUnlimited() || $package->getTotalClasses() > 1 ? 's' : null,
                    );

                    if (!empty($package->getAltText())) {
                        $label .= ' - '.$package->getAltText();
                    }

                    return $label;
                },
                'choice_attr' => function (Package $package) {
                    $currentDate = (new \DateTime())
                        ->add(new \DateInterval(sprintf('P%sD', $package->getDaysExpiry())))
                    ;

                    $price = $package->getSpecialPrice() ?: $package->getAmount();

                    return [
                        'class' => $package->isIsUnlimited() ? 'unlimited' : null,
                        'data-amount' => number_format((float) $package->getAmount(), 2),
                        'data-special-price' => $package->getSpecialPrice() ?
                            number_format((float) $package->getSpecialPrice(), 2) : '--',
                        'data-amount-original' => number_format((float) $price, 2, '.', ''),
                        'data-expiration-at' => $currentDate->format('d/m/Y'),
                    ];
                },
                'group_by' => function (Package $package) {
                    return PackageSessionType::getDescription($package->getType());
                },
                'placeholder' => 'placeholder.select',
            ])
            ->add('chargeMethod', ChoiceType::class, [
                'label' => 'label.charge_method',
                'choices' => array_flip(Transaction::chargeMethodChoices()),
            ])
            ->add('discount', null, [
                'label' => 'label.additional_discount',
            ])
            ->add('chargeAuthCode', null, [
                'label' => 'label.charge_auth_code',
            ])
            ->add('cardLast4', null, [
                'label' => 'label.card_last4',
            ])
            ->add('coupon', HiddenType::class, [
                'label' => 'label.coupon',
            ])
        ;

        $builder->get('user')->addModelTransformer($this->userToIdTransformer);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TransactionModel::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'transaction';
    }
}
