<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends Command
{
    protected const SECTION_SEPARATOR_LENGTH = 40;

    protected InputInterface $input;
    protected OutputInterface $output;

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;

        $this->msgSection($this->getName(), true);
        $this->msg('Starting process...');
    }

    /**
     * Print message.
     *
     * @param string $message
     */
    protected function msg(string $message): void
    {
        $this->output->writeln('<comment>['.date('Y-m-d H:i:s').']</comment> '.$message);
    }

    /**
     * Print message errors.
     *
     * @param string $message
     */
    protected function msgError(string $message): void
    {
        $message = '<error>'.$message.'</error>';
        $this->msg($message);
    }

    /**
     * Print info msg.
     *
     * @param string $message
     */
    protected function msgInfo(string $message): void
    {
        $message = '<info>'.$message.'</info>';
        $this->msg($message);
    }

    /**
     * Print message.
     *
     * @param string $message
     * @param bool   $lineBreak
     */
    protected function msgSection(string $message, bool $lineBreak = false): void
    {
        $length = strlen($message);

        if ($length < static::SECTION_SEPARATOR_LENGTH) {
            $length = static::SECTION_SEPARATOR_LENGTH;
        }

        $separator = str_repeat('=', $length);
        $msg = [$separator, $message, $separator];

        if ($lineBreak) {
            $msg[] = '';
        }

        $this->output->writeln($msg);
    }
}
