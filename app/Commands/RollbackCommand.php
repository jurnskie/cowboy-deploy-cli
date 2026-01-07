<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class RollbackCommand extends Command
{
    protected $signature = 'rollback {--to= : Deployment number to rollback to}';
    protected $description = 'Rollback to a previous deployment';

    private array $config;
    private string $configPath;

    public function handle(): int
    {
        $this->configPath = getcwd() . '/.cowboy-deploy.json';

        if (!file_exists($this->configPath)) {
            $this->error('No deployment config found!');
            $this->line('Run <fg=green>cowboy init</> first to set up deployment.');
            return self::FAILURE;
        }

        $this->config = json_decode(file_get_contents($this->configPath), true);
        $history = $this->config['history'] ?? [];

        if (count($history) < 2) {
            $this->warn('Not enough deployment history to rollback!');
            $this->line('You need at least 2 deployments to rollback.');
            return self::FAILURE;
        }

        $this->info('🤠 Cowboy Deploy - Rollback');
        $this->newLine();

        // Get target deployment
        $target = $this->option('to');

        if (!$target) {
            // Show recent deployments and let user choose
            $options = [];
            $recentHistory = array_slice(array_reverse($history), 0, 5);
            
            foreach ($recentHistory as $index => $record) {
                $number = count($history) - $index;
                $timestamp = $record['timestamp'] ?? 'Unknown';
                $commit = $record['git_commit'] ?? 'no commit';
                $type = $record['type'] ?? 'unknown';
                
                // Skip the current (latest) deployment
                if ($index === 0) {
                    continue;
                }

                $options[$number] = "#{$number} - {$timestamp} ({$type}) [{$commit}]";
            }

            if (empty($options)) {
                $this->error('No previous deployments to rollback to!');
                return self::FAILURE;
            }

            $target = select(
                label: 'Which deployment to rollback to?',
                options: $options,
                hint: 'This will deploy the state from that deployment'
            );
        }

        $target = (int) $target;

        if ($target < 1 || $target > count($history)) {
            $this->error("Invalid deployment number: {$target}");
            return self::FAILURE;
        }

        $targetRecord = $history[$target - 1];
        $commit = $targetRecord['git_commit'] ?? null;

        $this->newLine();
        $this->line("Target deployment: <fg=cyan>#{$target}</>");
        $this->line("Timestamp: {$targetRecord['timestamp']}");
        
        if ($commit) {
            $this->line("Git commit: <fg=yellow>{$commit}</>");
        }

        $this->newLine();

        if (!$commit) {
            $this->error('Cannot rollback: target deployment has no git commit reference!');
            $this->line('Rollback requires git history. The deployment must have been made from a git repository.');
            return self::FAILURE;
        }

        if (!is_dir(getcwd() . '/.git')) {
            $this->error('Not a git repository!');
            $this->line('Rollback requires git. Please initialize a git repository first.');
            return self::FAILURE;
        }

        // Check for uncommitted changes
        $result = Process::run('git status --porcelain');
        if ($result->output()) {
            $this->warn('⚠️  You have uncommitted changes:');
            $this->line($result->output());
            
            if (!confirm('Stash changes and continue?', false)) {
                $this->error('Rollback cancelled.');
                return self::FAILURE;
            }

            // Stash changes
            Process::run('git stash push -m "Cowboy Deploy rollback stash"');
            $this->info('Changes stashed.');
        }

        if (!confirm("Rollback to deployment #{$target}?", false)) {
            $this->error('Rollback cancelled.');
            return self::FAILURE;
        }

        // Checkout the target commit
        $this->task('Checking out commit ' . $commit, function() use ($commit) {
            $result = Process::run("git checkout {$commit}");
            return $result->successful();
        });

        // Run the push command
        $this->newLine();
        $this->info('Deploying rolled-back state...');
        $this->newLine();

        $pushResult = Process::run('php ' . __DIR__ . '/../../cowboy push --full');
        
        if (!$pushResult->successful()) {
            $this->error('Deployment failed during rollback!');
            $this->line($pushResult->errorOutput());
            
            // Try to restore to previous state
            Process::run('git checkout -');
            
            return self::FAILURE;
        }

        $this->line($pushResult->output());

        // Return to previous branch/commit
        Process::run('git checkout -');

        $this->newLine();
        $this->info('✅ Rollback complete!');
        $this->line('Your local git state has been restored.');

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void
    {
        // Not needed
    }
}
