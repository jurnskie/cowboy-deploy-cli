<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Process;

class StatusCommand extends Command
{
    protected $signature = 'status';
    protected $description = 'Show current deployment status';

    public function handle(): int
    {
        $configPath = getcwd() . '/.cowboy-deploy.json';

        if (!file_exists($configPath)) {
            $this->error('No deployment config found!');
            $this->line('Run <fg=green>cowboy init</> first to set up deployment.');
            return self::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);

        $this->info('🤠 Cowboy Deploy - Status');
        $this->newLine();

        // Project Info
        $this->line('<fg=yellow>Project:</> ' . ($config['project']['name'] ?? 'Unknown'));
        $this->line('<fg=yellow>Type:</> ' . ($config['project']['type'] ?? 'Unknown'));
        $this->newLine();

        // FTP Info (without password)
        $this->line('<fg=yellow>FTP Host:</> ' . $config['ftp']['host']);
        $this->line('<fg=yellow>FTP Path:</> ' . $config['ftp']['path']);
        $this->line('<fg=yellow>FTP User:</> ' . $config['ftp']['username']);
        $this->line('<fg=yellow>Secure:</> ' . ($config['ftp']['secure'] ? 'Yes (FTPS)' : 'No'));
        $this->newLine();

        // Git Status
        if (is_dir(getcwd() . '/.git')) {
            $branch = trim(Process::run('git rev-parse --abbrev-ref HEAD')->output());
            $commit = trim(Process::run('git rev-parse --short HEAD')->output());
            $uncommitted = Process::run('git status --porcelain')->output();

            $this->line('<fg=yellow>Git Branch:</> ' . $branch);
            $this->line('<fg=yellow>Git Commit:</> ' . $commit);
            
            if ($uncommitted) {
                $this->line('<fg=red>Uncommitted Changes:</> Yes');
            } else {
                $this->line('<fg=green>Uncommitted Changes:</> No');
            }
        } else {
            $this->line('<fg=gray>Git:</> Not a repository');
        }

        $this->newLine();

        // Deployment History
        $history = $config['history'] ?? [];
        
        if (empty($history)) {
            $this->line('<fg=yellow>Deployments:</> None yet');
        } else {
            $latest = end($history);
            $this->line('<fg=yellow>Total Deployments:</> ' . count($history));
            $this->line('<fg=yellow>Last Deployment:</> ' . ($latest['timestamp'] ?? 'Unknown'));
            $this->line('<fg=yellow>Last Deploy Type:</> ' . ($latest['type'] ?? 'unknown'));
            
            if (isset($latest['git_commit'])) {
                $this->line('<fg=yellow>Last Deploy Commit:</> ' . $latest['git_commit']);
            }
        }

        $this->newLine();

        // Deployment Options
        $this->line('<fg=yellow>Build Assets:</> ' . ($config['deploy']['build_assets'] ? 'Yes' : 'No'));
        $this->line('<fg=yellow>Run Composer:</> ' . ($config['deploy']['run_composer'] ? 'Yes' : 'No'));

        return self::SUCCESS;
    }

    public function schedule(Schedule $schedule): void
    {
        // Not needed
    }
}
