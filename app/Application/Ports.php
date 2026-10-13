<?php

namespace App\Application\Ports;

use App\Domain\Book;
use App\Domain\DateRange;
use App\Domain\Loan;

/*
 * Outbound ports. Defined by their consumer (DIP, constitution art. IV).
 * No Laravel or database type appears in their signatures (art. II).
 */

interface Clock
{
    public function now(): \DateTimeImmutable;
}

interface IdGenerator
{
    /** UUID v4 as text (api-contract §1). */
    public function next(): string;
}

interface PasswordHasher
{
    public function hash(string $plain): string;

    public function verify(string $plain, string $hash): bool;
}

interface TokenIssuer
{
    /** @return array{access_token: string, expires_in: int} */
    public function issue(string $userId, string $role): array;

    /** @return array{sub: string, role: string}|null  null if invalid or expired */
    public function verify(string $token): ?array;
}

interface CoverStorage
{
    public function put(string $bytes, string $extension): string;

    public function get(string $key): ?string;

    public function delete(string $key): void;
}

interface TransactionRunner
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}

interface UserRepository
{
    /** @return array{id: string, username: string, password_hash: string, role: string}|null */
    public function findByUsername(string $username): ?array;

    /** @throws \App\Domain\UsernameTaken */
    public function add(string $id, string $username, string $passwordHash, string $role): void;
}

interface CategoryRepository
{
    /** @return array<int, array{id: string, name: string}> */
    public function all(): array;

    /** @return array{id: string, name: string}|null */
    public function find(string $id): ?array;
}

interface BookRepository
{
    /** Active books only (global soft-delete filter, ADR-003). */
    public function find(string $id): ?Book;

    /** Q3: active books by batch. @param string[] $ids @return array<string, Book> keyed by id */
    public function findManyActive(array $ids): array;

    /** Q1. @return array{items: Book[], total: int} */
    public function search(?string $q, ?string $categoryId, int $page, int $size): array;

    /** @throws \App\Domain\IsbnTaken */
    public function add(Book $book): void;

    /** Saves title, author, category and total; fails if available copies would drop below 0 due to a concurrent write. */
    public function update(Book $book, int $totalDelta): void;

    public function hasOpenLoans(string $bookId): bool;

    public function markDeleted(string $bookId, \DateTimeImmutable $at): void;

    public function setCoverKey(string $bookId, ?string $key): void;

    /**
     * Withdraws one copy with optimistic control (ADR-002): only if one is still available.
     * @return bool false if another loan took the last copy
     */
    public function withdrawOne(string $bookId): bool;

    public function restockOne(string $bookId): void;
}

interface MemberRepository
{
    /** @return array{id: string, full_name: string, document_number: string}|null */
    public function find(string $id): ?array;

    /** @return array{items: array<int, array{id: string, full_name: string, document_number: string}>, total: int} */
    public function search(?string $q, int $page, int $size): array;

    /** @throws \App\Domain\DocumentTaken */
    public function add(string $id, string $fullName, string $documentNumber): void;

    /** Q7: locks the member row inside the transaction and counts unreturned items. @return array{unreturned: int, has_overdue: bool}|null */
    public function lockForLending(string $memberId, \DateTimeImmutable $now): ?array;

    public function unreturnedCount(string $memberId): int;
}

interface LoanRepository
{
    public function add(Loan $loan): void;

    public function find(string $id): ?Loan;

    /** Marks the item returned only if it was not. @return bool false if it was already returned */
    public function markItemReturned(string $loanId, string $itemId, \DateTimeImmutable $at): bool;

    /** Q6. @param array{from?: \DateTimeImmutable, to_exclusive?: \DateTimeImmutable, status?: string, member_id?: string} $filters @return array{items: Loan[], total: int} */
    public function list(array $filters, \DateTimeImmutable $now, int $page, int $size): array;
}

interface ReportReader
{
    /** Q9 (ADR-004). @return array<int, array{book_id: string, book_title: string, category_name: string, times_borrowed: int}> */
    public function mostBorrowed(DateRange $range): array;

    /** Q8. @return array{items: array<int, array<string, mixed>>, total: int} */
    public function overdue(\DateTimeImmutable $now, int $page, int $size): array;
}
