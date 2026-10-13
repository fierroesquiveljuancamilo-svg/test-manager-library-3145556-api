<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibrarySchemaTest extends TestCase
{
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run with DB_CONNECTION=pgsql against a PostgreSQL test database without a library schema.');
        }

        DB::beginTransaction();
        $this->migration = require database_path('migrations/2026_10_13_205618_create_library_schema.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->migration)) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_columns_match_the_physical_model_without_defaults(): void
    {
        $expected = [
            'book' => ['id:uuid', 'title:character varying:250', 'author:character varying:200', 'isbn:character:13', 'category_id:uuid', 'total_copies:integer', 'available_copies:integer', 'cover_key:character varying:512:nullable', 'deleted_at:timestamp with time zone::nullable'],
            'category' => ['id:uuid', 'name:character varying:120'],
            'loan' => ['id:uuid', 'member_id:uuid', 'loaned_by_user_id:uuid', 'loaned_at:timestamp with time zone'],
            'loan_item' => ['id:uuid', 'loan_id:uuid', 'book_id:uuid', 'book_title:character varying:250', 'isbn:character:13', 'category_name:character varying:120', 'due_at:timestamp with time zone', 'returned_at:timestamp with time zone::nullable'],
            'member' => ['id:uuid', 'full_name:character varying:200', 'document_number:character varying:30'],
            'user' => ['id:uuid', 'username:character varying:120', 'password_hash:character varying:512', 'role:character varying:40'],
        ];
        $actual = [];
        foreach (DB::select("SELECT * FROM information_schema.columns WHERE table_schema = 'library' ORDER BY table_name, ordinal_position") as $column) {
            $this->assertNull($column->column_default);
            $value = $column->column_name.':'.$column->data_type;
            if ($column->character_maximum_length !== null || $column->is_nullable === 'YES') {
                $value .= ':'.$column->character_maximum_length;
            }
            if ($column->is_nullable === 'YES') {
                $value .= ':nullable';
            }
            $actual[$column->table_name][] = $value;
        }
        $this->assertSame($expected, $actual);
    }

    public function test_categories_have_the_exact_fixed_ids_and_names(): void
    {
        $this->assertSame([
            '11111111-1111-4111-8111-111111111111' => 'Novela',
            '22222222-2222-4222-8222-222222222222' => 'Ciencia',
            '33333333-3333-4333-8333-333333333333' => 'Tecnología',
            '44444444-4444-4444-8444-444444444444' => 'Historia',
            '55555555-5555-4555-8555-555555555555' => 'Infantil',
        ], DB::table('library.category')->orderBy('id')->pluck('name', 'id')->all());
        $this->assertSame(0, DB::table('library.user')->count());
    }

    public function test_constraints_and_access_indexes_match_t02(): void
    {
        $constraints = collect(DB::select("SELECT c.conname, c.contype, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = 'library'"));
        $this->assertCount(6, $constraints->where('contype', 'p'));
        $this->assertCount(4, $constraints->where('contype', 'u'));
        $this->assertSame(['ck_book_available_range'], $constraints->where('contype', 'c')->pluck('conname')->values()->all());
        $foreignKeys = $constraints->where('contype', 'f')->pluck('definition')->sort()->values()->all();
        $this->assertSame([
            'FOREIGN KEY (book_id) REFERENCES library.book(id) ON DELETE RESTRICT',
            'FOREIGN KEY (category_id) REFERENCES library.category(id) ON DELETE RESTRICT',
            'FOREIGN KEY (loan_id) REFERENCES library.loan(id) ON DELETE CASCADE',
            'FOREIGN KEY (loaned_by_user_id) REFERENCES library."user"(id) ON DELETE RESTRICT',
            'FOREIGN KEY (member_id) REFERENCES library.member(id) ON DELETE RESTRICT',
        ], $foreignKeys);
        $this->assertSame(5, (int) DB::scalar("SELECT count(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = 'library' AND contype = 'f' AND confupdtype = 'a'"));
        $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'library'"))->pluck('indexdef', 'indexname');
        $this->assertCount(15, $indexes);
        foreach ([
            'book_active_isbn_unique' => 'UNIQUE INDEX book_active_isbn_unique ON library.book USING btree (isbn) WHERE (deleted_at IS NULL)',
            'book_active_category_title_index' => '(category_id, title) WHERE (deleted_at IS NULL)',
            'loan_loaned_at_index' => '(loaned_at)',
            'loan_item_book_id_index' => '(book_id)',
            'loan_item_unreturned_due_at_index' => '(due_at) WHERE (returned_at IS NULL)',
            'category_name_key' => '(name)',
            'member_document_number_key' => '(document_number)',
            'user_username_key' => '(username)',
            'loan_item_loan_id_book_id_key' => '(loan_id, book_id)',
        ] as $name => $definition) {
            $this->assertStringContainsString($definition, $indexes[$name]);
        }
        $this->assertStringNotContainsString('password_hash', $indexes->implode(' '));
    }

    public function test_active_isbn_uniqueness_and_available_range_are_enforced(): void
    {
        $book = [
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'title' => 'Example', 'author' => 'Author', 'isbn' => '9780000000001',
            'category_id' => '11111111-1111-4111-8111-111111111111',
            'total_copies' => 1, 'available_copies' => 1,
        ];
        DB::table('library.book')->insert($book);
        $book['id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->assertSqlState('23505', fn () => DB::table('library.book')->insert($book));
        DB::table('library.book')->update(['deleted_at' => '2026-01-01T00:00:00Z']);
        DB::table('library.book')->insert($book);
        foreach ([-1, 2] as $available) {
            $this->assertSqlState('23514', fn () => DB::table('library.book')->update(['available_copies' => $available]));
        }
        $this->assertSqlState('23503', fn () => DB::table('library.category')->where('id', $book['category_id'])->delete());
        $this->assertSqlState('22P02', fn () => DB::table('library.member')->insert(['id' => 'invalid', 'full_name' => 'Member', 'document_number' => '123']));
    }

    public function test_loan_foreign_keys_and_line_uniqueness_are_enforced(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        DB::table('library.member')->insert(['id' => $id, 'full_name' => 'Member', 'document_number' => '123']);
        DB::table('library.user')->insert(['id' => $id, 'username' => 'operator', 'password_hash' => 'test-placeholder', 'role' => 'librarian']);
        DB::table('library.book')->insert([
            'id' => $id, 'title' => 'Current title', 'author' => 'Author', 'isbn' => '9780000000001',
            'category_id' => '11111111-1111-4111-8111-111111111111', 'total_copies' => 1, 'available_copies' => 0,
        ]);
        DB::table('library.loan')->insert(['id' => $id, 'member_id' => $id, 'loaned_by_user_id' => $id, 'loaned_at' => '2026-01-01T00:00:00Z']);
        $line = [
            'id' => $id, 'loan_id' => $id, 'book_id' => $id, 'book_title' => 'Frozen title',
            'isbn' => '9780000000001', 'category_name' => 'Frozen category', 'due_at' => '2026-01-15T00:00:00Z',
        ];
        DB::table('library.loan_item')->insert($line);
        $line['id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->assertSqlState('23505', fn () => DB::table('library.loan_item')->insert($line));
        foreach (['member', 'user', 'book'] as $table) {
            $this->assertSqlState('23503', fn () => DB::table('library.'.$table)->where('id', $id)->delete());
        }
        DB::table('library.loan')->where('id', $id)->delete();
        $this->assertSame(0, DB::table('library.loan_item')->count());
        $this->assertSame(1, DB::table('library.book')->count());
    }

    public function test_rollback_and_reapply_restore_the_schema_and_seed(): void
    {
        $this->migration->down();
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM information_schema.schemata WHERE schema_name = 'library'"));
        $this->migration->up();
        $this->test_columns_match_the_physical_model_without_defaults();
        $this->test_categories_have_the_exact_fixed_ids_and_names();
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::statement('SAVEPOINT expected_violation');
        try {
            $operation();
            $this->fail('Expected PostgreSQL to reject the operation.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT expected_violation');
            DB::statement('RELEASE SAVEPOINT expected_violation');
        }
    }
}
