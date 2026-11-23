<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * Decides which rule applies to which module.
 */
class EnforcedRules
{
    /**
     * Set up the rules.
     *
     * @param list<EnforcedRule> $rules
     */
    public function __construct(private readonly array $rules = [])
    {
    }

    /**
     * Find the rule of each installed module that has one.
     *
     * A module's exact name beats wildcards and regular expressions; after that, disabled beats enabled. An enabled
     * entry that matches nothing is an error: it is a typo, or a package is missing.
     *
     * @param list<string> $installed
     * @return array<string,EnforcedRule> module name => rule
     */
    public function resolve(array $installed): array
    {
        $missing = $this->unmatched(1, $installed);
        if ($missing !== []) {
            throw new SequenceException('Enforced as enabled, but not installed: ' . implode(', ', $missing) . '.');
        }

        $result = [];
        foreach ($installed as $name) {
            $rule = $this->ruleFor($name);
            if ($rule !== null) {
                $result[$name] = $rule;
            }
        }

        return $result;
    }

    /**
     * List the disabled entries that match no installed module.
     *
     * @param list<string> $installed
     * @return list<string>
     */
    public function unmatchedDisabled(array $installed): array
    {
        return $this->unmatched(0, $installed);
    }

    /**
     * Get the rule that applies to a module.
     *
     * @param string $name
     */
    private function ruleFor(string $name): ?EnforcedRule
    {
        $result = null;
        foreach ($this->rules as $rule) {
            if (!$rule->matches($name)) {
                continue;
            }
            // Exact before pattern, then disabled before enabled; between equals the first one.
            $rank = [$rule->isExact() ? 0 : 1, $rule->state];
            if ($result === null || $rank < [$result->isExact() ? 0 : 1, $result->state]) {
                $result = $rule;
            }
        }

        return $result;
    }

    /**
     * List the entries with the given state that match no installed module.
     *
     * @param int $state
     * @param list<string> $installed
     * @return list<string>
     */
    private function unmatched(int $state, array $installed): array
    {
        $entries = [];
        foreach ($this->rules as $rule) {
            if ($rule->state !== $state) {
                continue;
            }
            foreach ($installed as $name) {
                if ($rule->matches($name)) {
                    continue 2;
                }
            }
            $entries[] = $rule->entry;
        }

        return array_values(array_unique($entries));
    }
}
