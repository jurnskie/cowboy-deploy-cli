<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class LogCommand extends Command
{
    protected $signature = 'log {--limit=10 : Number of deployments to show}';
    protected $description = 'Show deployment history';

    public function handle(): int
    {
        $configPath = getcwd() . '/.cowboy-deploy.json';

        if (!file_exists($configPath)) {
            $this->error('No deployment config found!');
            $this->line('Run <fg=green>cowboy init</> first to set up deployment.');
            return self::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);
        $history = $config['history'] ?? [];

        if (empty($history)) {
            $this->warn('No deployments yet!');
            $this->line('Run <fg=green>cowboy push</> to deploy.');
            return self::SUCCESS;
        }

        $this->info('🤠 Deployment History');
        $this->newLine();

        $limit = (int) $this->option('limit');
        $history = array_slice(array_reverse($history), 0, $limit);

        foreach ($history as $index => $record) {
            $number = count($config['history']) - $index;
            $timestamp = $record['timestamp'] ?? 'Unknown';
            $user = $record['user'] ?? 'Unknown';
            $type = $record['type'] ?? 'unknown';
            $commit = $record['git_commit'] ?? null;

            $typeColor = match($type) {
                'full' => 'red',
                'incremental' => 'green',
                default => 'yellow',
            };

            $this->line("#{$number} <fg={$typeColor}>[{$type}]</> <fg=cyan>{$timestamp}</>");
            $this->line("    User: {$user}");
            
            if ($commit) {
                $this->line("    Commit: <fg=yellow>{$commit}</>");
            }

            $this->newLine();
        }

        if (count($config['history']) > $limit) {
            $remaining = count($config['history']) - $limit;
            $this->line("<fg=gray>... and {$remaining} more. Use --limit={$remaining} to see all</>");
        }

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void
    {
        // Not needed
    }
}
