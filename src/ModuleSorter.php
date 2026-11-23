<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use LogicException;

/**
 * Sorts modules by sequence, in exactly the order of Magento\Framework\Module\ModuleList\Loader.
 *
 * Magento's class itself cannot be used: as of 2.4.8 it needs a generated factory and the ObjectManager. This is a port
 * of its algorithm, quirks included; MagentoParityTest compares the two.
 */
class ModuleSorter
{
    /**
     * Sort the modules.
     *
     * @param array<string,list<string>> $modules module name => names of the modules it must be loaded after
     * @return list<string>
     */
    public function sort(array $modules): array
    {
        ksort($modules);

        $cache = [];
        $expanded = [];
        foreach ($this->magentoFirst(array_keys($modules)) as $name) {
            $expanded[] = [$name, array_flip($this->expandSequence($modules, $name, $cache))];
        }

        // Each pair has to be compared, a regular sort doesn't do that. The result depends on the order of the swaps.
        $total = \count($expanded);
        for ($i = 0; $i < $total - 1; $i++) {
            for ($j = $i; $j < $total; $j++) {
                if (isset($expanded[$i][1][$expanded[$j][0]])) {
                    [$expanded[$i], $expanded[$j]] = [$expanded[$j], $expanded[$i]];
                }
            }
        }

        return array_column($expanded, 0);
    }

    /**
     * Put Magento's modules before the others. "Magento_" counts anywhere in the name, as in Magento.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function magentoFirst(array $names): array
    {
        $isMagento = static fn (string $name): bool => str_contains($name, 'Magento_');

        return array_merge(
            array_values(array_filter($names, $isMagento)),
            array_values(array_filter($names, static fn (string $name): bool => !$isMagento($name)))
        );
    }

    /**
     * Collect all direct and indirect sequence references of a module.
     *
     * @param array<string,list<string>> $modules
     * @param string $name
     * @param array<string,list<string>> $cache
     * @param array<string,true> $ancestors
     * @param string $parent
     * @return list<string>
     */
    private function expandSequence(
        array $modules,
        string $name,
        array &$cache,
        array $ancestors = [],
        string $parent = ''
    ): array {
        if (isset($ancestors[$name])) {
            throw new LogicException("Circular sequence reference from '$parent' to '$name'.");
        }
        $ancestors[$name] = true;

        if (!isset($cache[$name])) {
            $sequence = $modules[$name] ?? [];
            $all = [];
            foreach ($sequence as $related) {
                $all[] = $this->expandSequence($modules, $related, $cache, $ancestors, $name);
            }
            $all[] = $sequence;
            $cache[$name] = array_values(array_unique(array_merge(...$all)));
        }

        return $cache[$name];
    }
}
