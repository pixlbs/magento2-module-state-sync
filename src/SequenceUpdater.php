<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

/**
 * Computes the new module states, like setup:upgrade does, with enforced states on top.
 */
class SequenceUpdater
{
    /**
     * Set up the updater.
     *
     * @param ModuleSorter $sorter
     */
    public function __construct(private readonly ModuleSorter $sorter)
    {
    }

    /**
     * Compute the states of all installed modules, in sequence order.
     *
     * Modules that are not installed are dropped, disabled modules stay disabled and the others are enabled.
     *
     * @param array<string,mixed> $current the states in config.php
     * @param array<string,list<string>> $installed module name => names of the modules it must be loaded after
     * @param array<string,EnforcedRule> $enforced
     * @return array<string,int>
     */
    public function update(array $current, array $installed, array $enforced): array
    {
        $result = [];
        foreach ($this->sorter->sort($installed) as $name) {
            $kept = isset($current[$name]) && !$current[$name] ? 0 : 1;
            $result[$name] = isset($enforced[$name]) ? $enforced[$name]->state : $kept;
        }

        return $result;
    }
}
