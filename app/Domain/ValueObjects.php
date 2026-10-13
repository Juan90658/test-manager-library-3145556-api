<?php

namespace App\Domain;

/** 13-digit ISBN (CA-03.2). Lives in its owner's row, no table (plan D-07). */
final class Isbn
{
    private function __construct(public readonly string $value) {}

    public static function of(string $raw): self
    {
        $value = trim($raw);
        if (! preg_match('/^\d{13}$/', $value)) {
            throw new ValidationFailed('El ISBN debe tener exactamente 13 dígitos.', [['field' => 'isbn', 'issue' => 'debe tener 13 dígitos']]);
        }

        return new self($value);
    }
}

/** Number of copies: integer >= 1 (CA-03.3). */
final class Quantity
{
    private function __construct(public readonly int $value) {}

    public static function of(int $value, string $field = 'total_copies'): self
    {
        if ($value < 1) {
            throw new ValidationFailed('El total de ejemplares debe ser al menos 1.', [['field' => $field, 'issue' => 'debe ser ≥ 1']]);
        }

        return new self($value);
    }
}

/** Date range, both ends inclusive (CA-10.2). */
final class DateRange
{
    private function __construct(public readonly \DateTimeImmutable $from, public readonly \DateTimeImmutable $to) {}

    public static function of(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        if ($to < $from) {
            throw new InvalidDateRange('La fecha final no puede ser anterior a la inicial.', [['field' => 'to', 'issue' => 'anterior a from']]);
        }

        return new self($from, $to);
    }

    /** Exclusive upper bound: the day after `to` at 00:00 UTC. */
    public function toExclusive(): \DateTimeImmutable
    {
        return $this->to->modify('+1 day');
    }
}

final class Role
{
    public const ADMIN = 'admin';
    public const LIBRARIAN = 'librarian';
    public const ALL = [self::ADMIN, self::LIBRARIAN];
}

/** Normalized username: trimmed and lowercased (CA-02.2). */
final class Username
{
    private function __construct(public readonly string $value) {}

    public static function of(string $raw): self
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '' || mb_strlen($value) > 120) {
            throw new ValidationFailed('El nombre de usuario es obligatorio y admite hasta 120 caracteres.', [['field' => 'username', 'issue' => 'inválido']]);
        }

        return new self($value);
    }
}
