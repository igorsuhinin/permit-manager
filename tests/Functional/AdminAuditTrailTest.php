<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Enum\AdminAuditAction;
use App\Application\View\AdminAuditEntry;
use App\Infrastructure\Persistence\AdminAuditTrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Tests\Support\Database;

#[Medium]
#[CoversClass(AdminAuditTrail::class)]
#[UsesNamespace('Database\\Migrations')]
#[UsesNamespace('App')]
final class AdminAuditTrailTest extends TestCase
{
    public function testAuditStoresUtcWithoutChangingSettingsOrBlacklist(): void
    {
        $connection = Database::create();
        $settings = $connection->fetchAllAssociative('SELECT * FROM app_settings');
        $blacklist = $connection->fetchAllAssociative('SELECT * FROM blacklisted_ids');
        $audit = new AdminAuditTrail($connection);
        $audit->append(new AdminAuditEntry(
            actor: 'admin',
            action: AdminAuditAction::Login,
            targetHash: hash(algo: 'sha256', data: 'admin'),
            occurredAt: new \DateTimeImmutable('2026-11-11T12:00:00+02:00'),
        ));
        self::assertSame('2026-11-11 10:00:00', $connection->fetchOne('SELECT occurred_at FROM admin_audit'));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM admin_audit'));
        self::assertSame($settings, $connection->fetchAllAssociative('SELECT * FROM app_settings'));
        self::assertSame($blacklist, $connection->fetchAllAssociative('SELECT * FROM blacklisted_ids'));
    }
}
