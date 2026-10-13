<?php

namespace App\Adapters\Outbound\Persistence;

use App\Application\Ports;
use App\Domain\Book;
use App\Domain\DateRange;
use App\Domain\DocumentTaken;
use App\Domain\Isbn;
use App\Domain\IsbnTaken;
use App\Domain\Loan;
use App\Domain\LoanItem;
use App\Domain\UsernameTaken;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/*
 * Persistence adapters on PostgreSQL using Laravel's query builder.
 * Eloquent is deliberately not used: HasUuids generates UUID v7 (the contract asks for v4) and models expect
 * created_at/updated_at (the data model has none). Tables live in the `library` schema.
 */

final class Ts
{
    public static function db(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function parse(?string $v): ?\DateTimeImmutable
    {
        return $v === null ? null : (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function isUniqueViolation(QueryException $e, string $constraint): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), $constraint);
    }

    public static function isUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
    }

    /** Escapes LIKE wildcards so that "50%" matches literally. */
    public static function like(string $q): string
    {
        return '%'.addcslashes(trim($q), '\\%_').'%';
    }
}

final class PgTransactionRunner implements Ports\TransactionRunner
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}

final class PgUserRepository implements Ports\UserRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findByUsername(string $username): ?array
    {
        $row = $this->db->table('library.user')->where('username', $username)->first(['id', 'username', 'password_hash', 'role']);

        return $row ? (array) $row : null;
    }

    public function add(string $id, string $username, string $passwordHash, string $role): void
    {
        try {
            $this->db->table('library.user')->insert(['id' => $id, 'username' => $username, 'password_hash' => $passwordHash, 'role' => $role]);
        } catch (QueryException $e) {
            if (Ts::isUniqueViolation($e, 'username')) {
                throw new UsernameTaken('Ese nombre de usuario ya existe.', [['field' => 'username', 'issue' => 'repetido']]);
            }
            throw $e;
        }
    }
}

final class PgCategoryRepository implements Ports\CategoryRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function all(): array
    {
        return $this->db->table('library.category')->orderBy('name')->get(['id', 'name'])->map(fn ($r) => (array) $r)->all();
    }

    public function find(string $id): ?array
    {
        if (! Ts::isUuid($id)) {
            return null;
        }
        $row = $this->db->table('library.category')->where('id', $id)->first(['id', 'name']);

        return $row ? (array) $row : null;
    }
}

final class PgBookRepository implements Ports\BookRepository
{
    public function __construct(private ConnectionInterface $db) {}

    private function base()
    {
        // Global soft-delete filter: a single place (ADR-003).
        return $this->db->table('library.book as b')
            ->join('library.category as c', 'c.id', '=', 'b.category_id')
            ->whereNull('b.deleted_at')
            ->select('b.*', 'c.name as category_name');
    }

    private function map(object $r): Book
    {
        return new Book($r->id, $r->title, $r->author, Isbn::of($r->isbn), $r->category_id, $r->category_name,
            (int) $r->total_copies, (int) $r->available_copies, $r->cover_key, Ts::parse($r->deleted_at));
    }

    public function find(string $id): ?Book
    {
        if (! Ts::isUuid($id)) {
            return null;
        }
        $r = $this->base()->where('b.id', $id)->first();

        return $r ? $this->map($r) : null;
    }

    public function findManyActive(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($i) => Ts::isUuid($i)));
        $out = [];
        foreach ($this->base()->whereIn('b.id', $ids)->get() as $r) {
            $out[strtolower($r->id)] = $this->map($r);
        }

        return $out;
    }

    public function search(?string $q, ?string $categoryId, int $page, int $size): array
    {
        $query = $this->base();
        if ($q !== null && trim($q) !== '') {
            $like = Ts::like($q);
            $query->where(fn ($w) => $w->where('b.title', 'ilike', $like)->orWhere('b.author', 'ilike', $like));
        }
        if ($categoryId !== null) {
            $query->where('b.category_id', $categoryId);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('b.title')->orderBy('b.id')->offset(($page - 1) * $size)->limit($size)->get();

        return ['items' => $rows->map(fn ($r) => $this->map($r))->all(), 'total' => $total];
    }

    public function add(Book $book): void
    {
        try {
            $this->db->table('library.book')->insert([
                'id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'isbn' => $book->isbn->value,
                'category_id' => $book->categoryId, 'total_copies' => $book->totalCopies(),
                'available_copies' => $book->availableCopies(), 'cover_key' => null, 'deleted_at' => null,
            ]);
        } catch (QueryException $e) {
            if (Ts::isUniqueViolation($e, 'isbn')) {
                throw new IsbnTaken('Ya existe un libro activo con ese ISBN.', [['field' => 'isbn', 'issue' => 'repetido']]);
            }
            throw $e;
        }
    }

    public function update(Book $book, int $totalDelta): void
    {
        // Relative, conditional adjustment: a concurrent loan's change to available copies is never overwritten (ADR-002).
        $n = $this->db->update(
            'UPDATE library.book SET title = ?, author = ?, category_id = ?, total_copies = total_copies + ?, available_copies = available_copies + ?
             WHERE id = ? AND deleted_at IS NULL AND available_copies + ? >= 0',
            [$book->title, $book->author, $book->categoryId, $totalDelta, $totalDelta, $book->id, $totalDelta],
        );
        if ($n === 0) {
            throw new \App\Domain\ValidationFailed('El total no puede ser menor que los ejemplares prestados.', [['field' => 'total_copies', 'issue' => 'menor que los prestados']]);
        }
    }

    public function hasOpenLoans(string $bookId): bool
    {
        return $this->db->table('library.loan_item')->where('book_id', $bookId)->whereNull('returned_at')->exists();
    }

    public function markDeleted(string $bookId, \DateTimeImmutable $at): void
    {
        $this->db->table('library.book')->where('id', $bookId)->update(['deleted_at' => Ts::db($at)]);
    }

    public function setCoverKey(string $bookId, ?string $key): void
    {
        $this->db->table('library.book')->where('id', $bookId)->update(['cover_key' => $key]);
    }

    public function withdrawOne(string $bookId): bool
    {
        // Compare-and-set on the counter itself: the UPDATE only touches the row if a copy is still left.
        // The row stays locked until commit, so the second concurrent loan sees 0 and loses.
        return $this->db->update(
            'UPDATE library.book SET available_copies = available_copies - 1 WHERE id = ? AND deleted_at IS NULL AND available_copies >= 1',
            [$bookId],
        ) === 1;
    }

    public function restockOne(string $bookId): void
    {
        // No soft-delete filter: a book with open loans cannot be deleted, but a return must never fail because of it.
        $this->db->update('UPDATE library.book SET available_copies = available_copies + 1 WHERE id = ?', [$bookId]);
    }
}

