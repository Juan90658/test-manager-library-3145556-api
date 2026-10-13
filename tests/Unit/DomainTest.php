<?php

namespace Tests\Unit;

use App\Domain\Book;
use App\Domain\BookNotAvailable;
use App\Domain\DateRange;
use App\Domain\DuplicateBookInLoan;
use App\Domain\InvalidDateRange;
use App\Domain\Isbn;
use App\Domain\ItemAlreadyReturned;
use App\Domain\Loan;
use App\Domain\MemberHasOverdue;
use App\Domain\MemberLimitExceeded;
use App\Domain\Quantity;
use App\Domain\Username;
use App\Domain\ValidationFailed;
use PHPUnit\Framework\TestCase;

/** T-03 to T-06: domain invariants with no DB, no server and no Laravel (constitution, art. I). */
final class DomainTest extends TestCase
{
    private function book(int $total = 2, string $id = 'b1'): Book
    {
        return Book::create($id, ' Cien años ', 'GGM', Isbn::of('9780307474728'), 'c1', 'Novela', Quantity::of($total));
    }

    private function at(string $t): \DateTimeImmutable
    {
        return new \DateTimeImmutable($t, new \DateTimeZone('UTC'));
    }

    public function test_isbn_requires_13_digits(): void
    {
        $this->assertSame('9780307474728', Isbn::of('9780307474728')->value);
        foreach (['978030747472', '97803074747281', '978-030747472', 'abcdefghijklm'] as $bad) {
            try {
                Isbn::of($bad);
                $this->fail("Accepted {$bad}");
            } catch (ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quantity_must_be_positive(): void
    {
        $this->expectException(ValidationFailed::class);
        Quantity::of(0);
    }

    public function test_date_range_end_not_before_start(): void
    {
        $r = DateRange::of($this->at('2026-10-01'), $this->at('2026-10-31'));
        $this->assertSame('2026-11-01', $r->toExclusive()->format('Y-m-d'), 'to is inclusive: the exclusive bound is the next day');
        $this->expectException(InvalidDateRange::class);
        DateRange::of($this->at('2026-10-31'), $this->at('2026-10-01'));
    }

    public function test_new_book_starts_with_available_equal_total_and_trimmed_title(): void
    {
        $b = $this->book(4);
        $this->assertSame(4, $b->availableCopies());
        $this->assertSame('Cien años', $b->title);
    }

    public function test_withdraw_never_below_zero_and_restock_never_above_total(): void
    {
        $b = $this->book(1);
        $b->withdraw();
        $this->assertSame(0, $b->availableCopies());
        try {
            $b->withdraw();
            $this->fail('Lent with no copies left');
        } catch (BookNotAvailable) {
        }
        $b->restock();
        $this->expectException(\LogicException::class);
        $b->restock();
    }

    public function test_change_total_moves_available_by_same_difference_and_rejects_negative(): void
    {
        $b = $this->book(3);
        $b->withdraw();
        $b->withdraw();           // 3 total, 1 available
        $b->changeTotal(Quantity::of(5));
        $this->assertSame(3, $b->availableCopies());
        $this->expectException(ValidationFailed::class);
        $b->changeTotal(Quantity::of(1)); // would leave available at -1
    }

    public function test_loan_due_in_14_days_freezes_values_and_status_is_computed(): void
    {
        $book = $this->book(2);
        $loan = new Loan('l1', 'm1', 'u1', $this->at('2026-10-14T14:15:00Z'));
        $item = $loan->addItem('i1', $book);
        $this->assertSame('2026-10-28T14:15:00', $item->dueAt->format('Y-m-d\TH:i:s'));
        $this->assertSame(1, $book->availableCopies(), 'withdrawing and creating the item is one operation');

        $book->rename('Otro título', 'GGM', 'c2', 'Ciencia');
        $this->assertSame('Cien años', $item->bookTitle, 'the item does not follow the catalog');
        $this->assertSame('Novela', $item->categoryName);

        $this->assertSame('open', $loan->status($this->at('2026-10-28T14:14:59Z')));
        $this->assertSame('overdue', $loan->status($this->at('2026-10-28T14:15:01Z')));
        $loan->returnItem('i1', $book, $this->at('2026-10-29T00:00:00Z'));
        $this->assertSame('closed', $loan->status($this->at('2026-11-30T00:00:00Z')));
        $this->assertSame(2, $book->availableCopies());

        $this->expectException(ItemAlreadyReturned::class);
        $loan->returnItem('i1', $book, $this->at('2026-10-30T00:00:00Z'));
    }

    public function test_loan_has_1_to_3_items_without_duplicates(): void
    {
        Loan::assertItemsShape(['a']);
        Loan::assertItemsShape(['a', 'b', 'c']);
        foreach ([[], ['a', 'b', 'c', 'd']] as $bad) {
            try {
                Loan::assertItemsShape($bad);
                $this->fail('Accepted '.count($bad).' items');
            } catch (ValidationFailed) {
            }
        }
        $this->expectException(DuplicateBookInLoan::class);
        Loan::assertItemsShape(['a', 'A']);
    }

    public function test_member_rules_overdue_first_then_limit_of_three(): void
    {
        Loan::assertMemberCanBorrow(2, false, 1);
        try {
            Loan::assertMemberCanBorrow(3, true, 1);
            $this->fail();
        } catch (MemberHasOverdue) {
        }
        $this->expectException(MemberLimitExceeded::class);
        Loan::assertMemberCanBorrow(2, false, 2);
    }

    public function test_username_is_trimmed_and_lowercased(): void
    {
        $this->assertSame('ana', Username::of('Ana ')->value);
    }
}
