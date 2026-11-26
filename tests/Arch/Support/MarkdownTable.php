<?php

namespace Tests\Arch\Support;

/** Reads the tables of the normative Markdown documents for DriftTest. */
final class MarkdownTable
{
    /**
     * Rows of the first table after $heading, as lists of trimmed cells, with
     * the header and separator rows dropped.
     *
     * @return list<list<string>>
     */
    public static function rowsAfter(string $document, string $heading, bool $allowEmpty = false): array
    {
        return array_slice(self::tableAfter($document, $heading, $allowEmpty), 2);
    }

    /**
     * The header row of the first table after $heading.
     *
     * @return list<string>
     */
    public static function headerAfter(string $document, string $heading): array
    {
        return self::tableAfter($document, $heading)[0];
    }

    /** @return list<list<string>> */
    private static function tableAfter(string $document, string $heading, bool $allowEmpty = false): array
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3).'/'.$document));
        $start = strpos($text, $heading);

        expect($start)->not->toBeFalse("{$document} has no \"{$heading}\"");

        $rows = [];

        foreach (array_slice(explode("\n", substr($text, (int) $start)), 1) as $line) {
            if (! str_starts_with($line, '|')) {
                if ($rows !== []) {
                    break;
                }

                continue;
            }

            $rows[] = array_map(trim(...), explode('|', trim($line, '|')));
        }

        expect(count($rows))->toBeGreaterThan($allowEmpty ? 1 : 2, "{$document} has no table after \"{$heading}\"");

        return $rows;
    }
}
