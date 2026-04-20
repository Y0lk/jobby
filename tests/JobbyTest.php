<?php

namespace Jobby\Tests;

use Jobby\Helper;
use Jobby\Jobby;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Jobby\Jobby
 */
class JobbyTest extends TestCase
{
    /**
     * @var string
     */
    private $logFile;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = __DIR__ . '/_files/JobbyTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShell()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => PHP_BINARY . ' ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('Hello World!', $this->getLogContent());
    }

    /**
     * @return void
     */
    public function testBackgroundProcessIsNotSpawnedIfJobIsNotDueToBeRun()
    {
        $hour = date("H", strtotime("+1 hour"));
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => PHP_BINARY . ' ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => "* {$hour} * * *",
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertFalse(
            file_exists($this->logFile),
            "Failed to assert that log file doesn't exist and that background process did not spawn"
        );
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testCommandClosureCanCaptureVariablesAfterSerialization()
    {
        $message = 'Another function!';
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () use ($message) {
                    echo $message;

                    return true;
                },
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals($message, $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testClosureConfigCanCaptureVariablesAfterSerialization()
    {
        $message = 'Wrapped function!';
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldWrappedClosure',
            [
                'closure'  => static function () use ($message) {
                    echo $message;

                    return true;
                },
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals($message, $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testClosure()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo 'A function!';

                    return true;
                },
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShouldRunAllJobsAdded()
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'job-1',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-1';

                    return true;
                },
            ]
        );
        $jobby->add(
            'job-2',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-2';

                    return true;
                },
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertStringContainsString('job-1', $this->getLogContent());
        $this->assertStringContainsString('job-2', $this->getLogContent());
    }

    /**
     * This is the same test as testClosure but (!) we use the default
     * options to set the output file.
     */
    public function testDefaultOptionsShouldBeMerged()
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo "A function!";

                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::getDefaultConfig
     */
    public function testDefaultConfig()
    {
        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();

        $this->assertNull($config['recipients']);
        $this->assertEquals('sendmail', $config['mailer']);
        $this->assertNull($config['runAs']);
        $this->assertNull($config['output']);
        $this->assertEquals('Y-m-d H:i:s', $config['dateFormat']);
        $this->assertTrue($config['enabled']);
        $this->assertFalse($config['debug']);
    }

    /**
     * @covers ::setConfig
     * @covers ::getConfig
     */
    public function testSetConfig()
    {
        $jobby = new Jobby();
        $oldCfg = $jobby->getConfig();

        $jobby->setConfig(['dateFormat' => 'foo bar']);
        $newCfg = $jobby->getConfig();

        $this->assertEquals(count($oldCfg), count($newCfg));
        $this->assertEquals('foo bar', $newCfg['dateFormat']);
    }

    /**
     * @covers ::getJobs
     */
    public function testGetJobs()
    {
        $jobby = new Jobby();
        $this->assertCount(0, $jobby->getJobs());

        $jobby->add(
            'test job1',
            [
                'command' => 'test',
                'schedule' => '* * * * *',
            ]
        );

        $jobby->add(
            'test job2',
            [
                'command' => 'test',
                'schedule' => '* * * * *',
            ]
        );

        $this->assertCount(2, $jobby->getJobs());
    }

    /**
     * @covers ::add
     */
    public function testExceptionOnMissingJobOptionCommand()
    {
        $this->expectException(\Jobby\Exception::class);

        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'schedule' => '* * * * *',
            ]
        );
    }

    /**
     * @covers ::add
     */
    public function testExceptionOnMissingJobOptionSchedule()
    {
        $this->expectException(\Jobby\Exception::class);

        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'command' => static function () {
                },
            ]
        );
    }

    /**
     * @covers ::run
     * @covers ::runWindows
     * @covers ::runUnix
     */
    public function testShouldRunJobsAsync()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => function () {
                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );

        $timeStart = microtime(true);
        $jobby->run();
        $duration = microtime(true) - $timeStart;

        $this->assertLessThan(0.5, $duration);
    }

    public function testShouldFailIfMaxRuntimeExceeded()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $jobby = new Jobby();
        $jobby->add(
            'slow job',
            [
                'command'    => 'sleep 4',
                'schedule'   => '* * * * *',
                'maxRuntime' => 1,
                'output'     => $this->logFile,
            ]
        );

        $jobby->run();
        sleep(2);
        $jobby->run();
        sleep(2);

        $this->assertStringContainsString('ERROR: MaxRuntime of 1 secs exceeded!', $this->getLogContent());
    }

    /**
     * @covers ::getPhpBinary
     */
    public function testGetPhpBinary()
    {
        $jobby = new class () extends Jobby {
            public function exposedGetPhpBinary()
            {
                return $this->getPhpBinary();
            }
        };

        $this->assertIsString($jobby->exposedGetPhpBinary());
        $this->assertNotSame('', $jobby->exposedGetPhpBinary());
    }

    /**
     * @return string
     */
    private function getLogContent()
    {
        return file_get_contents($this->logFile);
    }

    private function getSleepTime()
    {
        return $this->helper->getPlatform() === Helper::UNIX ? 1 : 2;
    }
}
