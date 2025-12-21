<?php

namespace Dancycodes\Gale\Console;

use Illuminate\Console\Command;

/**
 * Install Gale assets and configuration
 *
 * This command publishes the Gale JavaScript bundle to the application's public
 * directory and provides setup instructions for integrating Gale into Blade templates.
 *
 * Gale bundles Alpine.js (v3) with the Morph plugin, so users should remove any
 * existing Alpine.js installation to prevent conflicts.
 */
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gale:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Gale assets and configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing Gale...');
        $this->newLine();

        // Publish assets
        $this->callSilent('vendor:publish', [
            '--tag' => 'gale-assets',
            '--force' => true,
        ]);

        $this->components->task('Publishing Gale assets', fn() => true);

        $this->newLine();
        $this->components->info('Gale installed successfully!');
        $this->newLine();

        $this->line('  Add <comment>@gale</comment> to your layout\'s <comment><head></comment>:');
        $this->newLine();
        $this->line('    <fg=gray><head></>');
        $this->line('        <fg=yellow>@gale</>');
        $this->line('    <fg=gray></head></>');
        $this->newLine();

        $this->components->warn('If you have existing Alpine.js, remove it. Gale includes Alpine.js v3 + Morph.');

        $this->newLine();
        $this->line('  <fg=gray>Read the docs:</> <href=https://dancycodes.com/gale>https://dancycodes.com/gale</>');

        return Command::SUCCESS;
    }
}
