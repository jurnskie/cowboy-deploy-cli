<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

class InitCommand extends Command
{
    protected $signature = 'init {--force : Overwrite existing config}';
    protected $description = 'Initialize deployment configuration for this project';

    public function handle(): int
    {
        $configPath = getcwd() . '/.cowboy-deploy.json';

        if (file_exists($configPath) && !$this->option('force')) {
            $this->error('Deployment config already exists!');
            $this->line('Use --force to overwrite or edit .cowboy-deploy.json manually.');
            return self::FAILURE;
        }

        $this->info('🤠 Cowboy Deploy - Project Setup');
        $this->newLine();

        // Detect project type
        $projectType = $this->detectProjectType();
        $this->line("Detected: <fg=yellow>{$projectType}</>");
        $this->newLine();

        // Load existing environment/config values
        $defaults = $this->loadDefaults();

        if (!empty($defaults)) {
            $this->info('📋 Found existing config values:');
            foreach ($defaults as $key => $value) {
                if ($key !== 'password') {
                    $this->line("  • {$key}: <fg=cyan>{$value}</>");
                }
            }
            $this->newLine();
        }

        // FTP Configuration with smart defaults
        $ftpHost = text(
            label: 'FTP Host',
            placeholder: 'ftp.example.com',
            default: $defaults['host'] ?? '',
            required: true
        );

        $ftpPort = text(
            label: 'FTP Port',
            default: $defaults['port'] ?? '21',
            required: true
        );

        $ftpUsername = text(
            label: 'FTP Username',
            default: $defaults['username'] ?? '',
            required: true
        );

        $ftpPassword = password(
            label: 'FTP Password',
            required: true,
            hint: !empty($defaults['password']) ? 'Using password from .env' : ''
        );
        
        // Use default password if user just pressed enter
        if (empty($ftpPassword) && !empty($defaults['password'])) {
            $ftpPassword = $defaults['password'];
        }

        $ftpPath = text(
            label: 'Remote Path',
            placeholder: '/public_html',
            default: $defaults['path'] ?? '/',
            required: true
        );

        $ftpSecure = confirm(
            label: 'Use FTPS (secure FTP)?',
            default: $defaults['secure'] ?? false
        );

        // Project Configuration
        $buildAssets = confirm(
            label: 'Build assets before deployment? (npm run build)',
            default: true
        );

        $runComposer = confirm(
            label: 'Run composer install on changes?',
            default: true
        );

        // Build config array
        $config = [
            'project' => [
                'type' => $projectType,
                'name' => basename(getcwd()),
            ],
            'ftp' => [
                'host' => $ftpHost,
                'port' => (int) $ftpPort,
                'username' => $ftpUsername,
                'password' => $ftpPassword,
                'path' => $ftpPath,
                'secure' => $ftpSecure,
            ],
            'deploy' => [
                'build_assets' => $buildAssets,
                'run_composer' => $runComposer,
                'excluded_paths' => [
                    '.git',
                    '.github',
                    'node_modules',
                    'vendor',
                    'tests',
                    '.env',
                    '.env.example',
                    '.cowboy-deploy.json',
                    'storage/logs',
                    'storage/framework/cache',
                    'storage/framework/sessions',
                    'storage/framework/views',
                ],
            ],
            'history' => [],
        ];

        // Save config
        file_put_contents(
            $configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->newLine();
        $this->info('✅ Configuration saved to .cowboy-deploy.json');
        $this->line('⚠️  Add .cowboy-deploy.json to .gitignore (contains FTP credentials)');
        $this->newLine();

        // Add to gitignore
        $this->addToGitignore('.cowboy-deploy.json');

        $this->line('Ready to deploy! Run: <fg=green>cowboy push</>');

        return self::SUCCESS;
    }

    private function loadDefaults(): array
    {
        $defaults = [];
        $cwd = getcwd();

        // Try .env file first (Laravel/Statamic convention)
        $envFile = $cwd . '/.env';
        if (file_exists($envFile)) {
            $env = $this->parseEnvFile($envFile);
            
            // Common FTP env variables
            $defaults['host'] = $env['FTP_HOST'] ?? $env['DEPLOY_FTP_HOST'] ?? '';
            $defaults['port'] = $env['FTP_PORT'] ?? $env['DEPLOY_FTP_PORT'] ?? '21';
            $defaults['username'] = $env['FTP_USER'] ?? $env['DEPLOY_FTP_USER'] ?? $env['FTP_USERNAME'] ?? '';
            $defaults['password'] = $env['FTP_PASSWORD'] ?? $env['DEPLOY_FTP_PASSWORD'] ?? $env['FTP_PASS'] ?? '';
            $defaults['path'] = $env['FTP_PATH'] ?? $env['DEPLOY_FTP_PATH'] ?? '/';
            $defaults['secure'] = ($env['FTP_SECURE'] ?? $env['FTP_SSL'] ?? 'false') === 'true';
        }

        // Try deploy.php (Deployer convention)
        $deployFile = $cwd . '/deploy.php';
        if (file_exists($deployFile)) {
            $content = file_get_contents($deployFile);
            
            if (preg_match("/host\('([^']+)'\)/", $content, $match)) {
                $defaults['host'] = $defaults['host'] ?: $match[1];
            }
            if (preg_match("/user\('([^']+)'\)/", $content, $match)) {
                $defaults['username'] = $defaults['username'] ?: $match[1];
            }
        }

        // Try .ftpconfig (common FTP client config)
        $ftpConfig = $cwd . '/.ftpconfig';
        if (file_exists($ftpConfig)) {
            $config = json_decode(file_get_contents($ftpConfig), true);
            if ($config) {
                $defaults['host'] = $defaults['host'] ?: ($config['host'] ?? '');
                $defaults['port'] = $defaults['port'] ?: ($config['port'] ?? '21');
                $defaults['username'] = $defaults['username'] ?: ($config['user'] ?? '');
                $defaults['password'] = $defaults['password'] ?: ($config['pass'] ?? '');
                $defaults['path'] = $defaults['path'] ?: ($config['remotePath'] ?? '/');
                $defaults['secure'] = $defaults['secure'] ?: ($config['secure'] ?? false);
            }
        }

        return array_filter($defaults);
    }

    private function parseEnvFile(string $path): array
    {
        $env = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes
                $value = trim($value, '"\'');

                $env[$key] = $value;
            }
        }

        return $env;
    }

    private function detectProjectType(): string
    {
        $cwd = getcwd();

        if (file_exists($cwd . '/composer.json')) {
            $composer = json_decode(file_get_contents($cwd . '/composer.json'), true);

            if (isset($composer['require']['statamic/cms'])) {
                return 'statamic';
            }

            if (isset($composer['require']['laravel/framework'])) {
                return 'laravel';
            }

            return 'php-composer';
        }

        if (file_exists($cwd . '/package.json')) {
            return 'node';
        }

        return 'generic';
    }

    private function addToGitignore(string $entry): void
    {
        $gitignorePath = getcwd() . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            file_put_contents($gitignorePath, $entry . PHP_EOL);
            $this->line('Created .gitignore');
            return;
        }

        $content = file_get_contents($gitignorePath);

        if (str_contains($content, $entry)) {
            $this->line('.gitignore already contains ' . $entry);
            return;
        }

        file_put_contents($gitignorePath, $content . PHP_EOL . $entry . PHP_EOL);
        $this->line('Added to .gitignore');
    }

    public function schedule(Schedule $schedule): void
    {
        // Not needed for this command
    }
}
