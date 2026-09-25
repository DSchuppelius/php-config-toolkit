<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ClassLoader;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ERRORToolkit\Factories\ConsoleLoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\{AbstractLogger, LogLevel};

class ClassLoaderTest extends TestCase {
    private string $testDirectory;

    protected function setUp(): void {
        $this->testDirectory = __DIR__ . '/test_classes';
    }

    protected function tearDown(): void {
        // Der Logger haengt statisch an der Klasse; den Spy nicht in andere Tests tragen.
        ClassLoader::setLogger(ConsoleLoggerFactory::getLogger());
    }

    public function test_traits_interfaces_und_enums_sind_kein_ladefehler(): void {
        $records = [];
        $spy = new class($records) extends AbstractLogger {
            /** @param list<array{0: string, 1: string}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->records[] = [(string) $level, (string) $message];
            }
        };

        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, $spy);
        $classes = $loader->getClasses();

        $this->assertNotContains('Tests\\test_classes\\SampleTrait', $classes);
        $this->assertNotContains('Tests\\test_classes\\SampleInterface', $classes);
        $this->assertNotContains('Tests\\test_classes\\SampleEnum', $classes);
        $this->assertContains('Tests\\test_classes\\ValidClass', $classes, 'Die regulaeren Klassen laedt er weiterhin.');

        $warnings = array_values(array_filter($records, static fn (array $r): bool => $r[0] === LogLevel::WARNING));
        $this->assertSame([], $warnings, 'Trait/Interface/Enum duerfen keine Warnung ausloesen: ' . json_encode($warnings));

        $debugSkips = array_filter($records, static fn (array $r): bool => $r[0] === LogLevel::DEBUG && str_contains($r[1], 'Kein Klassentyp'));
        $skipped = array_map(static fn (array $r): string => substr($r[1], strrpos($r[1], '\\') + 1), $debugSkips);
        sort($skipped);
        $this->assertSame(['SampleInterface', 'SampleTrait'], $skipped, 'Trait und Interface werden nur im Debug-Log genannt.');
    }

    public function test_can_load_valid_class(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertContains('Tests\\test_classes\\ValidClass', $classes);
    }

    public function test_skips_invalid_classes(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertNotContains('Tests\\test_classes\\InvalidClass', $classes);
    }

    public function test_can_load_subdirectory_class(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertContains('Tests\\test_classes\\sub\\SubValidClass', $classes);
    }

    public function test_throws_exception_for_invalid_directory(): void {
        $this->expectException(\Exception::class);
        new ClassLoader('/invalid/path', 'InvalidNamespace', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
    }
}
