<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The design spec (docs/superpowers/specs/2026-08-08-ui-ux-redesign-design.md)
 * asks for one compact density on data-table screens, and normal, roomier
 * spacing everywhere else — "density is a table-context rule, not a global one."
 *
 * These are structural checks over the Blade sources rather than rendered-HTML
 * assertions on purpose: the point is that every data-table screen agrees with
 * every other one, which is a property of the whole set, not of any single page.
 * A per-page assertion would let a new screen ship at the old density unnoticed.
 *
 * The set is defined by the shared data-table pattern's row treatment
 * (hover:bg-orange-50) rather than by "has a <table>", because two kinds of
 * table deliberately sit outside the rule: the ДДВ-04 report, whose label/value
 * layout has no header rows at all (recorded in the Documents-module plan), and
 * any future form-style table.
 */
class TableDensityTest extends TestCase
{
    private const COMPACT_CELL = 'py-1';

    private const SUPERSEDED_CELL = 'py-2 px-4';

    private const SHARED_ROW_PATTERN = 'hover:bg-orange-50';

    /** @return array<string, string> filename => contents */
    private function dataTableViews(): array
    {
        $views = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/livewire'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (str_contains($contents, '<table') && str_contains($contents, self::SHARED_ROW_PATTERN)) {
                $views[$file->getFilename()] = $contents;
            }
        }

        ksort($views);

        return $views;
    }

    public function test_every_data_table_screen_uses_the_compact_cell_padding(): void
    {
        $views = $this->dataTableViews();

        // Guards the discovery logic itself: if a refactor renamed the shared
        // row treatment, this would otherwise pass vacuously over zero files.
        $this->assertGreaterThan(15, count($views), 'Found suspiciously few data-table views — the discovery logic is probably wrong.');

        foreach ($views as $name => $contents) {
            $this->assertMatchesRegularExpression(
                '/<t[hd] class="'.preg_quote(self::COMPACT_CELL, '/').'[ "]/',
                $contents,
                "{$name} is a data-table screen but its cells do not use the compact ".self::COMPACT_CELL.' padding.'
            );
        }
    }

    public function test_no_screen_still_carries_the_superseded_roomy_padding(): void
    {
        foreach ($this->dataTableViews() as $name => $contents) {
            $this->assertStringNotContainsString(
                self::SUPERSEDED_CELL,
                $contents,
                "{$name} still uses the pre-compact padding ".self::SUPERSEDED_CELL.'.'
            );
        }
    }

    public function test_the_compact_rule_did_not_leak_into_the_sidebar(): void
    {
        // Non-table layouts keep their generous spacing. The sidebar's nav items
        // are the clearest example, and they write their padding the other way
        // round (px first), so a careless find-and-replace across
        // resources/views would show up here.
        $sidebar = file_get_contents(resource_path('views/livewire/layout/sidebar.blade.php'));

        $this->assertStringContainsString('px-4 py-2', $sidebar);
    }
}
