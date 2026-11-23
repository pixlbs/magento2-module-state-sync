<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use Exception;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Declaration\Converter\Dom;
use Magento\Framework\Module\ModuleList\Loader;
use Magento\Framework\Xml\Parser;
use Magento\Framework\Xml\ParserFactory;
use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\ModuleReader;
use PixelLabs\ModuleStateSync\ModuleSorter;
use ReflectionMethod;

/**
 * The same module.xml files go through Magento's own loader and through this package's reader and sorter: both must
 * come up with exactly the same order.
 *
 * Order matters down to modules that don't depend on each other, because config.php would otherwise change whenever
 * setup:upgrade and this package take turns.
 */
class MagentoParityTest extends TestCase
{
    use TemporaryDirectory;

    private const RANDOM_GRAPHS = 200;

    /**
     * @return array<string, array{array<string, string[]>}>
     */
    public static function moduleGraphs(): array
    {
        return [
            'one module' => [['Acme_A' => []]],
            'no sequence' => [['Zeta_Z' => [], 'Acme_A' => [], 'Magento_Store' => [], 'Acme_B' => []]],
            'chain against the alphabet' => [
                ['Acme_A' => ['Acme_B'], 'Acme_B' => ['Acme_C'], 'Acme_C' => ['Acme_D'], 'Acme_D' => []],
            ],
            'diamond' => [
                ['Acme_A' => ['Acme_C', 'Acme_B'], 'Acme_B' => ['Acme_D'], 'Acme_C' => ['Acme_D'], 'Acme_D' => []],
            ],
            'Magento modules come first' => [['Acme_A' => [], 'Magento_Zzz' => [], 'Magento_Catalog' => []]],
            'Magento module after a third-party module' => [
                ['Acme_A' => ['Magento_Store'], 'Magento_Catalog' => ['Acme_A'], 'Magento_Store' => []],
            ],
            'modules that are not installed' => [
                ['Acme_A' => ['Missing_M', 'Acme_B'], 'Acme_B' => ['Missing_M', 'Missing_N'], 'Magento_Store' => []],
            ],
            'Magento_ inside a name' => [['Acme_Magento_A' => [], 'Acme_A' => [], 'Zeta_Magento_B' => ['Acme_A']]],
            'Magento without underscore' => [['MagentoEse_A' => [], 'Acme_A' => [], 'Magento_Store' => []]],
            'duplicate references' => [
                ['Acme_A' => ['Acme_B', 'Acme_B', 'Acme_C'], 'Acme_B' => [], 'Acme_C' => ['Acme_B']],
            ],
            'names differing by case' => [
                ['acme_A' => [], 'Acme_A' => ['acme_A'], 'ACME_a' => [], 'acme_a' => ['ACME_a']],
            ],
            'underscores and digits' => [
                ['Acme_A10' => [], 'Acme_A2' => [], 'Acme_A_2' => [], 'Acme2_A' => ['Acme_A10']],
            ],
            'one after many, many after one' => [
                [
                    'Acme_A' => ['Acme_E', 'Acme_D', 'Acme_C'],
                    'Acme_B' => ['Acme_F'],
                    'Acme_C' => ['Acme_F'],
                    'Acme_D' => ['Acme_F'],
                    'Acme_E' => ['Acme_F', 'Magento_Store'],
                    'Acme_F' => [],
                    'Magento_Store' => ['Acme_F'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider moduleGraphs
     *
     * @param array<string, string[]> $modules
     */
    public function testSequenceMatchesMagento(array $modules): void
    {
        $this->assertSameSequence($this->writeModules($modules));
    }

    public function testSequenceOfRandomModulesMatchesMagento(): void
    {
        $reordered = 0;
        for ($seed = 1; $seed <= self::RANDOM_GRAPHS; $seed++) {
            $modules = $this->randomModules($seed);
            $sequence = $this->assertSameSequence($this->writeModules($modules), "Seed $seed.");
            $alphabetical = $sequence;
            sort($alphabetical);
            $reordered += (int) ($sequence !== $alphabetical);
            $this->clear();
        }

        // Guards the generator: graphs without effect on the order would compare nothing.
        self::assertGreaterThan(self::RANDOM_GRAPHS * 0.9, $reordered);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function moduleXml(): array
    {
        return [
            'more than one module' => [
                '<config><module name="Acme_X"><sequence><module name="Zeta_Z"/></sequence></module>'
                . '<module name="Acme_Y"/></config>',
            ],
            'the same module twice' => [
                '<config><module name="Acme_X"/>'
                . '<module name="Acme_X"><sequence><module name="Zeta_Z"/></sequence></module></config>',
            ],
            'more than one sequence' => [
                '<config><module name="Acme_X"><sequence><module name="Zeta_Z"/></sequence>'
                . '<sequence><module name="Zeta_Y"/></sequence></module></config>',
            ],
            'comments, whitespace and other nodes' => [
                "<config>\n<!-- a -->\n<module name=\"Acme_X\" setup_version=\"1.0.0\">\n"
                . "<other><module name=\"Zeta_Y\"/></other>\n<sequence>\n"
                . "<!-- <module name=\"Zeta_Y\"/> -->\n<other name=\"Zeta_Y\"/>\n"
                . "<module name=\"Zeta_Z\"/>\n</sequence>\n</module>\n</config>",
            ],
            'schema location' => [
                '<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
                . 'xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">'
                . '<module name="Acme_X"><sequence><module name="Zeta_Z"/></sequence></module></config>',
            ],
        ];
    }

    /**
     * @dataProvider moduleXml
     */
    public function testModuleXmlIsReadLikeMagento(string $xml): void
    {
        $this->assertSameSequence([
            $this->writeModuleXml('x', $xml),
            $this->writeModule('Zeta_Z'),
            $this->writeModule('Zeta_Y'),
        ]);
    }

    /**
     * @return array<string, array{array<string, string[]>}>
     */
    public static function circularGraphs(): array
    {
        return [
            'itself' => [['Acme_A' => ['Acme_A']]],
            'each other' => [['Acme_A' => ['Acme_B'], 'Acme_B' => ['Acme_A']]],
            'indirectly' => [
                ['Acme_A' => [], 'Acme_B' => ['Acme_C'], 'Acme_C' => ['Acme_A', 'Acme_D'], 'Acme_D' => ['Acme_B']],
            ],
        ];
    }

    /**
     * @dataProvider circularGraphs
     *
     * @param array<string, string[]> $modules
     */
    public function testCircularSequenceFailsLikeMagento(array $modules): void
    {
        $paths = $this->writeModules($modules);
        $magento = $this->exception(fn (): array => $this->magentoSequence($paths));
        $ours = $this->exception(fn (): array => $this->ourSequence($paths));

        self::assertNotNull($magento);
        self::assertNotNull($ours);
        self::assertSame($magento->getMessage(), $ours->getMessage());
        self::assertInstanceOf(\get_class($magento), $ours);
    }

    /**
     * @param string[] $paths
     * @return string[] the sequence
     */
    private function assertSameSequence(array $paths, string $message = ''): array
    {
        $magento = $this->magentoSequence($paths);
        self::assertNotEmpty($magento, $message);
        self::assertSame($magento, $this->ourSequence($paths), $message);

        return $magento;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function ourSequence(array $paths): array
    {
        return (new ModuleSorter())->sort((new ModuleReader())->read($paths));
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function magentoSequence(array $paths): array
    {
        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPaths')->willReturn($paths);

        $arguments = [new Dom(), new Parser(), $registrar, new File()];
        // Magento 2.4.8 and later create a parser per file.
        if ((new ReflectionMethod(Loader::class, '__construct'))->getNumberOfParameters() > \count($arguments)) {
            if (!class_exists(ParserFactory::class)) {
                require_once __DIR__ . '/_files/ParserFactory.php';
            }
            $arguments[] = new ParserFactory();
        }

        try {
            return array_keys((new Loader(...$arguments))->load());
        } finally {
            // Magento's parser leaves its error handler behind, which would handle PHPUnit's errors.
            $this->restoreErrorHandlers();
        }
    }

    /**
     * Remove the error handlers that Magento's parser has set.
     */
    private function restoreErrorHandlers(): void
    {
        while (true) {
            $handler = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            if (!\is_array($handler) || !$handler[0] instanceof Parser) {
                return;
            }
            restore_error_handler();
        }
    }

    /**
     * @param callable(): array<int, string> $callback
     */
    private function exception(callable $callback): ?Exception
    {
        try {
            $callback();
        } catch (Exception $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @param array<string, string[]> $modules
     * @return string[]
     */
    private function writeModules(array $modules): array
    {
        $paths = [];
        foreach ($modules as $name => $sequence) {
            $paths[] = $this->writeModule($name, $sequence);
        }

        return $paths;
    }

    /**
     * Generate modules in which every reference points to an earlier module, so that none are circular.
     *
     * @return array<string, string[]>
     */
    private function randomModules(int $seed): array
    {
        // Hashes instead of PHP's generator: a seed gives the same modules on every PHP version.
        $step = 0;
        $random = static function (int $min, int $max) use ($seed, &$step): int {
            $step++;
            $hash = hash('sha256', "$seed-$step");

            return $min + (int) hexdec(substr($hash, 0, 8)) % ($max - $min + 1);
        };

        $vendors = ['Magento', 'Acme', 'acme', 'Zeta', 'MageOS', 'Acme_Magento'];
        $letters = range('A', 'Z');
        $count = $random(5, 120);
        $names = [];
        while (\count($names) < $count) {
            $name = $vendors[$random(0, \count($vendors) - 1)] . '_' . $letters[$random(0, 25)] . $random(0, 99);
            $names[$name] = $name;
        }
        $names = array_values($names);

        $modules = [];
        foreach ($names as $index => $name) {
            $sequence = [];
            for ($i = $random(0, min(4, $index)); $i > 0; $i--) {
                $sequence[] = $names[$random(0, $index - 1)];
            }
            if ($random(0, 9) === 0) {
                $sequence[] = 'Missing_M' . $random(0, 3);
            }
            $modules[$name] = $sequence;
        }

        return $modules;
    }
}
