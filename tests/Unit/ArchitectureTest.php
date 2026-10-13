<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * T-19 · Constitution articles I, II and III, checked against the source code
 * (PHP equivalent of import-linter / NetArchTest).
 */
final class ArchitectureTest extends TestCase
{
    private static function source(string $dir): array
    {
        $root = dirname(__DIR__, 2).'/app/'.$dir;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $out = [];
        foreach ($files as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[$f->getPathname()] = file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    public function test_article_I_domain_depends_on_nothing(): void
    {
        foreach (self::source('Domain') as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/\b(use|new)\s+\\\\?(Illuminate|App\\\\(Application|Adapters|Bootstrap|Http|Providers))/', $code, "{$path} depends on something outside the domain");
        }
    }

    public function test_article_II_application_does_not_know_adapters_nor_framework(): void
    {
        foreach (self::source('Application') as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/\b(use|new)\s+\\\\?(Illuminate|App\\\\(Adapters|Bootstrap|Http))/', $code, "{$path} imports adapters or the framework");
        }
    }

    public function test_article_III_outbound_adapters_only_instantiated_in_composition(): void
    {
        foreach (['Domain', 'Application', 'Adapters/Inbound'] as $dir) {
            foreach (self::source($dir) as $path => $code) {
                $this->assertDoesNotMatchRegularExpression('/new\s+\\\\?(App\\\\Adapters\\\\Outbound|Pg[A-Z]|SystemClock|UuidV4Generator|Argon2PasswordHasher|JwtTokenIssuer|DiskCoverStorage)/', $code, "{$path} instantiates infrastructure");
            }
        }
        $this->assertFileExists(dirname(__DIR__, 2).'/app/Bootstrap/PortBindings.php');
    }

    public function test_detects_a_forbidden_import(): void
    {
        // The rule catches a forbidden import (T-19 evidence).
        $this->assertMatchesRegularExpression('/\b(use|new)\s+\\\\?(Illuminate|App\\\\(Application|Adapters|Bootstrap|Http|Providers))/', "use Illuminate\\Support\\Facades\\DB;");
    }
}
