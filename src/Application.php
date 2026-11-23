<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use Composer\InstalledVersions;
use LogicException;
use Magento\Framework\Component\ComponentRegistrar;
use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * The module-state-sync command.
 */
class Application
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_UPDATE_NEEDED = 2;

    private const CONFIG_FILE = 'app/etc/config.php';
    private const ENFORCED_FILE = 'app/etc/enforced-modules.php';

    private const HELP = <<<'TEXT'
        Updates the module sequence in app/etc/config.php and applies the states in app/etc/enforced-modules.php.

        Usage: module-state-sync [options]

          --root=<dir>       Magento root (default: the Composer project this is installed in)
          --config=<file>    config.php to update (default: <root>/app/etc/config.php)
          --enforced=<file>  enforced states (default: <root>/app/etc/enforced-modules.php, if it exists)
          --dry-run          Only show the changes; exit code 2 when there are any
          -h, --help         Show this help
        TEXT;

    /**
     * Set up the command.
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(private $stdout, private $stderr)
    {
    }

    /**
     * Run the command.
     *
     * @param list<string> $arguments
     * @param string $autoload the project's Composer autoloader, used without --root
     */
    public function run(array $arguments, string $autoload): int
    {
        try {
            $options = $this->parse($arguments);
            if (isset($options['help'])) {
                $this->write($this->stdout, self::HELP);
                return self::EXIT_OK;
            }

            return $this->update($options, $this->loadProject($options['root'] ?? null, $autoload));
        } catch (SequenceException | LogicException $exception) {
            $this->write($this->stderr, $exception->getMessage());
            return self::EXIT_ERROR;
        }
    }

    /**
     * Update config.php.
     *
     * @param array<string,string> $options
     * @param string $root
     */
    private function update(array $options, string $root): int
    {
        $config = new ConfigFile($options['config'] ?? $root . '/' . self::CONFIG_FILE);
        $rules = $this->loadEnforced($options['enforced'] ?? null, $root);

        $before = $config->getModules();
        $installed = (new ModuleReader())->read((new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE));
        $names = array_keys($installed);
        $enforced = $rules->resolve($names);
        $after = (new SequenceUpdater(new ModuleSorter()))->update($before, $installed, $enforced);

        foreach ($rules->unmatchedDisabled($names) as $entry) {
            $this->write($this->stdout, "Enforced as disabled, not installed: $entry");
        }
        foreach ((new ModuleChanges())->describe($before, $after, $enforced) as $line) {
            $this->write($this->stdout, $line);
        }

        if ($before === $after) {
            $this->write($this->stdout, 'The module sequence is up to date.');
            return self::EXIT_OK;
        }
        if (isset($options['dry-run'])) {
            $this->write($this->stdout, 'An update is needed.');
            return self::EXIT_UPDATE_NEEDED;
        }

        $config->saveModules($after);
        $this->write($this->stdout, 'The module sequence is updated.');

        return self::EXIT_OK;
    }

    /**
     * Load the project's autoloader, which registers all of its modules, and return the project's root.
     *
     * @param string|null $root
     * @param string $autoload
     */
    private function loadProject(?string $root, string $autoload): string
    {
        if ($root !== null) {
            $vendorPath = $root . '/app/etc/vendor_path.php';
            // phpcs:ignore Magento2.Security.IncludeFile
            $vendor = is_file($vendorPath) ? (static fn (): mixed => require $vendorPath)() : 'vendor';
            $vendor = \is_string($vendor) ? $vendor : 'vendor';
            $autoload = (str_starts_with($vendor, '/') ? $vendor : $root . '/' . $vendor) . '/autoload.php';
        }
        if (!is_file($autoload)) {
            throw new SequenceException("$autoload does not exist; run composer install, or set --root.");
        }
        // phpcs:ignore Magento2.Security.IncludeFile
        (static fn (): mixed => require_once $autoload)();

        if (!class_exists(ComponentRegistrar::class)) {
            throw new SequenceException("No Magento installation is loaded by $autoload.");
        }

        if ($root !== null) {
            return $root;
        }
        $path = InstalledVersions::getRootPackage()['install_path'];

        return realpath($path) ?: $path;
    }

    /**
     * Load the enforced module states; none without a file.
     *
     * @param string|null $file
     * @param string $root
     */
    private function loadEnforced(?string $file, string $root): EnforcedRules
    {
        $path = $file ?? $root . '/' . self::ENFORCED_FILE;
        if (is_file($path)) {
            return (new EnforcedModules())->load($path);
        }
        if ($file !== null) {
            throw new SequenceException("$file does not exist.");
        }

        return new EnforcedRules();
    }

    /**
     * Parse the command line.
     *
     * @param list<string> $arguments
     * @return array<string,string>
     */
    private function parse(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--(root|config|enforced)=(.+)$/s', $argument, $match)) {
                $options[$match[1]] = $match[1] === 'root' ? (rtrim($match[2], '/') ?: '/') : $match[2];
            } elseif ($argument === '--dry-run') {
                $options['dry-run'] = '1';
            } elseif ($argument === '--help' || $argument === '-h') {
                $options['help'] = '1';
            } else {
                throw new SequenceException("Unknown argument: $argument\n\n" . self::HELP);
            }
        }

        return $options;
    }

    /**
     * Write a line.
     *
     * @param resource $stream
     * @param string $line
     */
    private function write($stream, string $line): void
    {
        fwrite($stream, $line . PHP_EOL);
    }
}
