<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/module-state-sync as a subprocess, on a project with three modules and Magento's real registrar.
 */
class CommandTest extends TestCase
{
    use TemporaryDirectory {
        setUp as private createDirectory;
    }

    private const BIN = __DIR__ . '/../../bin/module-state-sync';

    protected function setUp(): void
    {
        $this->createDirectory();

        $code = "<?php\nrequire_once " . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n";
        foreach (['Acme_A' => ['Acme_B'], 'Acme_B' => [], 'Acme_C' => []] as $name => $sequence) {
            $path = $this->writeModule($name, $sequence);
            $code .= "Magento\\Framework\\Component\\ComponentRegistrar::register('module', '$name', '$path');\n";
        }
        $this->writeFile('vendor/autoload.php', $code);
        $this->writeConfig("['Acme_A' => 1, 'Acme_B' => 0, 'Gone_G' => 1]");
    }

    public function testUpdate(): void
    {
        $this->enforce("['enabled' => ['Acme_B'], 'disabled' => ['Acme_C*', 'Dev_*']]");

        [$code, $output] = $this->runCommand();

        self::assertSame(0, $code, $output);
        self::assertSame(['Acme_B' => 1, 'Acme_A' => 1, 'Acme_C' => 0], $this->modules());
        self::assertSame(
            [
                'Enforced as disabled, not installed: Dev_*',
                'Enabled: Acme_B (enforced by Acme_B)',
                'Added, disabled: Acme_C (enforced by Acme_C*)',
                'Removed, not installed: Gone_G',
                'The module sequence changed.',
                'The module sequence is updated.',
            ],
            explode("\n", trim($output))
        );
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->runCommand();
        $before = file_get_contents($this->configFile());

        [$code, $output] = $this->runCommand('--dry-run');

        self::assertSame(0, $code);
        self::assertStringContainsString('up to date', $output);
        self::assertSame($before, file_get_contents($this->configFile()));
    }

    public function testDryRunWritesNothing(): void
    {
        $before = file_get_contents($this->configFile());

        [$code, $output] = $this->runCommand('--dry-run');

        self::assertSame(2, $code);
        self::assertStringContainsString('Added, enabled: Acme_C', $output);
        self::assertSame($before, file_get_contents($this->configFile()));
    }

    public function testMissingEnforcedModuleFailsWithoutWriting(): void
    {
        $this->enforce("['enabled' => ['Acme_B', 'Acme_Typo']]");
        $before = file_get_contents($this->configFile());

        [$code, $output] = $this->runCommand();

        self::assertSame(1, $code);
        self::assertStringContainsString('not installed: Acme_Typo', $output);
        self::assertSame($before, file_get_contents($this->configFile()));
    }

    public function testConfigAndEnforcedFilesCanBeElsewhere(): void
    {
        $before = file_get_contents($this->configFile());
        $config = $this->writeFile('other/config.php', "<?php return ['modules' => ['Acme_A' => 1]];");
        $enforced = $this->writeFile('other/enforced.php', "<?php return ['disabled' => ['Acme_A']];");

        [$code] = $this->runCommand("--config=$config", "--enforced=$enforced");

        self::assertSame(0, $code);
        self::assertSame(['Acme_B' => 1, 'Acme_A' => 0, 'Acme_C' => 1], (require $config)['modules']);
        self::assertSame($before, file_get_contents($this->configFile()));
    }

    public function testVendorDirectoryCanBeElsewhere(): void
    {
        rename($this->directory . '/vendor', $this->directory . '/libraries');
        $this->writeFile('app/etc/vendor_path.php', "<?php return './libraries';");

        self::assertSame(0, $this->runCommand()[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'unknown option' => ['--nope', 'Unknown argument: --nope'],
            'option without value' => ['--config=', 'Unknown argument: --config='],
            'missing config' => ['--config=/nonexistent/config.php', '/nonexistent/config.php does not exist'],
            'missing enforced file' => ['--enforced=/nonexistent/e.php', '/nonexistent/e.php does not exist'],
            'missing root' => ['--root=/nonexistent', '/nonexistent/vendor/autoload.php does not exist'],
        ];
    }

    /**
     * @dataProvider invalidArguments
     */
    public function testInvalidArgumentsFail(string $argument, string $message): void
    {
        [$code, $output] = $this->runCommand($argument);

        self::assertSame(1, $code);
        self::assertStringContainsString($message, $output);
    }

    public function testHelp(): void
    {
        [$code, $output] = $this->runCommand('--help');

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage: module-state-sync', $output);
    }

    /**
     * @return array{int, string} exit code and output
     */
    private function runCommand(string ...$arguments): array
    {
        $command = array_map('escapeshellarg', [PHP_BINARY, self::BIN, "--root={$this->directory}", ...$arguments]);
        // phpcs:ignore Magento2.Security.InsecureFunction
        exec(implode(' ', $command) . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function configFile(): string
    {
        return $this->directory . '/app/etc/config.php';
    }

    /**
     * @return array<string, int>
     */
    private function modules(): array
    {
        return (require $this->configFile())['modules'];
    }

    private function writeConfig(string $modules): void
    {
        $this->writeFile('app/etc/config.php', "<?php return ['modules' => $modules];");
    }

    private function enforce(string $modules): void
    {
        $this->writeFile('app/etc/enforced-modules.php', "<?php return $modules;");
    }
}
