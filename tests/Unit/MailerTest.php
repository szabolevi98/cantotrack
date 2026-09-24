<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testTheTransportIsChosenOrReadFromAnOlderDsn(): void
    {
        self::assertSame('mail', Mailer::transport(['transport' => 'mail']));
        self::assertSame('smtp', Mailer::transport(['transport' => 'SMTP ', 'dsn' => 'file']));
        self::assertSame('file', Mailer::transport(['dsn' => 'file']));
        self::assertSame('dsn', Mailer::transport(['dsn' => 'smtp://mail.example.test:25']));
        self::assertSame('none', Mailer::transport([]));
        self::assertSame('none', Mailer::transport(['transport' => 'carrier pigeon']));
    }

    public function testTheServerIsWrittenFromItsFields(): void
    {
        self::assertSame(
            'smtp://anna%40example.test:p%40ss%3Aw0rd@smtp.example.test:587',
            Mailer::serverDsn(['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => '587', 'username' => 'anna@example.test', 'password' => 'p@ss:w0rd', 'encryption' => 'tls'])
        );
        self::assertSame('smtps://smtp.example.test:465', Mailer::serverDsn(['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => '', 'encryption' => 'ssl']));
        self::assertSame('smtp://localhost:25?auto_tls=false', Mailer::serverDsn(['transport' => 'smtp', 'host' => 'localhost', 'port' => '25', 'encryption' => 'none']));
        self::assertSame('smtp://mail.example.test:25', Mailer::serverDsn(['dsn' => 'smtp://mail.example.test:25']));
    }
}
