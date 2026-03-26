<?php

declare(strict_types=1);

namespace PHPAgentMemory\Recall;

final class Formatter
{
    public static function format(array $memories, array $skills, array $knowledge, int $maxChars = 8000): string
    {
        $sections = [];
        $chars = 0;

        if (!empty($memories)) {
            $section = "## Memories\n";
            foreach ($memories as $m) {
                $meta = $m['metadata'] ?? [];
                $line = "- [{$meta['category']}] {$meta['content']}";
                if (!empty($meta['tags'])) {
                    $line .= ' [' . implode(', ', $meta['tags']) . ']';
                }
                $line .= "\n";

                if ($chars + strlen($section) + strlen($line) > $maxChars) {
                    break;
                }
                $section .= $line;
                $chars += strlen($line);
            }
            $sections[] = $section;
        }

        if (!empty($skills)) {
            $section = "## Skills\n";
            foreach ($skills as $s) {
                $meta = $s['metadata'] ?? [];
                $line = "- [{$meta['category']}] {$meta['content']}\n";

                if ($chars + strlen($section) + strlen($line) > $maxChars) {
                    break;
                }
                $section .= $line;
                $chars += strlen($line);
            }
            $sections[] = $section;
        }

        if (!empty($knowledge)) {
            $section = "## Knowledge\n";
            foreach ($knowledge as $k) {
                $meta = $k['metadata'] ?? [];
                $source = $meta['source'] ? " (source: {$meta['source']})" : '';
                $line = "- {$meta['content']}{$source}\n";

                if ($chars + strlen($section) + strlen($line) > $maxChars) {
                    break;
                }
                $section .= $line;
                $chars += strlen($line);
            }
            $sections[] = $section;
        }

        return implode("\n", $sections);
    }
}
