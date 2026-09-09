<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RateUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'rate:update',
    description: 'Rate update command',
)]
class RateUpdateCommand extends Command
{
    public function __construct(
        private readonly RateUpdater $updater,
        #[Autowire(env: 'DEFAULT_BASE_CURRENCY')]
        private string $defaultBaseCurrency,
        #[Autowire(env: 'ALLOWED_BASE_CURRENCIES')]
        private string $allowedBaseCurrencies,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('base', InputArgument::OPTIONAL, 'Base currency code', $this->defaultBaseCurrency)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $baseCurrency = strtoupper($input->getArgument('base'));

        if (!in_array($baseCurrency, explode(',', $this->allowedBaseCurrencies), true)) {
            $io->error(sprintf('Base currency "%s" is not allowed. Allowed: %s.', $baseCurrency, $this->allowedBaseCurrencies));

            return Command::FAILURE;
        }

        try {
            $count = $this->updater->update($baseCurrency);
        } catch (\Throwable $exception) {
            $io->error('Rates were not updated: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Saved %d currency rates. Base: %s.', $count, $baseCurrency));

        return Command::SUCCESS;
    }
}
