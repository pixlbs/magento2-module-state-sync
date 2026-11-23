<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use Magento\Framework\App\DeploymentConfig\Writer\PhpFormatter;
use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\ConfigFile;
use PixelLabs\ModuleStateSync\Exception\SequenceException;

class ConfigFileTest extends TestCase
{
    use TemporaryDirectory;

    private const CONFIG = [
        'scopes' => ['websites' => ['admin' => ['website_id' => '0', 'default_group_id' => null]]],
        'modules' => ['Acme_A' => 1, 'Acme_B' => 0],
        'system' => [
            'default' => ['a' => ['quote' => "it's \\ <b>", 'list' => ['x', 'y'], 'empty' => [], 'on' => true]],
        ],
        'numbers' => [5 => 1.5, 7 => -2],
    ];

    public function testOnlyModulesChange(): void
    {
        $file = $this->writeConfig();
        (new ConfigFile($file))->saveModules(['Acme_B' => 1, 'Acme_C' => 0]);

        self::assertSame(['Acme_B' => 1, 'Acme_C' => 0], (require $file)['modules']);
        self::assertSame(array_replace(self::CONFIG, ['modules' => ['Acme_B' => 1, 'Acme_C' => 0]]), require $file);
        self::assertSame([$file], glob($this->directory . '/*'));
    }

    public function testFormatMatchesMagento(): void
    {
        $file = $this->writeConfig();

        self::assertSame((new PhpFormatter())->format(self::CONFIG), (new ConfigFile($file))->format(self::CONFIG));
    }

    public function testSymlinkAndModeAreKept(): void
    {
        $target = $this->writeConfig('shared/config.php');
        chmod($target, 0o640);
        $link = $this->directory . '/config.php';
        symlink('shared/config.php', $link);

        (new ConfigFile($link))->saveModules(['Acme_C' => 1]);

        clearstatcache();
        self::assertTrue(is_link($link));
        self::assertSame(['Acme_C' => 1], (require $target)['modules']);
        self::assertSame(0o640, (int) fileperms($target) & 0o777);
        self::assertSame([$target], glob($this->directory . '/shared/*'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'nothing returned' => ['<?php'],
            'no modules' => ["<?php return ['system' => []];"],
            'empty modules' => ["<?php return ['modules' => []];"],
            'modules is not an array' => ["<?php return ['modules' => 1];"],
        ];
    }

    /**
     * @dataProvider invalidFiles
     */
    public function testFileWithoutModulesIsRefused(string $contents): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage('has no modules');

        new ConfigFile($this->writeFile('config.php', $contents));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage('does not exist');

        new ConfigFile($this->directory . '/config.php');
    }

    private function writeConfig(string $path = 'config.php'): string
    {
        return $this->writeFile($path, '<?php return ' . var_export(self::CONFIG, true) . ';');
    }
}
