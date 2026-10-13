<?php

namespace App\Domain;

/**
 * Book aggregate. Guards: 0 <= available <= total, total >= 1, non-empty title (architecture.md §3).
 * Plain PHP only: the domain does not depend on Laravel (constitution, art. I).
 */
final class Book
{
    public function __construct(
        public readonly string $id,
        public string $title,
        public string $author,
        public readonly Isbn $isbn,
        public string $categoryId,
        public string $categoryName,
        private int $totalCopies,
        private int $availableCopies,
        public ?string $coverKey = null,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {
        self::assertTitle($title);
        Quantity::of($totalCopies);
        if ($availableCopies < 0 || $availableCopies > $totalCopies) {
            throw new \LogicException('Available copies out of range.');
        }
    }

    /** CA-03.1: available copies start equal to total. */
    public static function create(string $id, string $title, string $author, Isbn $isbn, string $categoryId, string $categoryName, Quantity $total): self
    {
        return new self($id, trim($title), trim($author), $isbn, $categoryId, $categoryName, $total->value, $total->value);
    }

    public function totalCopies(): int { return $this->totalCopies; }
    public function availableCopies(): int { return $this->availableCopies; }
    public function isActive(): bool { return $this->deletedAt === null; }

    /** CA-03.3: changing the total shifts available copies by the same difference; never < 0. */
    public function changeTotal(Quantity $newTotal): void
    {
        $newAvailable = $this->availableCopies + ($newTotal->value - $this->totalCopies);
        if ($newAvailable < 0) {
            $lent = $this->totalCopies - $this->availableCopies;
            throw new ValidationFailed(
                "El total no puede ser menor que los {$lent} ejemplares prestados.",
                [['field' => 'total_copies', 'issue' => "mínimo {$lent}"]],
            );
        }
        $this->totalCopies = $newTotal->value;
        $this->availableCopies = $newAvailable;
    }

    public function rename(string $title, string $author, string $categoryId, string $categoryName): void
    {
        self::assertTitle($title);
        $this->title = trim($title);
        $this->author = trim($author);
        $this->categoryId = $categoryId;
        $this->categoryName = $categoryName;
    }

    /** Withdraws one copy (CA-06.3). */
    public function withdraw(): void
    {
        if (! $this->isActive()) {
            throw new NotFound('El libro no existe o fue dado de baja.');
        }
        if ($this->availableCopies < 1) {
            throw new BookNotAvailable("No quedan ejemplares disponibles de «{$this->title}».", [['field' => 'book_id', 'issue' => 'sin ejemplares']]);
        }
        $this->availableCopies--;
    }

    /** Restocks one copy; never exceeds total (CA-07.3). */
    public function restock(): void
    {
        if ($this->availableCopies >= $this->totalCopies) {
            throw new \LogicException('Available copies cannot exceed total.');
        }
        $this->availableCopies++;
    }

    public function softDelete(\DateTimeImmutable $now, bool $hasOpenLoans): void
    {
        if ($hasOpenLoans) {
            throw new BookHasOpenLoans("«{$this->title}» tiene préstamos sin devolver y no se puede dar de baja.");
        }
        $this->deletedAt = $now;
    }

    private static function assertTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new ValidationFailed('El título es obligatorio.', [['field' => 'title', 'issue' => 'vacío']]);
        }
    }
}
