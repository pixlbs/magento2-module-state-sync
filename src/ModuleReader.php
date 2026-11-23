<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PixelLabs\ModuleStateSync\Exception\SequenceException;

/**
 * Reads module names and their sequence from etc/module.xml, the way Magento's module.xml converter does.
 */
class ModuleReader
{
    /**
     * Read the modules in the given directories.
     *
     * @param iterable<string> $modulePaths
     * @return array<string,list<string>> module name => names of the modules it must be loaded after
     */
    public function read(iterable $modulePaths): array
    {
        $modules = [];
        foreach ($modulePaths as $modulePath) {
            $file = $modulePath . '/etc/module.xml';
            $declared = $this->parse($file);
            // Magento uses only the first module of a file.
            $name = array_key_first($declared);
            if ($name === null) {
                throw new SequenceException("No module is declared in $file.");
            }
            $modules[$name] = $declared[$name];
        }

        return $modules;
    }

    /**
     * Parse one module.xml.
     *
     * @param string $file
     * @return array<string,list<string>>
     */
    private function parse(string $file): array
    {
        $modules = [];
        foreach ((new DOMXPath($this->load($file)))->query('/config/module') ?: [] as $module) {
            if (!$module instanceof DOMElement) {
                continue;
            }
            $sequence = [];
            foreach ($module->childNodes as $child) {
                if ($child->nodeName === 'sequence') {
                    // A second <sequence> replaces the first one, as in Magento.
                    $sequence = $this->readSequence($child, $file);
                }
            }
            $modules[$this->readName($module, $file)] = $sequence;
        }

        return $modules;
    }

    /**
     * Read the names in a sequence node.
     *
     * @param DOMNode $node
     * @param string $file
     * @return list<string>
     */
    private function readSequence(DOMNode $node, string $file): array
    {
        $names = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'module') {
                $names[] = $this->readName($child, $file);
            }
        }

        return $names;
    }

    /**
     * Read the name attribute of a module node.
     *
     * @param DOMNode $node
     * @param string $file
     */
    private function readName(DOMNode $node, string $file): string
    {
        if (!$node instanceof DOMElement || !$node->hasAttribute('name')) {
            throw new SequenceException("Attribute \"name\" is required for module node in $file.");
        }

        return $node->getAttribute('name');
    }

    /**
     * Load the XML of one module.xml.
     *
     * @param string $file
     */
    private function load(string $file): DOMDocument
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || $contents === '') {
            throw new SequenceException("Cannot read $file.");
        }

        $document = new DOMDocument();
        $useErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET);
        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);
        if (!$loaded) {
            throw new SequenceException("Invalid XML in $file: " . trim($error ? $error->message : 'unknown error'));
        }

        return $document;
    }
}
