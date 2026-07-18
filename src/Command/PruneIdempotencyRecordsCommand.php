<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\IdempotencyRecordRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:idempotency:prune',
    description: 'Delete expired API idempotency records in bounded batches.',
)]
final class PruneIdempotencyRecordsCommand extends Command
{
    public function __construct(private readonly IdempotencyRecordRepository $records)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per transaction (1-10000).', '1000')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Maximum transactions per run (1-1000).', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = $this->positiveIntegerOption($input, $io, 'batch-size', 10000);
        $maxBatches = $this->positiveIntegerOption($input, $io, 'max-batches', 1000);
        if ($batchSize === null || $maxBatches === null) {
            return Command::INVALID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $totalDeleted = 0;
        $lastBatchSize = 0;
        $batchesRun = 0;

        for ($batch = 1; $batch <= $maxBatches; ++$batch) {
            $lastBatchSize = $this->records->deleteExpired($now, $batchSize);
            ++$batchesRun;
            $totalDeleted += $lastBatchSize;

            if ($lastBatchSize < $batchSize) {
                break;
            }
        }

        $io->success(sprintf(
            'Pruned %d expired idempotency record(s) in %d batch(es).',
            $totalDeleted,
            $batchesRun,
        ));
        if ($batchesRun === $maxBatches && $lastBatchSize === $batchSize) {
            $io->note('Additional expired records may remain; the next scheduled run will continue cleanup.');
        }

        return Command::SUCCESS;
    }

    private function positiveIntegerOption(
        InputInterface $input,
        SymfonyStyle $io,
        string $name,
        int $maximum,
    ): ?int {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value)) {
            $io->error(sprintf('--%s must be an integer between 1 and %d.', $name, $maximum));

            return null;
        }

        $value = (int) $value;
        if ($value < 1 || $value > $maximum) {
            $io->error(sprintf('--%s must be an integer between 1 and %d.', $name, $maximum));

            return null;
        }

        return $value;
    }
}
