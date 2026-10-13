<?php

namespace App\Domain;

/**
 * Domain and application errors. They know nothing about HTTP:
 * only the HTTP adapter maps them to a status code and the single error body (architecture.md §5).
 */
abstract class DomainError extends \RuntimeException
{
    /** @param array<int, array{field: string, issue: string}> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** Business error code (api-contract §2). */
    abstract public function errorCode(): string;
}

final class InvalidCredentials extends DomainError { public function errorCode(): string { return 'INVALID_CREDENTIALS'; } }
final class NotFound extends DomainError { public function errorCode(): string { return 'NOT_FOUND'; } }
final class UsernameTaken extends DomainError { public function errorCode(): string { return 'USERNAME_TAKEN'; } }
final class IsbnTaken extends DomainError { public function errorCode(): string { return 'ISBN_TAKEN'; } }
final class DocumentTaken extends DomainError { public function errorCode(): string { return 'DOCUMENT_TAKEN'; } }
final class BookHasOpenLoans extends DomainError { public function errorCode(): string { return 'BOOK_HAS_OPEN_LOANS'; } }
final class BookNotAvailable extends DomainError { public function errorCode(): string { return 'BOOK_NOT_AVAILABLE'; } }
final class MemberHasOverdue extends DomainError { public function errorCode(): string { return 'MEMBER_HAS_OVERDUE'; } }
final class ItemAlreadyReturned extends DomainError { public function errorCode(): string { return 'ITEM_ALREADY_RETURNED'; } }
final class MemberLimitExceeded extends DomainError { public function errorCode(): string { return 'MEMBER_LIMIT_EXCEEDED'; } }
final class DuplicateBookInLoan extends DomainError { public function errorCode(): string { return 'DUPLICATE_BOOK_IN_LOAN'; } }
final class ValidationFailed extends DomainError { public function errorCode(): string { return 'VALIDATION_ERROR'; } }
final class InvalidDateRange extends DomainError { public function errorCode(): string { return 'INVALID_DATE_RANGE'; } }
