<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\error;

class PushCommand extends Command
{
    protected $signature = 'push {--full : Upload all files instead of just changes} {--dry-run : Show what would be deployed without actually deploying}';
    protected $description = 'Deploy project to FTP server';

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

        $this->info('🤠 Cowboy Deploy - ' . ($this->option('dry-run') ? 'DRY RUN' : 'Pushing to production'));
        $this->newLine();

        // Pre-deployment checks (BEFORE building assets to avoid false positives)
        if (!$this->preDeploymentChecks()) {
            return self::FAILURE;
        }

        // Stash any uncommitted changes temporarily
        $needsStash = $this->stashChangesIfNeeded();

        // Build assets if configured
        if ($this->config['deploy']['build_assets'] && !$this->option('dry-run')) {
            $success = $this->task('Building assets', fn() => $this->buildAssets());
            
            if (!$success) {
                $this->warn('⚠️  Asset build failed, continuing deployment anyway...');
            }
        }

        // Run composer if configured
        if ($this->config['deploy']['run_composer'] && !$this->option('dry-run')) {
            if ($this->hasComposerChanges()) {
                $this->task('Running composer install', fn() => $this->runComposer());
            }
        }

        // Deploy files
        $deployed = $this->deployFiles();

        if (!$deployed) {
            return self::FAILURE;
        }

        // Save deployment record
        if (!$this->option('dry-run')) {
            $this->saveDeploymentRecord();
        }

        // Restore stashed changes if we stashed them
        if ($needsStash && !$this->option('dry-run')) {
            $this->newLine();
            $popResult = Process::run('git stash pop');
            if ($popResult->successful()) {
                $this->info('✓ Stashed changes restored');
            } else {
                $this->warn('⚠️  Could not restore stashed changes. Run: git stash pop');
            }
        }

        $this->newLine();
        $this->info('✅ Deployment ' . ($this->option('dry-run') ? 'preview' : 'complete') . '!');