final class PgMemberRepository implements Ports\MemberRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function find(string $id): ?array
    {
        if (! Ts::isUuid($id)) {
            return null;
        }
        $r = $this->db->table('library.member')->where('id', $id)->first(['id', 'full_name', 'document_number']);

        return $r ? (array) $r : null;
    }

    public function search(?string $q, int $page, int $size): array
    {
        $query = $this->db->table('library.member');
        if ($q !== null && trim($q) !== '') {
            $like = Ts::like($q);
            $query->where(fn ($w) => $w->where('full_name', 'ilike', $like)->orWhere('document_number', 'ilike', $like));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('full_name')->orderBy('id')->offset(($page - 1) * $size)->limit($size)->get(['id', 'full_name', 'document_number']);

        return ['items' => $rows->map(fn ($r) => (array) $r)->all(), 'total' => $total];
    }

    public function add(string $id, string $fullName, string $documentNumber): void
    {
        try {
            $this->db->table('library.member')->insert(['id' => $id, 'full_name' => $fullName, 'document_number' => $documentNumber]);
        } catch (QueryException $e) {
            if (Ts::isUniqueViolation($e, 'document_number')) {
                throw new DocumentTaken('Ya existe un socio con ese documento.', [['field' => 'document_number', 'issue' => 'repetido']]);
            }
            throw $e;
        }
    }

    public function lockForLending(string $memberId, \DateTimeImmutable $now): ?array
    {
        if (! Ts::isUuid($memberId)) {
            return null;
        }
        // Per-member lock (not library-wide): two simultaneous loans to the same member cannot bypass the max of 3.
        $exists = $this->db->selectOne('SELECT id FROM library.member WHERE id = ? FOR UPDATE', [$memberId]);
        if ($exists === null) {
            return null;
        }
        $r = $this->db->selectOne(
            'SELECT count(*) AS unreturned, coalesce(bool_or(li.due_at < ?), false) AS has_overdue
             FROM library.loan_item li JOIN library.loan l ON l.id = li.loan_id
             WHERE l.member_id = ? AND li.returned_at IS NULL',
            [Ts::db($now), $memberId],
        );

        return ['unreturned' => (int) $r->unreturned, 'has_overdue' => (bool) $r->has_overdue];
    }

    public function unreturnedCount(string $memberId): int
    {
        return (int) $this->db->selectOne(
            'SELECT count(*) AS n FROM library.loan_item li JOIN library.loan l ON l.id = li.loan_id WHERE l.member_id = ? AND li.returned_at IS NULL',
            [$memberId],
        )->n;
    }
}

