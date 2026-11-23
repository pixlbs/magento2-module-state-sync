<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Unit;

use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\EnforcedRule;
use PixelLabs\ModuleStateSync\ModuleSorter;
use PixelLabs\ModuleStateSync\SequenceUpdater;

class SequenceUpdaterTest extends TestCase
{
    private const INSTALLED = ['Acme_A' => ['Acme_B'], 'Acme_B' => [], 'Acme_C' => []];

    /**
     * @return array<string, array{array<string, mixed>, array<string, int>, array<string, int>}>
     */
    public static function states(): array
    {
        return [
            'new modules are enabled' => [['Acme_C' => 1], [], ['Acme_B' => 1, 'Acme_A' => 1, 'Acme_C' => 1]],
            'disabled modules stay disabled' => [
                ['Acme_A' => 0, 'Acme_B' => '0', 'Acme_C' => false],
                [],
                ['Acme_B' => 0, 'Acme_A' => 0, 'Acme_C' => 0],
            ],
            'removed modules are dropped' => [
                ['Gone_G' => 1, 'Gone_H' => 0],
                [],
                ['Acme_B' => 1, 'Acme_A' => 1, 'Acme_C' => 1],
            ],
            'enforced states win over config.php' => [
                ['Acme_A' => 0, 'Acme_B' => 1, 'Acme_C' => 0],
                ['Acme_A' => 1, 'Acme_B' => 0],
                ['Acme_B' => 0, 'Acme_A' => 1, 'Acme_C' => 0],
            ],
            'enforced states apply to new modules' => [
                ['Acme_A' => 1],
                ['Acme_B' => 0, 'Acme_C' => 1],
                ['Acme_B' => 0, 'Acme_A' => 1, 'Acme_C' => 1],
            ],
        ];
    }

    /**
     * @dataProvider states
     *
     * @param array<string, mixed> $current
     * @param array<string, int> $enforced
     * @param array<string, int> $expected
     */
    public function testUpdate(array $current, array $enforced, array $expected): void
    {
        $rules = [];
        foreach ($enforced as $name => $state) {
            $rules[$name] = new EnforcedRule($name, $state);
        }
        $updater = new SequenceUpdater(new ModuleSorter());

        self::assertSame($expected, $updater->update($current, self::INSTALLED, $rules));
    }
}
