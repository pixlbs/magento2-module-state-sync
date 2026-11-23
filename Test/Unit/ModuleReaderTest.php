<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\Exception\SequenceException;
use PixelLabs\ModuleStateSync\ModuleReader;

class ModuleReaderTest extends TestCase
{
    use TemporaryDirectory;

    public function testRead(): void
    {
        $paths = [$this->writeModule('Acme_A', ['Acme_B', 'Magento_Store']), $this->writeModule('Acme_B')];

        self::assertSame(
            ['Acme_A' => ['Acme_B', 'Magento_Store'], 'Acme_B' => []],
            (new ModuleReader())->read($paths)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidXml(): array
    {
        return [
            'malformed' => ['<config>', 'Invalid XML in'],
            'no module' => ['<config/>', 'No module is declared in'],
            'module without name' => ['<config><module/></config>', 'Attribute "name" is required'],
            'sequence without name' => [
                '<config><module name="A_A"><sequence><module/></sequence></module></config>',
                'Attribute "name" is required',
            ],
        ];
    }

    /**
     * @dataProvider invalidXml
     */
    public function testInvalidXmlIsRefused(string $xml, string $message): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage($message);

        (new ModuleReader())->read([$this->writeModuleXml('a', $xml)]);
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage('Cannot read');

        (new ModuleReader())->read([$this->directory]);
    }
}
