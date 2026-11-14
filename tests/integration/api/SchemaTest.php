<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel prefixes the index and key names it makes up, but not ones a
 * migration writes itself, so a hand-written name collides when two installs
 * share a database (PostgreSQL and SQLite index names are schema-wide, MySQL
 * foreign key names database-wide).
 */
class SchemaTest extends TestCase
{
    #[Test]
    public function every_index_and_foreign_key_carries_the_table_prefix(): void
    {
        $this->extension('ernestdefoe-depot');
        $this->app();

        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $db->getSchemaBuilder();
        $prefix = $db->getTablePrefix();
        $tables = ['depot_categories', 'depot_category_groups', 'depot_files', 'depot_versions', 'depot_screenshots',
            'depot_reviews', 'depot_review_helpful', 'depot_watchers', 'depot_download_days', 'depot_download_log'];

        $checked = 0;
        foreach ($tables as $table) {
            $this->assertTrue($schema->hasTable($table), $table);
            $names = [
                ...array_column(array_filter($schema->getIndexes($table), fn ($i) => ! $i['primary']), 'name'),
                ...array_column($schema->getForeignKeys($table), 'name'),
            ];
            foreach (array_filter($names) as $name) {
                $this->assertStringStartsWith(strtolower($prefix.$table.'_'), strtolower($name), "$table: $name");
                $checked++;
            }
        }

        $this->assertGreaterThan(15, $checked);
    }
}
