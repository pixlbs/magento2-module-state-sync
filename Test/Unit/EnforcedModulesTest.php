<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\EnforcedModules;
use PixelLabs\ModuleStateSync\EnforcedRule;
use PixelLabs\ModuleStateSync\Exception\SequenceException;

class EnforcedModulesTest extends TestCase
{
    use TemporaryDirectory;

    private const INSTALLED = [
        'Acme_Cart',
        'Belfurn_Form',
        'Belfurn_Theme',
        'MageOS_PageBuilderComponents',
        'MageOS_PageBuilderLayout',
        'MageOS_PageBuilderUi',
        'Magento_CatalogGraphQl',
        'Magento_GraphQl',
        'Magento_Inventory',
        'Magento_InventoryApi',
        'Magento_InventorySales',
        'Magento_Store',
        'Merchant_BrandNewFeature',
        'Merchant_Checkout',
    ];

    /**
     * The README's example.
     */
    public function testAllFormsTogether(): void
    {
        $states = $this->resolve(
            <<<'PHP'
                [
                    'enabled' => ['Merchant_*', 'MageOS_PageBuilder{Layout,Ui}', 'Magento_InventoryApi'],
                    'disabled' => [
                        'Merchant_BrandNewFeature',
                        'Magento_Inventory*',
                        'Swissup_Ignition',
                        '/^Magento_.+GraphQl$/',
                    ],
                ]
                PHP
        );

        self::assertSame(
            [
                'MageOS_PageBuilderLayout' => 1,
                'MageOS_PageBuilderUi' => 1,
                'Magento_CatalogGraphQl' => 0,
                'Magento_Inventory' => 0,
                'Magento_InventoryApi' => 1,
                'Magento_InventorySales' => 0,
                'Merchant_BrandNewFeature' => 0,
                'Merchant_Checkout' => 1,
            ],
            $states
        );
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function patterns(): array
    {
        return [
            'exact name' => ['Magento_Inventory', ['Magento_Inventory']],
            'names are case-sensitive' => ['magento_inventory*', []],
            'wildcard in the middle' => ['M*_*Api', ['Magento_InventoryApi']],
            'wildcard at the start' => ['*Ui', ['MageOS_PageBuilderUi']],
            'wildcard is anchored' => ['Inventory*', []],
            'empty alternative' => ['Magento_Inventory{,Api}', ['Magento_Inventory', 'Magento_InventoryApi']],
            'spaces between alternatives' => ['Belfurn_{Form, Theme}', ['Belfurn_Form', 'Belfurn_Theme']],
            'alternatives with a wildcard' => [
                'Ma{geOS,gento}_*{Ui,Api}',
                ['MageOS_PageBuilderUi', 'Magento_InventoryApi'],
            ],
            'regex is not anchored' => ['/Store/', ['Magento_Store']],
            'regex with flag' => ['/^ACME_/i', ['Acme_Cart']],
            'regex with quantifier' => ['/^Magento_\w{5}$/', ['Magento_Store']],
            'regex with lookahead' => [
                '/^Magento_Inventory(?!Api)/',
                ['Magento_Inventory', 'Magento_InventorySales'],
            ],
        ];
    }

    /**
     * @dataProvider patterns
     *
     * @param string[] $expected
     */
    public function testPattern(string $pattern, array $expected): void
    {
        $states = $this->resolve('[\'disabled\' => [' . var_export($pattern, true) . ']]');

        self::assertSame($expected, array_keys($states));
    }

    public function testOverlappingPatternsThatAgreeAreFine(): void
    {
        $states = $this->resolve("['enabled' => ['Belfurn_*', 'Belfurn_F*', 'Belfurn_Form', 'Belfurn_Form']]");

        self::assertSame(['Belfurn_Form' => 1, 'Belfurn_Theme' => 1], $states);
    }

    public function testEmptyFileEnforcesNothing(): void
    {
        self::assertSame([], $this->resolve('[]'));
        self::assertSame([], $this->resolve("['enabled' => [], 'disabled' => []]"));
    }

    public function testUnmatchedDisabledEntriesAreListed(): void
    {
        $file = $this->writeFile('enforced.php', "<?php return ['disabled' => ['Gone_{A,B}', 'Magento_*', '/^x/']];");
        $rules = (new EnforcedModules())->load($file);

        self::assertSame(['Gone_A', 'Gone_B', '/^x/'], $rules->unmatchedDisabled(self::INSTALLED));
    }

    /**
     * @return array<string, array{string, array<string, int>}>
     */
    public static function overlaps(): array
    {
        return [
            'disabled name, enabled wildcard' => [
                "['enabled' => ['Belfurn_*'], 'disabled' => ['Belfurn_Form']]",
                ['Belfurn_Form' => 0, 'Belfurn_Theme' => 1],
            ],
            'enabled name, disabled wildcard' => [
                "['enabled' => ['Belfurn_Form'], 'disabled' => ['Belfurn_*']]",
                ['Belfurn_Form' => 1, 'Belfurn_Theme' => 0],
            ],
            'enabled name, disabled regex' => [
                "['enabled' => ['Belfurn_Form'], 'disabled' => ['/^Belfurn_/']]",
                ['Belfurn_Form' => 1, 'Belfurn_Theme' => 0],
            ],
            'alternatives are names' => [
                "['enabled' => ['Belfurn_{Form,Theme}'], 'disabled' => ['Belfurn_*']]",
                ['Belfurn_Form' => 1, 'Belfurn_Theme' => 1],
            ],
            'both patterns' => [
                "['enabled' => ['Belfurn_*', 'Belfurn_F*'], 'disabled' => ['/Form$/']]",
                ['Belfurn_Form' => 0, 'Belfurn_Theme' => 1],
            ],
            'both patterns, disabled listed first' => [
                "['disabled' => ['*_Form'], 'enabled' => ['Belfurn_*']]",
                ['Belfurn_Form' => 0, 'Belfurn_Theme' => 1],
            ],
            'both names' => [
                "['enabled' => ['Belfurn_Form'], 'disabled' => ['Belfurn_Form']]",
                ['Belfurn_Form' => 0],
            ],
            'both names and patterns' => [
                "['enabled' => ['Belfurn_*', 'Belfurn_Form'], 'disabled' => ['Belfurn_*', 'Belfurn_Form']]",
                ['Belfurn_Form' => 0, 'Belfurn_Theme' => 0],
            ],
        ];
    }

    /**
     * An exact name beats patterns, then disabled beats enabled.
     *
     * @dataProvider overlaps
     *
     * @param array<string, int> $expected
     */
    public function testOverlap(string $code, array $expected): void
    {
        self::assertSame($expected, $this->resolve($code));
    }

    public function testMissingEnabledEntriesAreRefused(): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage(
            'Enforced as enabled, but not installed: Acme_Typo, Gone_*, /^Gone_/, Belfurn_Typo.'
        );

        $this->resolve("['enabled' => ['Acme_Typo', 'Acme_Cart', 'Gone_*', '/^Gone_/', 'Belfurn_{Form,Typo}']]");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'nothing returned' => ['', 'must return an array'],
            'module names as keys' => ["return ['Acme_Cart' => 1];", "unknown key 'Acme_Cart'"],
            'misspelled key' => ["return ['disable' => []];", "unknown key 'disable'"],
            'no list' => ["return ['enabled' => 'Acme_Cart'];", "'enabled' must be a list"],
            'states as values' => ["return ['enabled' => ['Acme_Cart' => 1]];", "'enabled' must be a list"],
            'no string' => ["return ['enabled' => [1]];", "'enabled' must contain only strings"],
            'empty entry' => ["return ['enabled' => ['']];", "'' is not a module name"],
            'other characters' => ["return ['enabled' => ['Acme Cart']];", "'Acme Cart' is not a module name"],
            'unclosed brace' => ["return ['enabled' => ['Acme_{A,B']];", "'Acme_{A,B' is not a module name"],
            'nested braces' => ["return ['enabled' => ['A_{B,{C,D}}']];", 'is not a module name'],
            'regex without end' => ["return ['disabled' => ['/Acme']];", "'/Acme' is not a valid regular expression"],
            'malformed regex' => ["return ['disabled' => ['/(/']];", "'/(/' is not a valid regular expression"],
        ];
    }

    /**
     * @dataProvider invalidFiles
     */
    public function testInvalidFileIsRefused(string $code, string $message): void
    {
        $this->expectException(SequenceException::class);
        $this->expectExceptionMessage($message);

        (new EnforcedModules())->load($this->writeFile('enforced.php', "<?php $code"));
    }

    /**
     * @return array<string, int>
     */
    private function resolve(string $code): array
    {
        $file = $this->writeFile('enforced-' . hash('sha256', $code) . '.php', "<?php return $code;");

        return array_map(
            static fn (EnforcedRule $rule): int => $rule->state,
            (new EnforcedModules())->load($file)->resolve(self::INSTALLED)
        );
    }
}
