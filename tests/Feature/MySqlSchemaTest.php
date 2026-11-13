<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_generated_index_name_fits_the_mysql_identifier_limit(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only identifier regression check.');
        }

        $indexes = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->selectRaw('TABLE_NAME as table_name, INDEX_NAME as index_name')
            ->get()
            ->unique(fn ($row) => $row->table_name.'.'.$row->index_name);

        $this->assertNotEmpty($indexes);

        foreach ($indexes as $index) {
            $this->assertLessThanOrEqual(
                64,
                strlen($index->index_name),
                "Index {$index->table_name}.{$index->index_name} exceeds MySQL's 64-character identifier limit.",
            );
        }
    }
}
