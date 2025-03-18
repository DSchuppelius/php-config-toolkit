<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ERRORToolkit\Factories\ConsoleLoggerFactory;

class ClassLoaderTest extends TestCase {
    private string $testDirectory;

    protected function setUp(): void {
        $this->testDirectory = __DIR__ . '/test_classes';
    }

    public function testCanLoadValidClass(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertContains('Tests\\test_classes\\ValidClass', $classes);
    }

    public function testSkipsInvalidClasses(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertNotContains('Tests\\test_classes\\InvalidClass', $classes);
    }

    public function testCanLoadSubdirectoryClass(): void {
        $loader = new ClassLoader($this->testDirectory, 'Tests\\test_classes', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
        $classes = $loader->getClasses();

        $this->assertContains('Tests\\test_classes\\sub\\SubValidClass', $classes);
    }

    public function testThrowsExceptionForInvalidDirectory(): void {
        $this->expectException(\Exception::class);
        new ClassLoader('/invalid/path', 'InvalidNamespace', ConfigTypeInterface::class, ConsoleLoggerFactory::getLogger());
    }
}