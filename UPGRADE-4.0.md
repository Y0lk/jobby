# UPGRADE FROM 3.x TO 4.0

Version 4.0.0 is the first release of the maintained fork.
It includes a package rename and mailer changes that may require updates in downstream projects.

## Breaking changes

1. Composer package name changed from `hellogerard/jobby` to `y0lk/jobby`.
2. The mailer dependency changed from `swiftmailer/swiftmailer` to `phpmailer/phpmailer`.
3. `Jobby\Helper::__construct()` now accepts `PHPMailer\PHPMailer\PHPMailer` instead of `\Swift_Mailer`.
4. `Jobby\Helper::sendMail()` now returns a `PHPMailer\PHPMailer\PHPMailer` instance instead of a `\Swift_Message`.

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
3. If you injected a custom SwiftMailer instance into `Jobby\Helper`, switch that integration to PHPMailer.
4. If your tests asserted against SwiftMailer message objects, update them to assert on PHPMailer properties instead.

## Notes

The PHP namespace remains `Jobby\`, so most application code that only constructs `new Jobby\Jobby()` should not need namespace changes.
