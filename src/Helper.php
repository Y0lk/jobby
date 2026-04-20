<?php
namespace Jobby;

use PHPMailer\PHPMailer\PHPMailer;

class Helper
{
    /**
     * @var int
     */
    const UNIX = 0;

    /**
     * @var int
     */
    const WINDOWS = 1;

    /**
     * @var resource[]
     */
    private $lockHandles = [];

    /**
     * @var PHPMailer|null
     */
    private $mailer;

    /**
     * @param PHPMailer|null $mailer
     */
    public function __construct(PHPMailer $mailer = null)
    {
        $this->mailer = $mailer;
    }

    /**
     * @param string $job
     * @param array  $config
     * @param string $message
     *
     * @return PHPMailer
     */
    public function sendMail($job, array $config, $message)
    {
        $host = $this->getHost();
        $fromAddress = $this->normalizeSenderAddress($config['smtpSender']);
        $body = <<<EOF
$message

You can find its output in {$config['output']} on $host.

Best,
jobby@$host
EOF;
        $mailer = $this->getCurrentMailer($config);
        $mailer->clearAllRecipients();
        foreach (array_filter(array_map('trim', explode(',', (string) $config['recipients']))) as $recipient) {
            $mailer->addAddress($recipient);
        }
        $mailer->Subject = "[$host] '{$job}' needs some attention!";
        $mailer->Body = $body;
        if (!$mailer->setFrom($fromAddress, $config['smtpSenderName'], false)) {
            throw new Exception("Unable to set sender address '$fromAddress'.");
        }
        $mailer->Sender = $fromAddress;
        $mailer->send();

        return $mailer;
    }

    /**
     * @param array $config
     *
     * @return PHPMailer
     */
    private function getCurrentMailer(array $config)
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $mailer = new PHPMailer();
        $mailer->isHTML(false);

        if ($config['mailer'] === 'smtp') {
            $mailer->isSMTP();
            $mailer->Host = $config['smtpHost'];
            $mailer->Port = (int) $config['smtpPort'];
            $mailer->SMTPAuth = !empty($config['smtpUsername']) || !empty($config['smtpPassword']);
            $mailer->Username = $config['smtpUsername'];
            $mailer->Password = $config['smtpPassword'];

            if ($config['smtpSecurity'] === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['smtpSecurity'] === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
        } elseif ($config['mailer'] === 'mail') {
            $mailer->isMail();
        } else {
            $mailer->isSendmail();
        }

        return $mailer;
    }

    /**
     * @return string
     */
    public function getDefaultMailSender()
    {
        return $this->normalizeSenderAddress('jobby@' . $this->getHost());
    }

    /**
     * PHPMailer requires a syntactically valid email address, while many hosts
     * expose short machine names such as "runner123". Normalize those names to
     * a safe local domain so the default sender remains usable.
     *
     * @param string $address
     *
     * @return string
     */
    private function normalizeSenderAddress($address)
    {
        $address = trim((string) $address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $address;
        }

        $parts = explode('@', $address, 2);
        $localPart = isset($parts[0]) ? $parts[0] : 'jobby';
        $domain = isset($parts[1]) ? $parts[1] : '';

        $localPart = preg_replace("/[^A-Za-z0-9.!#$%&'*+\\/=?^_`{|}~-]+/", '', $localPart);
        if ($localPart === '') {
            $localPart = 'jobby';
        }

        $domain = preg_replace('/[^A-Za-z0-9.-]+/', '-', $domain);
        $domain = trim($domain, '.-');
        if ($domain !== '' && strpos($domain, '.') === false) {
            $domain .= '.local';
        }
        if ($domain === '') {
            $domain = 'localhost.localdomain';
        }

        $normalizedAddress = strtolower($localPart . '@' . $domain);
        if (filter_var($normalizedAddress, FILTER_VALIDATE_EMAIL)) {
            return $normalizedAddress;
        }

        return 'jobby@localhost.localdomain';
    }

    /**
     * @param string $lockFile
     *
     * @throws Exception
     * @throws InfoException
     */
    public function acquireLock($lockFile)
    {
        if (array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock already acquired (Lockfile: $lockFile).");
        }

        if (!file_exists($lockFile) && !touch($lockFile)) {
            throw new Exception("Unable to create file (File: $lockFile).");
        }

        $fh = fopen($lockFile, 'rb+');
        if ($fh === false) {
            throw new Exception("Unable to open file (File: $lockFile).");
        }

        $attempts = 5;
        while ($attempts > 0) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->lockHandles[$lockFile] = $fh;
                ftruncate($fh, 0);
                fwrite($fh, getmypid());

                return;
            }
            usleep(250);
            --$attempts;
        }

        throw new InfoException("Job is still locked (Lockfile: $lockFile)!");
    }

    /**
     * @param string $lockFile
     *
     * @throws Exception
     */
    public function releaseLock($lockFile)
    {
        if (!array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock NOT held - bug? Lockfile: $lockFile");
        }

        if ($this->lockHandles[$lockFile]) {
            ftruncate($this->lockHandles[$lockFile], 0);
            flock($this->lockHandles[$lockFile], LOCK_UN);
        }

        unset($this->lockHandles[$lockFile]);
    }

    /**
     * @param string $lockFile
     *
     * @return int
     */
    public function getLockLifetime($lockFile)
    {
        if (!file_exists($lockFile)) {
            return 0;
        }

        $pid = file_get_contents($lockFile);
        if (empty($pid)) {
            return 0;
        }

        if (!posix_kill((int) $pid, 0)) {
            return 0;
        }

        $stat = stat($lockFile);

        return (time() - $stat['mtime']);
    }

    /**
     * @return string
     */
    public function getTempDir()
    {
        // @codeCoverageIgnoreStart
        if (function_exists('sys_get_temp_dir')) {
            $tmp = sys_get_temp_dir();
        } elseif (!empty($_SERVER['TMP'])) {
            $tmp = $_SERVER['TMP'];
        } elseif (!empty($_SERVER['TEMP'])) {
            $tmp = $_SERVER['TEMP'];
        } elseif (!empty($_SERVER['TMPDIR'])) {
            $tmp = $_SERVER['TMPDIR'];
        } else {
            $tmp = getcwd();
        }
        // @codeCoverageIgnoreEnd

        return $tmp;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return php_uname('n');
    }

    /**
     * @return string|null
     */
    public function getApplicationEnv()
    {
        return isset($_SERVER['APPLICATION_ENV']) ? $_SERVER['APPLICATION_ENV'] : null;
    }

    /**
     * @return int
     */
    public function getPlatform()
    {
        if (strncasecmp(PHP_OS, 'Win', 3) === 0) {
            // @codeCoverageIgnoreStart
            return self::WINDOWS;
            // @codeCoverageIgnoreEnd
        }

        return self::UNIX;
    }

    /**
     * @param string $input
     *
     * @return string
     */
    public function escape($input)
    {
        $input = strtolower($input);
        $input = preg_replace('/[^a-z0-9_. -]+/', '', $input);
        $input = trim($input);
        $input = str_replace(' ', '_', $input);
        $input = preg_replace('/_{2,}/', '_', $input);

        return $input;
    }

    public function getSystemNullDevice()
    {
        $platform = $this->getPlatform();
        if ($platform === self::UNIX) {
            return '/dev/null';
        }
        return 'NUL';
    }
}
