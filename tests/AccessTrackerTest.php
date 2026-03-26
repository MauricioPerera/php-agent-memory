<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\AccessTracker;

final class AccessTrackerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pam-access-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function testIncrementAndGet(): void
    {
        $tracker = new AccessTracker($this->tmpDir);
        $this->assertSame(0, $tracker->get('id1'));

        $tracker->increment('id1');
        $this->assertSame(1, $tracker->get('id1'));

        $tracker->increment('id1');
        $this->assertSame(2, $tracker->get('id1'));
    }

    public function testIncrementMany(): void
    {
        $tracker = new AccessTracker($this->tmpDir);
        $tracker->incrementMany(['a', 'b', 'a']);
        $this->assertSame(2, $tracker->get('a'));
        $this->assertSame(1, $tracker->get('b'));
    }

    public function testFlushAndReload(): void
    {
        $tracker = new AccessTracker($this->tmpDir);
        $tracker->increment('x');
        $tracker->increment('x');
        $tracker->flush();

        $tracker2 = new AccessTracker($this->tmpDir);
        $this->assertSame(2, $tracker2->get('x'));
    }
}
