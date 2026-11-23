<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

/**
 * Describes the difference between two sets of module states.
 */
class ModuleChanges
{
    /**
     * List the changes, one line each.
     *
     * @param array<string,mixed> $before
     * @param array<string,int> $after
     * @param array<string,EnforcedRule> $enforced
     * @return list<string>
     */
    public function describe(array $before, array $after, array $enforced): array
    {
        $lines = [];
        foreach ($after as $name => $state) {
            $suffix = isset($enforced[$name]) ? " (enforced by {$enforced[$name]->entry})" : '';
            $label = $state ? 'enabled' : 'disabled';
            if (!isset($before[$name])) {
                $lines[] = "Added, $label: $name$suffix";
            } elseif ((bool) $before[$name] !== (bool) $state) {
                $lines[] = ucfirst($label) . ": $name$suffix";
            }
        }
        foreach (array_keys(array_diff_key($before, $after)) as $name) {
            $lines[] = "Removed, not installed: $name";
        }

        $kept = array_keys(array_intersect_key($before, $after));
        if ($kept !== array_keys(array_intersect_key($after, $before))) {
            $lines[] = 'The module sequence changed.';
        }

        return $lines;
    }
}
