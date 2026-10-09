<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Venuno\OrderImport\Model\Sync\OrderSyncEngine;
use Venuno\OrderImport\Model\Sync\OrderSyncService;
use Venuno\OrderImport\Model\Sync\SyncException;

/**
 * bin/magento venuno:orders:sync — bring already-imported orders up to date with the read-only legacy
 * store. Dry-run by default; --apply requires --expect=N equal to the number of orders with changes.
 *
 *   --discover=FILE                 write the frozen list of drifted legacy entity ids, then stop
 *   --orders-file=FILE              frozen list (one legacy entity_id per line, # comments)
 *   --report=FILE                   JSON-lines report, one object per order
 *   --apply --expect=N [--skip-failed]
 *   --rollback-batch=ID | --rollback-audit=ID
 */
class OrderSyncCommand extends Command
{
    public function __construct(private readonly OrderSyncService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('venuno:orders:sync')
            ->setDescription('Sync already-imported orders with the read-only legacy source (dry-run by default).')
            ->addOption('orders-file', null, InputOption::VALUE_REQUIRED, 'Frozen list of legacy entity ids')
            ->addOption('discover', null, InputOption::VALUE_REQUIRED, 'Write drifted legacy entity ids to this file and stop')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'JSON-lines report path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan only (default)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the plan')
            ->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Required with --apply: number of orders with changes')
            ->addOption('skip-failed', null, InputOption::VALUE_NONE, 'With --apply: proceed although some orders fail closed')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch id for the audit trail')
            ->addOption('rollback-batch', null, InputOption::VALUE_REQUIRED, 'Roll back every applied sync in a batch')
            ->addOption('rollback-audit', null, InputOption::VALUE_REQUIRED, 'Roll back one applied sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $engine = $this->service->engine();
        } catch (SyncException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('rollback-batch') || $input->getOption('rollback-audit')) {
            return $this->rollback($engine, $input, $output);
        }
        if ($path = $input->getOption('discover')) {
            $ids = $engine->discover();
            file_put_contents($path, '# venuno:orders:sync discovery ' . date('c') . "\n" . implode("\n", $ids) . "\n");
            $output->writeln(sprintf('Discovered %d drifted orders -> %s', count($ids), $path));
            return Command::SUCCESS;
        }
        $file = $input->getOption('orders-file');
        if (!$file || !is_readable($file)) {
            $output->writeln('<error>--orders-file is required (create one with --discover).</error>');
            return Command::FAILURE;
        }
        $ids = self::readIds((string) $file);
        $apply = (bool) $input->getOption('apply');
        $batch = (string) ($input->getOption('batch') ?: 'sync-' . date('Ymd-His'));

        // Always plan everything first; apply only if the plan matches --expect.
        $planned = [];
        foreach ($ids as $id) {
            $planned[$id] = $engine->sync($id, false, $batch, 'cli');
        }
        $changes = array_filter($planned, fn ($o) => $o->status === 'planned');
        $failed = array_filter($planned, fn ($o) => $o->status === 'failed');
        $final = $planned;
        if ($apply) {
            $expect = $input->getOption('expect');
            if ($expect === null || (int) $expect !== count($changes)) {
                $output->writeln(sprintf('<error>ABORT: --expect=%s but %d orders have changes. Nothing applied.</error>', $expect ?? '(missing)', count($changes)));
                return Command::FAILURE;
            }
            if ($failed && !$input->getOption('skip-failed')) {
                $output->writeln(sprintf('<error>ABORT: %d orders fail closed; review or pass --skip-failed. Nothing applied.</error>', count($failed)));
                return Command::FAILURE;
            }
            foreach (array_keys($changes) as $id) {
                $final[$id] = $engine->sync($id, true, $batch, 'cli');
            }
        }
        $this->report($final, $input->getOption('report'), $output, $apply ? $batch : null);
        $applyFailures = $apply ? count(array_filter($final, fn ($o) => $o->status === 'failed')) - count($failed) : 0;
        return $applyFailures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function rollback(OrderSyncEngine $engine, InputInterface $input, OutputInterface $output): int
    {
        $ids = $input->getOption('rollback-audit') ? [(int) $input->getOption('rollback-audit')] : $engine->auditIdsForBatch((string) $input->getOption('rollback-batch'));
        $failed = 0;
        foreach ($ids as $auditId) {
            try {
                $engine->rollback($auditId);
                $output->writeln('rolled back audit #' . $auditId);
            } catch (SyncException $e) {
                $failed++;
                $output->writeln(sprintf('<error>audit #%d NOT rolled back (%s): %s</error>', $auditId, $e->getReason(), $e->getMessage()));
            }
        }
        $output->writeln(sprintf('Rolled back %d of %d.', count($ids) - $failed, count($ids)));
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param array<int, \Venuno\OrderImport\Model\Sync\SyncOutcome> $outcomes */
    private function report(array $outcomes, mixed $path, OutputInterface $output, ?string $batch): void
    {
        $buckets = [];
        $statuses = [];
        $lines = [];
        foreach ($outcomes as $outcome) {
            $statuses[$outcome->status] = ($statuses[$outcome->status] ?? 0) + 1;
            foreach ($outcome->categories() as $category) {
                $buckets[$category] = ($buckets[$category] ?? 0) + 1;
            }
            $lines[] = json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($path) {
            file_put_contents((string) $path, implode("\n", $lines) . "\n");
        }
        ksort($buckets);
        $output->writeln(sprintf('orders=%d %s%s', count($outcomes), json_encode($statuses), $batch ? ' batch=' . $batch : ''));
        foreach ($buckets as $category => $n) {
            $output->writeln(sprintf('%5d  %s', $n, $category));
        }
    }

    /** @return list<int> */
    public static function readIds(string $file): array
    {
        $ids = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '') {
                continue;
            }
            if (!ctype_digit($line)) {
                throw new \InvalidArgumentException('Not a legacy entity id: ' . $line);
            }
            $ids[(int) $line] = true;
        }
        return array_keys($ids);
    }
}
