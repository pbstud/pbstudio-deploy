<?php
/**
 * Created by.
 *
 * User: JCHR <car.chr@gmail.com>
 * Date: 2020-11-05
 * Time: 09:07
 */

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Coupon;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Util\PackageSessionType;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Coupon Type.
 *
 * @author JCHR <car.chr@gmail.com>
 */
class CouponType extends AbstractType
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
            ->add('code', null, [
                'label' => 'label.code',
            ])
            ->add('dateStart', DateType::class, [
                'label' => 'label.date_start',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
            ])
            ->add('dateEnd', DateType::class, [
                'label' => 'label.date_end',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
            ])
            ->add('discount', null, [
                'label' => 'label.discount',
                'block_prefix' => 'percent',
            ])
            ->add('usesTotal', null, [
                'label' => 'label.uses_total',
            ])
            ->add('applySpecialPrice', null, [
                'label' => 'label.apply_special_price',
                'block_prefix' => 'switch',
            ])
            ->add('packages', null, [
                'label' => 'label.packages',
                'class' => Package::class,
                'query_builder' => function (PackageRepository $er) {
                    return $er->createQueryBuilder('p')
                        ->where('p.isActive = :active')
                        ->setParameter('active', true)
                        ->orderBy('p.type')
                        ->addOrderBy('p.amount')
                    ;
                },
                'choice_label' => function (Package $package) {
                    $label = sprintf(
                        '%s clase(s)',
                        $package->isIsUnlimited() ? '∞' : $package->getTotalClasses(),
                    );

                    if (!empty($package->getAltText())) {
                        $label .= ' - '.$package->getAltText();
                    }

                    return $label;
                },
                'group_by' => function (Package $package) {
                    return PackageSessionType::getDescription($package->getType());
                },
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Coupon::class,
        ]);
    }
}
