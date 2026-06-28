<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ClassLoader;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ERRORToolkit\Factories\ConsoleLoggerFactory;
use PHPUnit\Framework\TestCase;

class ClassLoaderTest extends TestCase {
    private string $testDirectory;

    protected function setUp(): void {
        $this->testDirectory = __DIR__ . '/test_classes';
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
