<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Enum\DiagnosticStatus;
use App\Application\Port\DeploymentDiagnosticsQueriesInterface;
use App\Application\Query\DiagnoseDeploymentHandler;
use App\Application\Query\DiagnoseDeploymentQuery;
use App\Application\View\DiagnosticCheck;
use App\Console\Command\DoctorCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[Small]
#[CoversClass(DoctorCommand::class)]
#[CoversClass(DiagnoseDeploymentHandler::class)]
#[UsesClass(DiagnoseDeploymentQuery::class)]
#[UsesClass(DiagnosticCheck::class)]
final class DoctorCommandTest extends TestCase
{
    #[DataProvider('results')]
    public function testReadQueryReportsFailureWhenAnyCheckFails(DiagnosticStatus $status, int $exit): void
    {
        $checks = [
            new DiagnosticCheck(name: 'First', status: $status, message: 'Synthetic result.'),
            new DiagnosticCheck(name: 'Last', status: DiagnosticStatus::Passed, message: 'Another result.'),
        ];
        $diagnostics = $this->createMock(DeploymentDiagnosticsQueriesInterface::class);
        $diagnostics->expects($this->once())->method('inspect')->willReturn($checks);
        $tester = new CommandTester(new DoctorCommand(new DiagnoseDeploymentHandler($diagnostics)));
        self::assertSame($exit, $tester->execute([]));
        self::assertStringContainsString('First', $tester->getDisplay());
        self::assertStringContainsString('Last', $tester->getDisplay());
        self::assertStringContainsString($status->value, $tester->getDisplay());
    }

    /** @return iterable<string, array{DiagnosticStatus, int}> */
    public static function results(): iterable
    {
        yield 'passed' => [DiagnosticStatus::Passed, 0];
        yield 'failed followed by passed' => [DiagnosticStatus::Failed, 1];
    }

    public function testUnexpectedFailureCannotLeakExceptionDetails(): void
    {
        $diagnostics = $this->createMock(DeploymentDiagnosticsQueriesInterface::class);
        $diagnostics->expects($this->once())->method('inspect')
            ->willThrowException(new \RuntimeException('private credentials and paths'));
        $tester = new CommandTester(new DoctorCommand(new DiagnoseDeploymentHandler($diagnostics)));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Diagnostics could not finish.', $tester->getDisplay());
        self::assertStringNotContainsString('private credentials and paths', $tester->getDisplay());
    }
}
