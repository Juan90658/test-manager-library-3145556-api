<?php

namespace App\Application\Services;

use App\Application\Ports\BookRepository;
use App\Application\Ports\CategoryRepository;
use App\Application\Ports\Clock;
use App\Application\Ports\CoverStorage;
use App\Application\Ports\IdGenerator;
use App\Application\Ports\LoanRepository;
use App\Application\Ports\MemberRepository;
use App\Application\Ports\PasswordHasher;
use App\Application\Ports\ReportReader;
use App\Application\Ports\TokenIssuer;
use App\Application\Ports\TransactionRunner;
use App\Application\Ports\UserRepository;
use App\Domain\Book;
use App\Domain\BookNotAvailable;
use App\Domain\DateRange;
use App\Domain\InvalidCredentials;
use App\Domain\Isbn;
use App\Domain\Loan;
use App\Domain\LoanItem;
use App\Domain\NotFound;
use App\Domain\Quantity;
use App\Domain\Role;
use App\Domain\Username;
use App\Domain\ValidationFailed;

/*
 * Use cases. One per class (SRP). They only know the domain and the ports (constitution, art. II).
 */

/** HU-01 */
final class LoginService
{
    public function __construct(private UserRepository $users, private PasswordHasher $hasher, private TokenIssuer $tokens) {}

    /** @return array{access_token: string, token_type: string, expires_in: int, role: string} */
    public function login(string $username, string $password): array
    {
        $user = $this->users->findByUsername(mb_strtolower(trim($username)));
        // CA-01.2: same message for unknown user and wrong password.
        if ($user === null || ! $this->hasher->verify($password, $user['password_hash'])) {
            throw new InvalidCredentials('Usuario o contraseña incorrectos.');
        }
        $token = $this->tokens->issue($user['id'], $user['role']);

        return ['access_token' => $token['access_token'], 'token_type' => 'Bearer', 'expires_in' => $token['expires_in'], 'role' => $user['role']];
    }
}

/** HU-02 */
final class CreateLibrarianService
{
    public function __construct(private UserRepository $users, private PasswordHasher $hasher, private IdGenerator $ids) {}

    /** @return array{id: string, username: string, role: string} */
    public function create(string $username, string $password, string $role): array
    {
        // CA-02.3: requesting admin through the API is a broken input rule -> 422. Nobody grants admin at runtime.
        if ($role !== Role::LIBRARIAN) {
            throw new ValidationFailed('Solo se pueden crear usuarios con rol librarian.', [['field' => 'role', 'issue' => 'solo admite librarian']]);
        }
        if ($password === '') {
            throw new ValidationFailed('La contraseña es obligatoria.', [['field' => 'password', 'issue' => 'vacía']]);
        }
        $name = Username::of($username);
        $id = $this->ids->next();
        $this->users->add($id, $name->value, $this->hasher->hash($password), Role::LIBRARIAN);

        return ['id' => $id, 'username' => $name->value, 'role' => Role::LIBRARIAN];
    }
}

/** Startup (plan D-10): the initial admin comes from the environment, never from the DB or the repo. */
final class BootstrapAdminService
{
    public function __construct(private UserRepository $users, private PasswordHasher $hasher, private IdGenerator $ids) {}

    public function ensure(string $username, string $password): bool
    {
        $name = Username::of($username);
        if ($this->users->findByUsername($name->value) !== null) {
            return false;
        }
        $this->users->add($this->ids->next(), $name->value, $this->hasher->hash($password), Role::ADMIN);

        return true;
    }
}

/** HU-03: create, edit and soft-delete books. */
final class CreateBookService
{
    public function __construct(private BookRepository $books, private CategoryRepository $categories, private IdGenerator $ids) {}

    public function create(string $title, string $author, string $isbn, string $categoryId, int $total): Book
    {
        $category = $this->categories->find($categoryId)
            ?? throw new ValidationFailed('La categoría no existe.', [['field' => 'category_id', 'issue' => 'no existe']]);
        $book = Book::create($this->ids->next(), $title, $author, Isbn::of($isbn), $category['id'], $category['name'], Quantity::of($total));
        $this->books->add($book);

        return $book;
    }
}

final class UpdateBookService
{
    public function __construct(private BookRepository $books, private CategoryRepository $categories, private TransactionRunner $tx) {}

