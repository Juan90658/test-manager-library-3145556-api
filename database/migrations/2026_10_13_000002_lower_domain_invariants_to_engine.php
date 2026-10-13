<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T-18 · Move to the engine the invariants marked "pending (T-18) · domain only" (data-model.md §4).
 * It is its own migration, not part of the initial one: the history shows when they moved from domain to engine,
 * and data-model.md §4 is updated in the same change (constitution, art. VI and X).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE library.book   ADD CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1);
ALTER TABLE library."user" ADD CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE library.book   DROP CONSTRAINT IF EXISTS ck_book_total_positive;
ALTER TABLE library."user" DROP CONSTRAINT IF EXISTS ck_user_role;
SQL);
    }
};
