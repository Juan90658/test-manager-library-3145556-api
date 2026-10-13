<?php

namespace App\Bootstrap;

use App\Adapters\Outbound\Infrastructure as Infra;
use App\Adapters\Outbound\Persistence as Pg;
use App\Application\Ports;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

/**
 * The ONLY composition point (constitution, art. III): this is where a port meets its adapter.
 * No App\Adapters\Outbound class is instantiated outside this file.
 * Switching cover storage (disk -> S3) means changing one line here (OCP).
 */
final class PortBindings extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConnectionInterface::class, fn ($app) => $app['db']->connection());

        $this->app->singleton(Ports\Clock::class, fn () => new Infra\SystemClock((int) config('library.clock_offset_days')));
        $this->app->singleton(Ports\IdGenerator::class, Infra\UuidV4Generator::class);
        $this->app->singleton(Ports\PasswordHasher::class, Infra\Argon2PasswordHasher::class);
        $this->app->singleton(Ports\TokenIssuer::class, fn ($app) => new Infra\JwtTokenIssuer(
            (string) config('library.jwt_secret'), (int) config('library.jwt_ttl_seconds'), $app->make(Ports\Clock::class),
        ));
        $this->app->singleton(Ports\CoverStorage::class, fn ($app) => new Infra\DiskCoverStorage(
            str_starts_with((string) config('library.covers_dir'), '/') ? config('library.covers_dir') : base_path(config('library.covers_dir')),
            $app->make(Ports\IdGenerator::class),
        ));

        $this->app->bind(Ports\TransactionRunner::class, Pg\PgTransactionRunner::class);
        $this->app->bind(Ports\UserRepository::class, Pg\PgUserRepository::class);
        $this->app->bind(Ports\CategoryRepository::class, Pg\PgCategoryRepository::class);
        $this->app->bind(Ports\BookRepository::class, Pg\PgBookRepository::class);
        $this->app->bind(Ports\MemberRepository::class, Pg\PgMemberRepository::class);
        $this->app->bind(Ports\LoanRepository::class, Pg\PgLoanRepository::class);
        $this->app->bind(Ports\ReportReader::class, Pg\PgReportReader::class);
    }
}
