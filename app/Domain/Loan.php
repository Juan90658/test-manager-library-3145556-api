<?php

namespace App\Domain;

/** Loan item: freezes title, ISBN and category at the moment of the loan (CA-06.6, RN-4). */
final class LoanItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $bookId,
        public readonly string $bookTitle,
        public readonly string $isbn,
        public readonly string $categoryName,
        public readonly \DateTimeImmutable $dueAt,
        public ?\DateTimeImmutable $returnedAt = null,
    ) {}

    public function isReturned(): bool { return $this->returnedAt !== null; }

    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return ! $this->isReturned() && $this->dueAt < $now;
    }
}

/**
 * Loan aggregate: an immutable fact except for each item's `returned_at`.
 * 1 to 3 items, no repeated book, due in 14 days. Status is computed (constitution, art. VII).
 */
final class Loan
{
    public const LOAN_DAYS = 14;
    public const MAX_ITEMS = 3;
    public const MAX_UNRETURNED_PER_MEMBER = 3;

    /** @var LoanItem[] */
    private array $items = [];

    public function __construct(
        public readonly string $id,
        public readonly string $memberId,
        public readonly string $loanedByUserId,
        public readonly \DateTimeImmutable $loanedAt,
    ) {}

    /**
     * Checks the member rules before lending (CA-06.4, CA-06.5).
     * Order matters: overdue first (409), then limit (422), as in architecture.md §4.
     */
    public static function assertMemberCanBorrow(int $unreturned, bool $hasOverdue, int $requested): void
    {
        if ($hasOverdue) {
            throw new MemberHasOverdue('El socio tiene libros vencidos y no puede pedir otro hasta devolverlos.');
        }
        if ($unreturned + $requested > self::MAX_UNRETURNED_PER_MEMBER) {
            throw new MemberLimitExceeded(
                "El socio ya tiene {$unreturned} libros sin devolver; el máximo es ".self::MAX_UNRETURNED_PER_MEMBER.'.',
                [['field' => 'items', 'issue' => "sin devolver actualmente: {$unreturned}"]],
            );
        }
    }

    /** @param string[] $bookIds */
    public static function assertItemsShape(array $bookIds): void
    {
        $n = count($bookIds);
        if ($n < 1 || $n > self::MAX_ITEMS) {
            throw new ValidationFailed('Un préstamo lleva de 1 a 3 libros.', [['field' => 'items', 'issue' => 'de 1 a 3 elementos']]);
        }
        $seen = [];
        foreach ($bookIds as $i => $id) {
            $key = strtolower($id);
            if (isset($seen[$key])) {
                throw new DuplicateBookInLoan('No se puede repetir el mismo libro en un préstamo.', [['field' => "items[{$i}].book_id", 'issue' => 'repetido']]);
            }
            $seen[$key] = true;
        }
    }

    /** Withdrawing the copy and creating the item are a single operation (architecture.md §3). */
    public function addItem(string $itemId, Book $book): LoanItem
    {
        if (count($this->items) >= self::MAX_ITEMS) {
            throw new ValidationFailed('Un préstamo lleva de 1 a 3 libros.', [['field' => 'items', 'issue' => 'máximo 3']]);
        }
        foreach ($this->items as $existing) {
            if ($existing->bookId === $book->id) {
                throw new DuplicateBookInLoan('No se puede repetir el mismo libro en un préstamo.');
            }
        }
        $book->withdraw();
        $item = new LoanItem(
            $itemId,
            $book->id,
            $book->title,
            $book->isbn->value,
            $book->categoryName,
            $this->loanedAt->modify('+'.self::LOAN_DAYS.' days'),
        );
        $this->items[] = $item;

        return $item;
    }

    /** Rehydration from persistence. */
    public function restoreItem(LoanItem $item): void
    {
        $this->items[] = $item;
    }

    public function returnItem(string $itemId, Book $book, \DateTimeImmutable $now): LoanItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $itemId) {
                if ($item->isReturned()) {
                    throw new ItemAlreadyReturned('Esa línea ya fue devuelta.');
                }
                $book->restock();
                $item->returnedAt = $now;

                return $item;
            }
        }
        throw new NotFound('La línea no pertenece a ese préstamo.');
    }

    /** @return LoanItem[] */
    public function items(): array { return $this->items; }

    /** open | overdue | closed — computed, never stored (CA-08.3). */
    public function status(\DateTimeImmutable $now): string
    {
        $pending = array_filter($this->items, fn (LoanItem $i) => ! $i->isReturned());
        if ($pending === []) {
            return 'closed';
        }
        foreach ($pending as $item) {
            if ($item->isOverdue($now)) {
                return 'overdue';
            }
        }

        return 'open';
    }
}
