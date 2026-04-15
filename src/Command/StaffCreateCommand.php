<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Staff;
use App\Entity\StaffProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:staff:create',
    description: 'Staff create',
)]
class StaffCreateCommand extends Command
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('firstname', InputArgument::REQUIRED, 'The firstname')
            ->addArgument('paternal_surname', InputArgument::REQUIRED, 'The paternal surname')
            ->addArgument('maternal_surname', InputArgument::REQUIRED, 'The maternal surname')
            ->addArgument('email', InputArgument::REQUIRED, 'The email')
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addArgument('password', InputArgument::REQUIRED, 'The password')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Set the staff as inactive')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $firstname = $input->getArgument('firstname');
        $paternalSurname = $input->getArgument('paternal_surname');
        $maternalSurname = $input->getArgument('maternal_surname');
        $email = $input->getArgument('email');
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $inactive = $input->getOption('inactive');

        $staff = new Staff();
        $staffProfile = new StaffProfile();

        $password = $this->passwordHasher->hashPassword($staff, $password);

        $staffProfile
            ->setFirstname($firstname)
            ->setPaternalSurname($paternalSurname)
            ->setMaternalSurname($maternalSurname)
        ;

        $staff
            ->setEmail($email)
            ->setUsername($username)
            ->setPassword($password)
            ->setRoles(['ROLE_ADMIN'])
            ->setProfile($staffProfile)
        ;

        if ($inactive) {
            $staff->setIsActive($inactive);
        }

        $this->em->persist($staff);
        $this->em->flush();

        return Command::SUCCESS;
    }
}
