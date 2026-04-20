<?php

namespace Jobby\Tests;

use Jobby\Helper;
use Jobby\Jobby;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Jobby\Helper
 */
class HelperTest extends TestCase
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var string
     */
    private $tmpDir;

    /**
     * @var string
     */
    private $lockFile;

    /**
     * @var string
     */
    private $copyOfLockFile;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new Helper();
        $this->tmpDir = $this->helper->getTempDir();
        $this->lockFile = $this->tmpDir . '/test.lock';
        $this->copyOfLockFile = $this->tmpDir . "/test.lock.copy";
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        unset($_SERVER['APPLICATION_ENV']);

        parent::tearDown();
    }

    /**
     * @param string $input
     * @param string $expected
     *
     * @dataProvider dataProviderTestEscape
     */
    public function testEscape($input, $expected)
    {
        $actual = $this->helper->escape($input);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function dataProviderTestEscape()
    {
        return [
            ['lower', 'lower'],
            ['UPPER', 'upper'],
            ['0123456789', '0123456789'],
            ['with    spaces', 'with_spaces'],
            ['invalid!@#$%^&*()chars', 'invalidchars'],
            ['._-', '._-'],
        ];
    }

    /**
     * @covers ::getPlatform
     */
    public function testGetPlatform()
    {
        $actual = $this->helper->getPlatform();
        $this->assertContains($actual, [Helper::UNIX, Helper::WINDOWS]);
    }

    /**
     * @covers ::getPlatform
     */
    public function testPlatformConstants()
    {
        $this->assertNotEquals(Helper::UNIX, Helper::WINDOWS);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     */
    public function testAquireAndReleaseLock()
    {
        $this->helper->acquireLock($this->lockFile);
        $this->assertFileExists($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
        $this->assertSame('', file_get_contents($this->lockFile));
        $this->helper->acquireLock($this->lockFile);
        $this->assertFileExists($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
        $this->assertSame('', file_get_contents($this->lockFile));
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     */
    public function testLockFileShouldContainCurrentPid()
    {
        $this->helper->acquireLock($this->lockFile);

        //on Windows, file locking is mandatory not advisory, so you can't do file_get_contents on a locked file
        //therefore, we need to make a copy of the lock file in order to read its contents
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            copy($this->lockFile, $this->copyOfLockFile);
            $lockFile = $this->copyOfLockFile;
        } else {
            $lockFile = $this->lockFile;
        }

        $this->assertEquals(getmypid(), file_get_contents($lockFile));

        $this->helper->releaseLock($this->lockFile);
        $this->assertEmpty(file_get_contents($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileDoesNotExists()
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }

        $this->assertFalse(file_exists($this->lockFile));
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileIsEmpty()
    {
        file_put_contents($this->lockFile, '');
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfItContainsAInvalidPid()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("Test relies on posix_ functions");
        }

        file_put_contents($this->lockFile, 'invalid-pid');
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testGetLocklifetime()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("Test relies on posix_ functions");
        }

        $this->helper->acquireLock($this->lockFile);

        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        $this->assertEquals(1, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        $this->assertEquals(2, $this->helper->getLockLifetime($this->lockFile));

        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::releaseLock
     */
    public function testReleaseNonExistin()
    {
        $this->expectException(\Jobby\Exception::class);

        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testExceptionIfAquireFails()
    {
        $this->expectException(\Jobby\InfoException::class);

        touch($this->lockFile);
        $fh = fopen($this->lockFile, 'r+');
        $this->assertTrue(is_resource($fh));

        $res = flock($fh, LOCK_EX | LOCK_NB);
        $this->assertTrue($res);

        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testAquireLockShouldFailOnSecondTry()
    {
        $this->expectException(\Jobby\Exception::class);

        $this->helper->acquireLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::getTempDir
     */
    public function testGetTempDir()
    {
        $valid = [sys_get_temp_dir(), getcwd()];
        foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $valid[] = $_SERVER[$key];
            }
        }

        $actual = $this->helper->getTempDir();
        $this->assertContains($actual, $valid);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnv()
    {
        $_SERVER['APPLICATION_ENV'] = 'foo';

        $actual = $this->helper->getApplicationEnv();
        $this->assertEquals('foo', $actual);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnvShouldBeNullIfUndefined()
    {
        $actual = $this->helper->getApplicationEnv();
        $this->assertNull($actual);
    }

    /**
     * @covers ::getHost
     */
    public function testGetHostname()
    {
        $actual = $this->helper->getHost();
        $this->assertContains($actual, [gethostname(), php_uname('n')]);
    }

    /**
     * @covers ::sendMail
     * @covers ::getCurrentMailer
     */
    public function testSendMail()
    {
        $mailer = $this->getPHPMailerMock();
        $mailer->expects($this->once())
            ->method('send')
        ;

        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();
        $config['output'] = 'output message';
        $config['recipients'] = 'a@a.com,b@b.com';

        $helper = new Helper($mailer);
        $mail = $helper->sendMail('job', $config, 'message');

        $host = $helper->getHost();
        $this->assertStringContainsString('job', $mail->Subject);
        $this->assertStringContainsString("[$host]", $mail->Subject);
        $this->assertEquals($config['smtpSender'], $mail->From);
        $this->assertEquals('jobby', $mail->FromName);
        $this->assertEquals($config['smtpSender'], $mail->Sender);
        $this->assertStringContainsString($config['output'], $mail->Body);
        $this->assertStringContainsString('message', $mail->Body);
    }

    /**
     * @covers ::getDefaultMailSender
     */
    public function testGetDefaultMailSenderNormalizesShortHostnames()
    {
        /** @var Helper $helper */
        $helper = $this->getMockBuilder(Helper::class)
            ->onlyMethods(['getHost'])
            ->getMock();
        $helper->expects($this->once())
            ->method('getHost')
            ->willReturn('runnervmeorf1');

        $this->assertEquals('jobby@runnervmeorf1.local', $helper->getDefaultMailSender());
    }

    /**
     * @return PHPMailer
     */
    private function getPHPMailerMock()
    {
        return $this->getMockBuilder(PHPMailer::class)
            ->onlyMethods(['send'])
            ->getMock();
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForUnix()
    {
        /** @var Helper $helper */
        $helper = $this->getMockBuilder(Helper::class)
            ->onlyMethods(['getPlatform'])
            ->getMock();
        $helper->expects($this->once())
            ->method("getPlatform")
            ->willReturn(Helper::UNIX);

        $this->assertEquals("/dev/null", $helper->getSystemNullDevice());
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForWindows()
    {
        /** @var Helper $helper */
        $helper = $this->getMockBuilder(Helper::class)
            ->onlyMethods(['getPlatform'])
            ->getMock();
        $helper->expects($this->once())
               ->method("getPlatform")
               ->willReturn(Helper::WINDOWS);

        $this->assertEquals("NUL", $helper->getSystemNullDevice());
    }
}
