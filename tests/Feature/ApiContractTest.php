<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract and integration tests against a real PostgreSQL (database library_test).
 * Each test starts from an engine with no schema and runs the migrations (ADR-001).
 */
final class ApiContractTest extends TestCase
{
    private const NOVELA = '11111111-1111-4111-8111-111111111111';
    private const CIENCIA = '22222222-2222-4222-8222-222222222222';

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DB::unprepared('DROP SCHEMA IF EXISTS library CASCADE; DROP TABLE IF EXISTS public.migrations;');
        Artisan::call('migrate', ['--force' => true]);
        config(['library.admin_username' => 'admin', 'library.admin_password' => 'Admin-Test-123']);
        Artisan::call('library:bootstrap-admin');
        $this->admin = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'Admin-Test-123'])->json('access_token');
    }

    private function as(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function book(string $isbn, int $total = 1, string $title = 'Cien años de soledad', string $cat = self::NOVELA): string
    {
        return $this->as($this->admin)->postJson('/api/v1/books', [
            'title' => $title, 'author' => 'GGM', 'isbn' => $isbn, 'category_id' => $cat, 'total_copies' => $total,
        ])->assertCreated()->json('id');
    }

    private function member(string $doc): string
    {
        return $this->as($this->admin)->postJson('/api/v1/members', ['full_name' => "Socio {$doc}", 'document_number' => $doc])->assertCreated()->json('id');
    }

    private function assertErrorBody($res, int $status, string $code): void
    {
        $res->assertStatus($status)->assertExactJsonStructure(['code', 'message', 'details']);
        $this->assertSame($code, $res->json('code'));
    }

    public function test_engine_matches_data_model(): void
    {
        $tables = collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'library' ORDER BY 1"))->pluck('table_name')->all();
        $this->assertSame(['book', 'category', 'loan', 'loan_item', 'member', 'user'], $tables, 'six tables, singular names');
        $defaults = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'library' AND column_default IS NOT NULL");
        $this->assertSame([], $defaults, 'no column with DEFAULT');
        $audit = DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'library' AND column_name IN ('created_at','updated_at','status','days_late')");
        $this->assertSame([], $audit, 'no audit columns or stored status');
        $checks = collect(DB::select("SELECT conname FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = 'library' AND contype = 'c'"))->pluck('conname')->sort()->values()->all();
        $this->assertSame(['ck_book_available_range', 'ck_book_total_positive', 'ck_user_role'], $checks);
    }

    public function test_auth_errors_and_roles(): void
    {
        $this->assertErrorBody($this->getJson('/api/v1/books'), 401, 'UNAUTHENTICATED');
        $this->assertErrorBody($this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'x']), 401, 'INVALID_CREDENTIALS');
        $this->assertErrorBody($this->postJson('/api/v1/auth/login', ['username' => 'nadie', 'password' => 'x']), 401, 'INVALID_CREDENTIALS');
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/users', ['username' => 'x', 'password' => 'p', 'role' => 'admin']), 422, 'VALIDATION_ERROR');
        $res = $this->as($this->admin)->postJson('/api/v1/users', ['username' => 'Ana ', 'password' => 'Ana-12345', 'role' => 'librarian'])->assertCreated();
        $this->assertSame('ana', $res->json('username'));
        $this->assertStringNotContainsString('password', $res->getContent());
        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/users', ['username' => 'ANA', 'password' => 'x', 'role' => 'librarian']), 409, 'USERNAME_TAKEN');

        $lib = $this->postJson('/api/v1/auth/login', ['username' => 'ana', 'password' => 'Ana-12345'])->assertOk()->assertJson(['token_type' => 'Bearer', 'expires_in' => 28800, 'role' => 'librarian'])->json('access_token');
        // 403 before checking whether the resource exists
        $this->assertErrorBody($this->as($lib)->deleteJson('/api/v1/books/00000000-0000-4000-8000-000000000000'), 403, 'FORBIDDEN_ROLE');
        $this->assertErrorBody($this->as($lib)->getJson('/api/v1/reports/most-borrowed?from=2026-01-01&to=2026-01-31'), 403, 'FORBIDDEN_ROLE');
        $this->as($lib)->postJson('/api/v1/members', ['full_name' => 'X', 'document_number' => '1'])->assertCreated();
    }

    public function test_books_catalog_rules(): void
    {
        $id = $this->book('9780307474728', 2);
        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/books', ['title' => 'B', 'author' => 'A', 'isbn' => '9780307474728', 'category_id' => self::NOVELA, 'total_copies' => 1]), 409, 'ISBN_TAKEN');
        $this->as($this->admin)->getJson('/api/v1/books?q=SOLEDAD')->assertOk()->assertJson(['page' => 1, 'size' => 20, 'total' => 1])->assertJsonPath('items.0.cover_key', null);
        $this->assertErrorBody($this->as($this->admin)->getJson('/api/v1/books?size=51'), 422, 'VALIDATION_ERROR');

        $this->as($this->admin)->deleteJson("/api/v1/books/{$id}")->assertNoContent();
        $this->assertErrorBody($this->as($this->admin)->getJson("/api/v1/books/{$id}"), 404, 'NOT_FOUND');
        $this->book('9780307474728'); // a deleted book's ISBN can be registered again
    }

    public function test_lend_return_overdue_and_frozen_report(): void
    {
        $b1 = $this->book('9780307474728', 1);
        $b2 = $this->book('9780553380163', 2, 'Breve historia del tiempo', self::CIENCIA);
        $m = $this->member('1075');

        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/loans', ['member_id' => $m, 'items' => [['book_id' => $b1], ['book_id' => $b1]]]), 422, 'DUPLICATE_BOOK_IN_LOAN');

        $loan = $this->as($this->admin)->postJson('/api/v1/loans', ['member_id' => $m, 'items' => [['book_id' => $b1], ['book_id' => $b2]]])->assertCreated();
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $loan->json('loaned_at'));
        $this->assertSame('open', $loan->json('status'));

        // No copies: 409 and nothing is recorded (the other book is not withdrawn)
        $m2 = $this->member('2000');
        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/loans', ['member_id' => $m2, 'items' => [['book_id' => $b2], ['book_id' => $b1]]]), 409, 'BOOK_NOT_AVAILABLE');
        $this->assertSame(1, $this->as($this->admin)->getJson("/api/v1/books/{$b2}")->json('available_copies'));

        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/loans', ['member_id' => $m, 'items' => [['book_id' => $this->book('9780000000017')], ['book_id' => $this->book('9780000000024')]]]), 422, 'MEMBER_LIMIT_EXCEEDED');
        $this->assertErrorBody($this->as($this->admin)->deleteJson("/api/v1/books/{$b1}"), 409, 'BOOK_HAS_OPEN_LOANS');

        $item = $loan->json('items.0.id');
        $this->as($this->admin)->postJson("/api/v1/loans/{$loan->json('id')}/items/{$item}/return")->assertOk();
        $this->assertErrorBody($this->as($this->admin)->postJson("/api/v1/loans/{$loan->json('id')}/items/{$item}/return"), 409, 'ITEM_ALREADY_RETURNED');

        // ADR-004: renaming after lending does not change the period's report
        $this->as($this->admin)->putJson("/api/v1/books/{$b1}", ['title' => 'Título nuevo', 'author' => 'GGM', 'category_id' => self::CIENCIA, 'total_copies' => 1])->assertOk();
        $today = gmdate('Y-m-d');
        $rows = $this->as($this->admin)->getJson("/api/v1/reports/most-borrowed?from={$today}&to={$today}")->assertOk()->json('rows');
        $this->assertContains('Cien años de soledad', array_column($rows, 'book_title'));
        $this->assertNotContains('Título nuevo', array_column($rows, 'book_title'));
        $this->assertArrayNotHasKey('member_name', $rows[0]);
        $this->assertErrorBody($this->as($this->admin)->getJson('/api/v1/reports/most-borrowed?from=2026-10-31&to=2026-10-01'), 422, 'INVALID_DATE_RANGE');

        // Overdue: forced by moving the clock (Clock port), not the database
        config(['library.clock_offset_days' => 20]);
        $this->app->forgetInstance(\App\Application\Ports\Clock::class);
        $over = $this->as($this->admin)->getJson('/api/v1/loans/overdue')->assertOk();
        $this->assertSame(1, $over->json('total'));
        $this->assertSame(6, $over->json('items.0.days_late'));
        $this->assertErrorBody($this->as($this->admin)->postJson('/api/v1/loans', ['member_id' => $m, 'items' => [['book_id' => $b1]]]), 409, 'MEMBER_HAS_OVERDUE');
        $this->as($this->admin)->getJson('/api/v1/loans?status=overdue')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_unknown_route_uses_single_error_body(): void
    {
        $this->assertErrorBody($this->as($this->admin)->getJson('/api/v1/nope'), 404, 'NOT_FOUND');
        $this->assertErrorBody($this->as($this->admin)->getJson('/api/v1/loans/no-es-uuid'), 404, 'NOT_FOUND');
    }
}
