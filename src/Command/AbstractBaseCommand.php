<?php

declare(strict_types=1);


use App\Exception\Console\InvalidParameterException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AbstractBaseCommand.
 */
abstract class AbstractBaseCommand extends Command
{
    private const DEFAULT_CURRENT_DATE_VALUE = 'now';
    private const CURRENT_DATETIME_OPTION = 'current-datetime';

    protected \DateTime $currentDateTime;

    /** {@inheritdoc} */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption(self::CURRENT_DATETIME_OPTION, 'd', InputOption::VALUE_OPTIONAL, 'Date in format "YYYY-MM-DD HH:MM"', self::DEFAULT_CURRENT_DATE_VALUE)
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $currentDateFromInput = $input->getOption(self::CURRENT_DATETIME_OPTION);

            if (!\is_string($currentDateFromInput)) {
                throw new InvalidParameterException(sprintf('Parameter `%s` is not a string', self::CURRENT_DATETIME_OPTION));
            }

            if (self::DEFAULT_CURRENT_DATE_VALUE !== $currentDateFromInput) {
                $dateTime = \DateTime::createFromFormat('Y-m-d H:i', $currentDateFromInput);
                if (false === $dateTime) {
                    throw new InvalidParameterException('Invalid date format. Correct format YYYY-MM-DD HH:MM, e.g. "2018-11-01 14:45"');
                }
                $this->currentDateTime = $dateTime;
            } else {
                $this->currentDateTime = new \DateTime('now', new \DateTimeZone('UTC'));
            }
        } catch (InvalidParameterException $e) {
            $io->write($e->getMessage(), false, SymfonyStyle::OUTPUT_RAW);

            throw $e;
        }
    }
}
