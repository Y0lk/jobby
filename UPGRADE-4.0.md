# UPGRADE FROM 3.x TO 4.0

Version 4.0.0 is the first release of the maintained fork.
It includes a package rename, mailer changes, a PHP 8.0+ requirement, and a move to `opis/closure` 4.x.

## Breaking changes

1. Composer package name changed from `hellogerard/jobby` to `y0lk/jobby`.
2. Minimum supported PHP version is now 8.0.
3. The mailer dependency changed from `swiftmailer/swiftmailer` to `phpmailer/phpmailer`.
4. Closure serialization now uses `opis/closure` 4.x and serializes closures directly through the Opis 4 API.
5. `Jobby\Helper::__construct()` now accepts `PHPMailer\PHPMailer\PHPMailer` instead of `\Swift_Mailer`.
6. `Jobby\Helper::sendMail()` now returns a `PHPMailer\PHPMailer\PHPMailer` instance instead of a `\Swift_Message`.

## What to update

1. Replace the package name in your application `composer.json`:

```json
{
  "require": {
    "y0lk/jobby": "^4.0"
  }
}
```

2. Run Composer to remove the abandoned package and install the fork.
3. If you persist serialized closures anywhere outside the current process, regenerate them for `opis/closure` 4.x before relying on them after the upgrade.
4. Pass raw closures and let Jobby serialize them internally.
5. If you injected a custom SwiftMailer instance into `Jobby\Helper`, switch that integration to PHPMailer.
6. If your tests asserted against SwiftMailer message objects, update them to assert on PHPMailer properties instead.

## Notes

The PHP namespace remains `Jobby\`, so most application code that only constructs `new Jobby\Jobby()` should not need namespace changes.