    public function update(string $id, string $title, string $author, string $categoryId, int $total, ?string $isbn): Book
    {
        return $this->tx->run(function () use ($id, $title, $author, $categoryId, $total, $isbn) {
            $book = $this->books->find($id) ?? throw new NotFound('El libro no existe o fue dado de baja.');
            // PUT replaces title, author, category and total (api-contract §3.4). The ISBN is immutable.
            if ($isbn !== null && trim($isbn) !== $book->isbn->value) {
                throw new ValidationFailed('El ISBN no se puede modificar.', [['field' => 'isbn', 'issue' => 'no editable']]);
            }
            $category = $this->categories->find($categoryId)
                ?? throw new ValidationFailed('La categoría no existe.', [['field' => 'category_id', 'issue' => 'no existe']]);
            $delta = $total - $book->totalCopies();
            $book->rename($title, $author, $category['id'], $category['name']);
            $book->changeTotal(Quantity::of($total));
            $this->books->update($book, $delta);

            return $book;
        });
    }
}

final class DeleteBookService
{
    public function __construct(private BookRepository $books, private Clock $clock, private TransactionRunner $tx) {}

    public function delete(string $id): void
    {
        $this->tx->run(function () use ($id) {
            $book = $this->books->find($id) ?? throw new NotFound('El libro no existe o fue dado de baja.');
            $now = $this->clock->now();
            $book->softDelete($now, $this->books->hasOpenLoans($id));
            $this->books->markDeleted($id, $now);
        });
    }
}

/** CA-03.4 and data-model §7: mandatory order, first update/null the key and commit; only then delete the binary. */
final class BookCoverService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private BookRepository $books, private CoverStorage $storage, private TransactionRunner $tx) {}

    public function upload(string $bookId, string $bytes, string $detectedMime): Book
    {
        $book = $this->books->find($bookId) ?? throw new NotFound('El libro no existe o fue dado de baja.');
        $ext = match ($detectedMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new ValidationFailed('La portada debe ser JPG o PNG.', [['field' => 'file', 'issue' => 'tipo no permitido']]),
        };
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ValidationFailed('La portada no puede superar 2 MB.', [['field' => 'file', 'issue' => 'máximo 2 MB']]);
        }
        $old = $book->coverKey;
        $key = $this->storage->put($bytes, $ext);
        $this->tx->run(fn () => $this->books->setCoverKey($bookId, $key));
        if ($old !== null) {
            $this->storage->delete($old);
        }
        $book->coverKey = $key;

        return $book;
    }

    public function remove(string $bookId): void
    {
        $book = $this->books->find($bookId) ?? throw new NotFound('El libro no existe o fue dado de baja.');
        if ($book->coverKey === null) {
            return;
        }
        $this->tx->run(fn () => $this->books->setCoverKey($bookId, null));
        $this->storage->delete($book->coverKey);
    }

    /** @return array{bytes: string, mime: string} */
    public function read(string $bookId): array
    {
        $book = $this->books->find($bookId) ?? throw new NotFound('El libro no existe o fue dado de baja.');
        $bytes = $book->coverKey === null ? null : $this->storage->get($book->coverKey);
        if ($bytes === null) {
            throw new NotFound('El libro no tiene portada.');
        }

        return ['bytes' => $bytes, 'mime' => str_ends_with($book->coverKey, '.png') ? 'image/png' : 'image/jpeg'];
    }
}

/** HU-04 */
final class SearchBooksService
{
    public function __construct(private BookRepository $books) {}

    /** @return array{items: Book[], total: int} */
    public function search(?string $q, ?string $categoryId, int $page, int $size): array
    {
        return $this->books->search($q, $categoryId, $page, $size);
    }

    public function get(string $id): Book
    {
        return $this->books->find($id) ?? throw new NotFound('El libro no existe o fue dado de baja.');
    }
}

/** HU-06 */
final class LendBooksService
{
    public function __construct(
        private BookRepository $books,
        private MemberRepository $members,
        private LoanRepository $loans,
        private Clock $clock,
        private IdGenerator $ids,
        private TransactionRunner $tx,
    ) {}

