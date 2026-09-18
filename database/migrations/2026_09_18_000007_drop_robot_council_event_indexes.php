<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the two composite indexes `robot-council/core#94` removed from the create migration.
 *
 * **A migration rather than an edit, because the condition that made edits safe has lapsed.**
 * `CLAUDE.md` recorded that this package edits its create migrations in place, and named the one
 * thing that made that safe: `v0.1.0` ships no `database/` directory, so no host could have run any
 * of them from a release. It also named how that ends -- "the first host installing from `dev-main`
 * makes this false, silently" -- and it has. `robot-council/robot-council` installs from `dev-main`
 * and has run the events migration, so editing it left that database carrying two indexes a fresh
 * install no longer creates.
 *
 * #94 measured those indexes as used by no shape of the feed read, on a million-event feed, with the
 * plan and buffer count identical without them. On an append-only table they are two index writes
 * per event buying nothing, which is worth removing from a database that already has them rather
 * than only from ones created later.
 *
 * **Guarded on presence rather than assumed**, because this runs against three kinds of database: a
 * host that installed after #94 never had them, a host that installed before it does, and SQLite
 * reports index names differently from Postgres. `dropIndexIfExists` does not exist on the schema
 * builder, so the check is a read of what is actually there.
 */
return new class extends Migration
{
    /**
     * The indexes to remove, by the columns they cover.
     *
     * Named by columns rather than by index name, because the name is the engine's business: Laravel
     * generates `<table>_<columns>_index` but a host that created one by hand may have called it
     * something else, and comparing columns finds it either way.
     *
     * @var list<list<string>>
     */
    private const COLUMNS = [
        ['user_id', 'id'],
        ['type', 'id'],
    ];

    /**
     * Drop each index, where it is there to drop.
     */
    public function up(): void
    {
        $present = $this->present();

        Schema::table('robot_council_events', function (Blueprint $table) use ($present): void {
            foreach ($present as $name) {
                $table->dropIndex($name);
            }
        });
    }

    /**
     * Put them back, for a host rolling this migration back.
     *
     * Recreated by columns, which gives them Laravel's generated names again. A host whose indexes
     * carried other names gets the conventional ones back rather than its own, which is the most a
     * rollback can honestly promise.
     */
    public function down(): void
    {
        Schema::table('robot_council_events', function (Blueprint $table): void {
            foreach (self::COLUMNS as $columns) {
                $table->index($columns);
            }
        });
    }

    /**
     * The names of the indexes this migration is about that the table actually has.
     *
     * @return list<string> The index names to drop.
     */
    private function present(): array
    {
        $wanted = array_map(
            fn (array $columns): array => array_map(strtolower(...), $columns),
            self::COLUMNS
        );

        $found = [];

        foreach (Schema::getIndexes('robot_council_events') as $index) {
            $columns = $index['columns'] ?? null;
            $name = $index['name'] ?? null;

            if (! \is_array($columns) || ! \is_string($name)) {
                continue;
            }

            $lowered = array_values(array_map(
                static fn (mixed $column): string => strtolower(\is_scalar($column) ? (string) $column : ''),
                $columns
            ));

            if (\in_array($lowered, $wanted, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }
};
