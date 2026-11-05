<?php

declare(strict_types=1);

use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The projection tables. Indexes and triggers are NOT created here: they are derived from the
 * registered definitions and installed by `model-search:sync` (also run after every migration),
 * with `CREATE INDEX CONCURRENTLY`, which cannot run inside a migration transaction.
 *
 * Primary keys stay `(subject_type, subject_id)` also for partitioned setups: the partition column
 * is nullable and ids are unique per source table. The triggers write `changes.partition = NULL`;
 * the drain derives the partition from the root row.
 *
 * Privileges: the role that runs the sync owns these tables. The roles that write to the watched
 * tables need INSERT on the changes table (the triggers run as the invoker), and UPDATE on the
 * state table to TRUNCATE a watched table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = new TableNames(self::prefix());

        Schema::create($names->entries(), function (Blueprint $table): void {
            $table->text('subject_type');
            $table->text('subject_id');
            $table->text('partition')->nullable();
            $table->smallInteger('stage');
            $table->text('names')->default('');
            $table->timestampTz('sort_at', 6)->nullable();
            $table->timestampTz('indexed_at', 6);
            $table->primary(['subject_type', 'subject_id']);
        });

        Schema::create($names->keys(), function (Blueprint $table): void {
            $table->text('subject_type');
            $table->text('subject_id');
            $table->text('partition')->nullable();
            $table->text('kind');
            $table->text('key');
            $table->text('value');
            $table->smallInteger('stage');
            $table->primary(['subject_type', 'subject_id', 'kind', 'key']);
        });

        foreach ([$names->entries(), $names->keys()] as $table) {
            DB::statement(sprintf('ALTER TABLE "%s" SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_analyze_scale_factor = 0.01)', $table));
        }

        Schema::create($names->changes(), function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('node_type');
            $table->text('node_id');
            $table->text('partition')->nullable();
            $table->timestampTz('changed_at', 6)->default(DB::raw('clock_timestamp()'));
        });

        Schema::create($names->state(), function (Blueprint $table): void {
            $table->text('subject_type')->primary();
            $table->text('definition_fingerprint');
            $table->bigInteger('definition_version');
            $table->timestampTz('installed_at', 6);
            $table->timestampTz('ready_at', 6)->nullable();
            $table->text('rebuild_cursor')->nullable();
            $table->timestampTz('rebuild_started_at', 6)->nullable();
        });

        Schema::create($names->failures(), function (Blueprint $table): void {
            $table->text('subject_type');
            $table->text('subject_id');
            $table->integer('attempts');
            $table->text('last_error_class');
            $table->timestampTz('last_failed_at', 6);
            $table->primary(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        $names = new TableNames(self::prefix());

        // Triggers on host tables write to the changes table: they must go first.
        app(SchemaSync::class)->uninstall();

        foreach ([$names->failures(), $names->state(), $names->changes(), $names->keys(), $names->entries()] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private static function prefix(): string
    {
        $prefix = config('model-search.table_prefix');

        return is_string($prefix) ? $prefix : 'model_search_';
    }
};
