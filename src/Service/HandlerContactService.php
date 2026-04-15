<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handler Contact Service.
 */
readonly class HandlerContactService
{
    /**
     * HandlerContactService constructor.
     *
     * @param FormFactoryInterface    $formFactory
     * @param UrlGeneratorInterface   $router
     * @param RequestStack            $requestStack
     * @param ConfigurationRepository $configurationRepository
     * @param MailerInterface         $mailer
     */
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $router,
        private RequestStack $requestStack,
        private ConfigurationRepository $configurationRepository,
        private MailerInterface $mailer
    ) {
    }

    /**
     * @return FormInterface
     */
    public function createContactForm(): FormInterface
    {
        $contactForm = $this->formFactory->createBuilder(FormType::class, null, [
            'action' => $this->router->generate('contact_send'),
            'method' => 'POST',
        ]);

        $contactForm
            ->add('name', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length([
                        'min' => 3,
                    ]),
                ],
            ])
            ->add('phone', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length([
                        'min' => 5,
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Assert\Email(),
                ],
            ])
            ->add('message', TextareaType::class, [
                'attr' => [
                    'rows' => 6,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('submit', SubmitType::class)
        ;

        return $contactForm->getForm();
    }

    /**
     * Process form.
     *
     * @return bool
     */
    public function processForm(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        $contactForm = $this->createContactForm();
        $contactForm->handleRequest($request);

        if ($contactForm->isSubmitted() && $contactForm->isValid()) {
            $formData = $contactForm->getData();

            /** @var Configuration $general */
            $general = $this->configurationRepository->findGeneral();

            $config = $general->getData();

            try {
                $this->sendEmail($config, $formData);

                return true;
            } catch (TransportExceptionInterface $e) {
            }
        }

        return false;
    }

    /**
     * @param array $config
     * @param array $data
     *
     * @throws TransportExceptionInterface
     */
    private function sendEmail(array $config, array $data): void
    {
        // "email" is a reserved variable.
        $from = $data['email'];
        unset($data['email']);

        $data['from'] = $from;

        $email = new TemplatedEmail();
        $email
            ->subject('Nuevo mensaje de contacto.')
            ->from(new Address($config['email_contacto'], 'pbstudio.com'))
            ->to($config['email_contacto'])

            ->htmlTemplate('mail/contact-mail.html.twig')
            ->context($data)
        ;

        $this->mailer->send($email);
    }
}
