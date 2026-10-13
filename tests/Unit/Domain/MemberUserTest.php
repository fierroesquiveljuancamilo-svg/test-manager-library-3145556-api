<?php

namespace Tests\Unit\Domain;

use App\Domain\Book;
use App\Domain\DomainError;
use App\Domain\Isbn;
use App\Domain\Loan;
use App\Domain\Member;
use App\Domain\MemberHasOverdue;
use App\Domain\MemberLimitExceeded;
use App\Domain\Quantity;
use App\Domain\User;
use App\Domain\UserRole;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberUserTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function test_member_preserves_name_case_and_requires_document(): void
    {
        $member = new Member(self::ID, ' Ana Pérez ', ' DOC-123 ');
        $this->assertSame('Ana Pérez', $member->fullName);
        $this->assertSame('DOC-123', $member->documentNumber);
        $this->assertSame(self::ID, $member->id);
    }

    #[DataProvider('invalidMembers')]
    public function test_member_rejects_missing_or_oversized_fields(string $name, string $document): void
    {
        $this->expectException(DomainError::class);
        new Member(self::ID, $name, $document);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidMembers(): iterable
    {
        yield ['Ana', ' '];
        yield [' ', 'DOC'];
        yield ['Ana', str_repeat('D', 31)];
        yield [str_repeat('A', 201), 'DOC'];
    }

    public function test_username_is_trimmed_and_lowercased(): void
    {
        $user = User::register(self::ID, 'Ana ', 'test-placeholder');
        $this->assertSame('ana', $user->username);
        $this->assertSame(UserRole::Librarian, $user->role);
        $this->assertSame('test-placeholder', $user->passwordHash());
        $this->assertSame('ángela', User::register(self::ID, ' ÁNGELA ', 'test-placeholder')->username);
    }

    #[DataProvider('invalidRoles')]
    public function test_registration_rejects_admin_and_unknown_roles(string $role): void
    {
        $this->expectException(DomainError::class);
        User::register(self::ID, 'Ana', 'test-placeholder', $role);
    }

    /** @return iterable<array{string}> */
    public static function invalidRoles(): iterable
    {
        yield ['admin'];
        yield ['reader'];
        yield [''];
        yield ['Librarian'];
    }

    public function test_admin_is_supported_only_through_the_provisioning_factory(): void
    {
        $user = User::provisionAdmin(self::ID, ' ADMIN ', 'test-placeholder');
        $this->assertSame('admin', $user->username);
        $this->assertSame(UserRole::Admin, $user->role);
    }

    public function test_hash_is_absent_from_debug_and_public_json_data(): void
    {
        $user = User::register(self::ID, 'Ana', 'test-placeholder');
        $this->assertArrayNotHasKey('passwordHash', $user->__debugInfo());
        $this->assertStringNotContainsString('test-placeholder', json_encode($user, JSON_THROW_ON_ERROR));
    }

    public function test_blank_username_is_rejected(): void
    {
        $this->expectException(DomainError::class);
        User::register(self::ID, ' ', 'test-placeholder');
    }

    public function test_empty_hash_is_rejected(): void
    {
        $this->expectException(DomainError::class);
        User::register(self::ID, 'Ana', '');
    }

    public function test_member_limit_counts_unreturned_books_across_multiple_loans(): void
    {
        $member = new Member(self::ID, 'Ana', 'DOC');
        $first = $this->loanWithItem(1, $member->id);
        $second = $this->loanWithItem(2, $member->id);
        $member->assertCanBorrow(new Quantity(1), [$first, $second], new DateTimeImmutable('2026-01-02Z'));
        try {
            $member->assertCanBorrow(new Quantity(2), [$first, $second], new DateTimeImmutable('2026-01-02Z'));
            $this->fail('Two existing books plus two requested books exceed the limit.');
        } catch (MemberLimitExceeded $error) {
            $this->assertSame('MEMBER_LIMIT_EXCEEDED', $error->errorCode);
            $this->assertSame(2, $error->currentCount);
            $this->assertSame(2, $error->requestedCount);
        }
    }

    public function test_member_with_overdue_line_cannot_borrow_even_below_limit(): void
    {
        $member = new Member(self::ID, 'Ana', 'DOC');
        $loan = $this->loanWithItem(1, $member->id);
        $clock = new FakeClock(new DateTimeImmutable('2026-01-15T00:00:00Z'));
        $member->assertCanBorrow(new Quantity(1), [$loan], $clock->now());
        $clock->advance('PT1S');
        try {
            $member->assertCanBorrow(new Quantity(1), [$loan], $clock->now());
            $this->fail('A member with an overdue line cannot borrow.');
        } catch (MemberHasOverdue $error) {
            $this->assertSame('MEMBER_HAS_OVERDUE', $error->errorCode);
        }
    }

    public function test_returned_lines_and_other_members_do_not_block_borrowing(): void
    {
        $member = new Member(self::ID, 'Ana', 'DOC');
        $book = new Book(self::ID, 'Title', 'Author', new Isbn('9780307474728'), '11111111-1111-4111-8111-111111111111', new Quantity(1));
        $loan = new Loan(self::ID, $member->id, self::ID, new DateTimeImmutable('2026-01-01Z'));
        $loan->addItem(self::ID, $book, 'Novela');
        $loan->record();
        $loan->returnItem(self::ID, $book, new DateTimeImmutable('2026-01-02Z'));
        $other = $this->loanWithItem(2, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $member->assertCanBorrow(new Quantity(3), [$loan, $other], new DateTimeImmutable('2026-02-01Z'));
        $this->assertSame(1, $book->availableCopies());
    }

    public function test_requesting_more_than_three_books_fails_even_without_existing_loans(): void
    {
        $member = new Member(self::ID, 'Ana', 'DOC');
        $this->expectException(MemberLimitExceeded::class);
        $member->assertCanBorrow(new Quantity(4), [], new DateTimeImmutable('2026-01-01Z'));
    }

    private function loanWithItem(int $number, string $memberId): Loan
    {
        $id = sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number);
        $book = new Book($id, 'Title', 'Author', new Isbn('9780307474728'), '11111111-1111-4111-8111-111111111111', new Quantity(1));
        $loan = new Loan($id, $memberId, self::ID, new DateTimeImmutable('2026-01-01Z'));
        $loan->addItem($id, $book, 'Novela');
        $loan->record();

        return $loan;
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(DomainError::class);
        new Member('invalid', 'Ana', 'DOC');
    }
}