        return self::SUCCESS;
    }

    private function preDeploymentChecks(): bool
    {
        // Check if git repo
        if (!is_dir(getcwd() . '/.git')) {
            $this->warn('⚠️  Not a git repository. Consider initializing git for better deployment tracking.');
        }

        // Verify FTP connection
        $this->task('Testing FTP connection', function() {
            return $this->testFtpConnection();
        });

        return true;
    }

    private function stashChangesIfNeeded(): bool
    {
        if (!is_dir(getcwd() . '/.git')) {
            return false;
        }

        // Check for uncommitted changes (excluding build artifacts)
        $result = Process::run('git status --porcelain');
        $changes = $result->output();
        
        if (!$changes) {
            return false; // No changes
        }

        // Filter out common build artifact paths
        $lines = explode("\n", trim($changes));
        $relevantChanges = array_filter($lines, function($line) {
            // Ignore common build output directories
            return !preg_match('#(public/build|public/js|public/css|public/mix-manifest|dist/)#', $line);
        });

        if (empty($relevantChanges)) {
            // Only build artifacts changed, that's fine
            return false;
        }

        // We have real uncommitted changes
        $this->warn('⚠️  You have uncommitted changes:');
        foreach ($relevantChanges as $line) {
            $this->line($line);
        }
        
        if ($this->option('dry-run')) {
            return false;
        }

        if (!confirm('Stash changes and continue deployment?', false)) {
            $this->error('Deployment cancelled.');
            exit(self::FAILURE);
        }

        // Stash changes
        Process::run('git stash push -m "Cowboy Deploy auto-stash ' . date('Y-m-d H:i:s') . '"');
        $this->info('✓ Changes stashed');

        return true;
    }

    private function testFtpConnection(): bool
    {
        $ftp = $this->config['ftp'];
        
        $protocol = $ftp['secure'] ? 'ftps' : 'ftp';
        $url = "{$protocol}://{$ftp['username']}:{$ftp['password']}@{$ftp['host']}:{$ftp['port']}{$ftp['path']}";

        // Use curl to test connection
        $result = Process::run("curl -s --connect-timeout 10 --list-only '{$url}' 2>&1");
        
        return $result->successful();
    }

    private function buildAssets(): bool
    {
        if (!file_exists(getcwd() . '/package.json')) {
            return true; // No package.json, skip
        }

        // Check if node_modules exists
        if (!is_dir(getcwd() . '/node_modules')) {
            // Try to install dependencies first
            $installResult = Process::run('npm install');
            if (!$installResult->successful()) {
                return false; // Can't install, can't build
            }
        }

        $result = Process::run('npm run build');
        return $result->successful();
    }

    private function hasComposerChanges(): bool
    {
        if (!is_dir(getcwd() . '/.git')) {
            return true; // Not a git repo, assume changes
        }

        $result = Process::run('git diff HEAD composer.lock');
        return !empty($result->output());
    }

    private function runComposer(): bool
    {
        $result = Process::run('composer install --no-dev --optimize-autoloader');
        return $result->successful();
    }

    private function deployFiles(): bool
    {
        $ftp = $this->config['ftp'];
        $excluded = $this->config['deploy']['excluded_paths'];

        $this->line('Deploying to: <fg=yellow>' . $ftp['host'] . $ftp['path'] . '</>');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No files will be uploaded');
            $this->line('Would deploy from: ' . getcwd());
            $this->line('Excluded paths: ' . implode(', ', $excluded));
            return true;
        }

        // Check if git-ftp is available
        $hasGitFtp = Process::run('which git-ftp')->successful();

        if ($hasGitFtp && is_dir(getcwd() . '/.git')) {
            return $this->deployWithGitFtp();
        }

        return $this->deployWithNcftp();
    }

    private function deployWithGitFtp(): bool
    {
        $ftp = $this->config['ftp'];
        
        // Initialize git-ftp config
        Process::run("git config git-ftp.url ftp://{$ftp['host']}:{$ftp['port']}{$ftp['path']}");
        Process::run("git config git-ftp.user {$ftp['username']}");
        Process::run("git config git-ftp.password {$ftp['password']}");

        // Set excluded paths
        $excludeFile = getcwd() . '/.git-ftp-ignore';
        file_put_contents($excludeFile, implode("\n", $this->config['deploy']['excluded_paths']));

        // If we have built assets, commit them temporarily for git-ftp
        $tempCommitNeeded = false;
        $statusResult = Process::run('git status --porcelain');
        if ($statusResult->output()) {
            $this->info('Temporarily committing build artifacts for deployment...');
            $addResult = Process::run('git add -A');
            $commitResult = Process::run('git commit -m "temp: build artifacts for deployment [cowboy-deploy]"');
            
            if ($commitResult->successful()) {
                $tempCommitNeeded = true;
            } else {
                $this->warn('Could not create temp commit, may have issues...');
            }
        }

        // Check if this is the first deployment (git-ftp needs init)
        $isFirstDeploy = empty($this->config['history']) || $this->option('full');
        
        if ($isFirstDeploy) {
            $this->info('First deployment detected - initializing git-ftp...');
            $this->warn('⏳ This may take a while for the first upload...');
            $command = 'git ftp init';
        } else {
            $this->info('Using git-ftp for incremental deployment...');
            $command = 'git ftp push';
        }
        
        // Run with extended timeout (1 hour for large deployments)
        $result = Process::timeout(3600)->run($command);
        
        // Remove temp commit AFTER deployment
        if ($tempCommitNeeded) {
            $resetResult = Process::run('git reset --soft HEAD~1');
            if ($resetResult->successful()) {
                $this->info('✓ Temporary commit removed');
            }
        }
        
        if ($result->successful()) {
            $this->line($result->output());
            return true;
        }

        // If push failed, might need init
        if (!$isFirstDeploy && str_contains($result->errorOutput(), 'git ftp init')) {
            $this->warn('Remote state missing, running git ftp init...');
            $this->warn('⏳ This may take a while...');
            $result = Process::timeout(3600)->run('git ftp init');
            
            if ($result->successful()) {
                $this->line($result->output());
                return true;
            }
        }

        $this->error('git-ftp failed:');
        $this->line($result->errorOutput());
        
        if ($result->output()) {
            $this->line('Output:');
            $this->line($result->output());
        }
        
        $this->newLine();
        $this->warn('💡 Troubleshooting tips:');
        $this->line('  • Check FTP credentials are correct');
        $this->line('  • Verify remote path exists and is writable');
        $this->line('  • Try: cowboy push --full (force re-init)');
        $this->line('  • Check git-ftp verbose: git ftp push -vv');
        
        return false;
    }

    private function deployWithNcftp(): bool
    {
        $ftp = $this->config['ftp'];
        
        $this->info('Using ncftpput for deployment...');
        $this->warn('⚠️  Full upload mode (install git-ftp for incremental deploys)');

        // Build ncftpput command
        $excludes = array_map(fn($path) => "-X '{$path}'", $this->config['deploy']['excluded_paths']);
        $excludeStr = implode(' ', $excludes);

        $command = sprintf(
            "ncftpput -R -v -u '%s' -p '%s' %s %s %s .",
            $ftp['username'],
            $ftp['password'],
            $excludeStr,
            $ftp['host'],
            $ftp['path']
        );

        $result = Process::run($command);

        if ($result->successful()) {
            return true;
        }

        $this->error('ncftpput failed:');
        $this->line($result->errorOutput());
        return false;
    }

    private function saveDeploymentRecord(): void
    {
        $record = [
            'timestamp' => now()->toIso8601String(),
            'user' => get_current_user(),
            'type' => $this->option('full') ? 'full' : 'incremental',
            'git_commit' => $this->getCurrentGitCommit(),
        ];

        $this->config['history'][] = $record;

        // Keep only last 20 deployments
        $this->config['history'] = array_slice($this->config['history'], -20);

        file_put_contents(
            $this->configPath,
            json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function getCurrentGitCommit(): ?string
    {
        if (!is_dir(getcwd() . '/.git')) {
            return null;
        }

        $result = Process::run('git rev-parse --short HEAD');
        return $result->successful() ? trim($result->output()) : null;
    }

    public function schedule(Schedule $schedule): void
    {
        // Not needed for this command
    }
}