final class PgLoanRepository implements Ports\LoanRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function add(Loan $loan): void
    {
        $this->db->table('library.loan')->insert([
            'id' => $loan->id, 'member_id' => $loan->memberId, 'loaned_by_user_id' => $loan->loanedByUserId, 'loaned_at' => Ts::db($loan->loanedAt),
        ]);
        foreach ($loan->items() as $i) {
            $this->db->table('library.loan_item')->insert([
                'id' => $i->id, 'loan_id' => $loan->id, 'book_id' => $i->bookId, 'book_title' => $i->bookTitle, 'isbn' => $i->isbn,
                'category_name' => $i->categoryName, 'due_at' => Ts::db($i->dueAt), 'returned_at' => null,
            ]);
        }
    }

    public function find(string $id): ?Loan
    {
        if (! Ts::isUuid($id)) {
            return null;
        }
        $r = $this->db->table('library.loan')->where('id', $id)->first();
        if (! $r) {
            return null;
        }

        return $this->hydrate([$r])[0];
    }

    /** Loads the items of several loans in one query (no N+1). @return Loan[] */
    private function hydrate(iterable $rows): array
    {
        $loans = [];
        foreach ($rows as $r) {
            $loans[$r->id] = new Loan($r->id, $r->member_id, $r->loaned_by_user_id, Ts::parse($r->loaned_at));
        }
        if ($loans === []) {
            return [];
        }
        $items = $this->db->table('library.loan_item')->whereIn('loan_id', array_keys($loans))->orderBy('due_at')->orderBy('book_title')->get();
        foreach ($items as $i) {
            $loans[$i->loan_id]->restoreItem(new LoanItem($i->id, $i->book_id, $i->book_title, $i->isbn, $i->category_name, Ts::parse($i->due_at), Ts::parse($i->returned_at)));
        }

        return array_values($loans);
    }

    public function markItemReturned(string $loanId, string $itemId, \DateTimeImmutable $at): bool
    {
        return $this->db->update(
            'UPDATE library.loan_item SET returned_at = ? WHERE id = ? AND loan_id = ? AND returned_at IS NULL',
            [Ts::db($at), $itemId, $loanId],
        ) === 1;
    }

    public function list(array $filters, \DateTimeImmutable $now, int $page, int $size): array
    {
        $q = $this->db->table('library.loan as l');
        if (isset($filters['from'])) {
            $q->where('l.loaned_at', '>=', Ts::db($filters['from']));
        }
        if (isset($filters['to_exclusive'])) {
            $q->where('l.loaned_at', '<', Ts::db($filters['to_exclusive']));
        }
        if (isset($filters['member_id'])) {
            $q->where('l.member_id', $filters['member_id']);
        }
        if (isset($filters['status'])) {
            // Status is computed in the query; there is no column (art. VII).
            $nowDb = Ts::db($now);
            $pending = 'SELECT 1 FROM library.loan_item x WHERE x.loan_id = l.id AND x.returned_at IS NULL';
            match ($filters['status']) {
                'closed' => $q->whereRaw("NOT EXISTS ({$pending})"),
                'overdue' => $q->whereRaw("EXISTS ({$pending} AND x.due_at < ?)", [$nowDb]),
                'open' => $q->whereRaw("EXISTS ({$pending})")->whereRaw("NOT EXISTS ({$pending} AND x.due_at < ?)", [$nowDb]),
            };
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('l.loaned_at')->orderBy('l.id')->offset(($page - 1) * $size)->limit($size)->get(['l.*']);

        return ['items' => $this->hydrate($rows), 'total' => $total];
    }
}

final class PgReportReader implements Ports\ReportReader
{
    public function __construct(private ConnectionInterface $db) {}

    public function mostBorrowed(DateRange $range): array
    {
        // ADR-004: a single aggregate query grouped by the item's FROZEN values; never joins the live catalog.
        $rows = $this->db->select(
            'SELECT li.book_id, li.book_title, li.category_name, count(*) AS times_borrowed
             FROM library.loan_item li JOIN library.loan l ON l.id = li.loan_id
             WHERE l.loaned_at >= ? AND l.loaned_at < ?
             GROUP BY li.book_id, li.book_title, li.category_name
             ORDER BY times_borrowed DESC, li.book_title ASC, li.category_name ASC',
            [Ts::db($range->from), Ts::db($range->toExclusive())],
        );

        return array_map(fn ($r) => [
            'book_id' => $r->book_id, 'book_title' => $r->book_title, 'category_name' => $r->category_name, 'times_borrowed' => (int) $r->times_borrowed,
        ], $rows);
    }

    public function overdue(\DateTimeImmutable $now, int $page, int $size): array
    {
        $nowDb = Ts::db($now);
        $total = (int) $this->db->selectOne('SELECT count(*) AS n FROM library.loan_item WHERE returned_at IS NULL AND due_at < ?', [$nowDb])->n;
        $rows = $this->db->select(
            'SELECT li.loan_id, li.id AS item_id, li.book_title, m.full_name AS member_name, li.due_at,
                    floor(extract(epoch FROM (?::timestamptz - li.due_at)) / 86400)::int AS days_late
             FROM library.loan_item li
             JOIN library.loan l ON l.id = li.loan_id
             JOIN library.member m ON m.id = l.member_id
             WHERE li.returned_at IS NULL AND li.due_at < ?
             ORDER BY li.due_at ASC, li.id
             OFFSET ? LIMIT ?',
            [$nowDb, $nowDb, ($page - 1) * $size, $size],
        );

        return ['items' => array_map(fn ($r) => [
            'loan_id' => $r->loan_id, 'item_id' => $r->item_id, 'book_title' => $r->book_title, 'member_name' => $r->member_name,
            'due_at' => Ts::parse($r->due_at), 'days_late' => (int) $r->days_late,
        ], $rows), 'total' => $total];
    }
}
