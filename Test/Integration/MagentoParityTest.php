<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Test\Integration;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Module\ModuleList\Loader;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelLabs\ModuleStateSync\ModuleReader;
use PixelLabs\ModuleStateSync\ModuleSorter;

/**
 * All modules of a real install are put in the same order as by Magento's own loader.
 */
class MagentoParityTest extends TestCase
{
    public function testSequenceMatchesMagento(): void
    {
        $magento = array_keys(Bootstrap::getObjectManager()->create(Loader::class)->load());
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertContains('Magento_Store', $magento);
        self::assertSame($magento, (new ModuleSorter())->sort((new ModuleReader())->read($paths)));
    }
}