    /** @param string[] $bookIds */
    public function lend(string $memberId, array $bookIds, string $userId): Loan
    {
        Loan::assertItemsShape($bookIds);

        return $this->tx->run(function () use ($memberId, $bookIds, $userId) {
            $now = $this->clock->now();
            $state = $this->members->lockForLending($memberId, $now)
                ?? throw new NotFound('El socio no existe.', [['field' => 'member_id', 'issue' => 'no existe']]);
            Loan::assertMemberCanBorrow($state['unreturned'], $state['has_overdue'], count($bookIds));

            $books = $this->books->findManyActive($bookIds);
            $loan = new Loan($this->ids->next(), $memberId, $userId, $now);
            foreach (array_values($bookIds) as $i => $bookId) {
                $book = $books[strtolower($bookId)]
                    ?? throw new NotFound('El libro no existe o fue dado de baja.', [['field' => "items[{$i}].book_id", 'issue' => 'no existe']]);
                try {
                    $loan->addItem($this->ids->next(), $book);
                } catch (BookNotAvailable $e) {
                    throw new BookNotAvailable($e->getMessage(), [['field' => "items[{$i}].book_id", 'issue' => 'sin ejemplares']]);
                }
                // ADR-002: if another loan took the copy between read and write, 409 and nothing is recorded.
                if (! $this->books->withdrawOne($book->id)) {
                    throw new BookNotAvailable("No quedan ejemplares disponibles de «{$book->title}».", [['field' => "items[{$i}].book_id", 'issue' => 'sin ejemplares']]);
                }
            }
            $this->loans->add($loan);

            return $loan;
        });
    }
}

/** HU-07 */
final class ReturnItemService
{
    public function __construct(private LoanRepository $loans, private BookRepository $books, private Clock $clock, private TransactionRunner $tx) {}

    public function return(string $loanId, string $itemId): LoanItem
    {
        return $this->tx->run(function () use ($loanId, $itemId) {
            $loan = $this->loans->find($loanId) ?? throw new NotFound('El préstamo no existe.');
            $item = null;
            foreach ($loan->items() as $candidate) {
                if ($candidate->id === strtolower($itemId)) {
                    $item = $candidate;
                }
            }
            if ($item === null) {
                throw new NotFound('La línea no pertenece a ese préstamo.');
            }
            if ($item->isReturned()) {
                throw new \App\Domain\ItemAlreadyReturned('Esa línea ya fue devuelta.');
            }
            $now = $this->clock->now();
            // CA-07.2: the conditional write (returned_at IS NULL) prevents a double restock even with two simultaneous returns.
            if (! $this->loans->markItemReturned($loanId, $item->id, $now)) {
                throw new \App\Domain\ItemAlreadyReturned('Esa línea ya fue devuelta.');
            }
            $this->books->restockOne($item->bookId);
            $item->returnedAt = $now;

            return $item;
        });
    }
}

/** HU-08 */
final class LoanQueryService
{
    public function __construct(private LoanRepository $loans, private Clock $clock) {}

    public function get(string $id): Loan
    {
        return $this->loans->find($id) ?? throw new NotFound('El préstamo no existe.');
    }

    /** @return array{items: Loan[], total: int} */
    public function list(?DateRange $range, ?string $status, ?string $memberId, int $page, int $size): array
    {
        $filters = array_filter([
            'from' => $range?->from,
            'to_exclusive' => $range?->toExclusive(),
            'status' => $status,
            'member_id' => $memberId,
        ]);

        return $this->loans->list($filters, $this->clock->now(), $page, $size);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}

/** HU-09 */
final class OverdueService
{
    public function __construct(private ReportReader $reader, private Clock $clock) {}

    public function list(int $page, int $size): array
    {
        return $this->reader->overdue($this->clock->now(), $page, $size);
    }
}

/** HU-10 (ADR-004) */
final class MostBorrowedReportService
{
    public function __construct(private ReportReader $reader) {}

    public function run(DateRange $range): array
    {
        return $this->reader->mostBorrowed($range);
    }
}

/** HU-05 */
final class MemberService
{
    public function __construct(private MemberRepository $members, private IdGenerator $ids) {}

    public function create(string $fullName, string $document): array
    {
        $fullName = trim($fullName);
        $document = trim($document);
        if ($fullName === '' || $document === '') {
            throw new ValidationFailed('Nombre y documento son obligatorios.', [['field' => $fullName === '' ? 'full_name' : 'document_number', 'issue' => 'vacío']]);
        }
        $id = $this->ids->next();
        $this->members->add($id, $fullName, $document);

        return ['id' => $id, 'full_name' => $fullName, 'document_number' => $document];
    }

    public function get(string $id): array
    {
        $member = $this->members->find($id) ?? throw new NotFound('El socio no existe.');

        return $member + ['active_loans' => $this->members->unreturnedCount($id)];
    }

    public function search(?string $q, int $page, int $size): array
    {
        return $this->members->search($q, $page, $size);
    }
}
