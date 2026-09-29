<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Storage\SqliteConnection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/about`, `/health` and `/ready`, exercised over HTTP the way a probe would.
 *
 * The load-bearing test here is {@see testLivenessStaysUpWhenTheStoreIsBroken}.
 * The health/readiness split is easy to describe and easy to erode — one
 * well-meaning "let's also check the store in /health" turns every store blip
 * into a restart storm — so the split is asserted rather than trusted: with the
 * store deliberately unreachable, `/ready` must fail and `/health` must not.
 */
final class SystemControllerTest extends WebTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function json(string $uri): array
    {
        $client = static::createClient();
        $client->request('GET', $uri);

        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testHealthReportsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testAboutReportsTheApplicationNameAndAVersion(): void
    {
        $body = $this->json('/about');

        self::assertSame('memory-draft', $body['name']);
        self::assertIsString($body['version']);
        self::assertNotSame('', $body['version']);
    }

    /**
     * Nothing has been stamped in a local checkout, and the honest answer to
     * "which build is this?" is then `unknown` rather than a guess (§8.13).
     */
    public function testAboutReportsUnknownWhenNoVersionWasStamped(): void
    {
        // Boot via createClient() first: the container is only safe to reach
        // through the test client, which owns the kernel's lifecycle.
        $client = static::createClient();

        self::assertFalse(
            is_file(self::getContainer()->getParameter('kernel.project_dir').'/VERSION'),
            'This assertion documents the local-checkout state; a stamped build is covered in Docker.',
        );

        $client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unknown', $body['version']);
    }

    public function testReadyReportsTheStoreIsReadable(): void
    {
        $body = $this->json('/ready');

        self::assertSame('ready', $body['status']);
        self::assertSame(0, $body['keys']);
    }

    /**
     * The whole point of the split: a broken dependency takes the instance out
     * of the pool (503 from `/ready`) without killing the process (`/health`
     * stays 200, so the orchestrator does not restart a working container).
     */
    public function testLivenessStaysUpWhenTheStoreIsBroken(): void
    {
        $client = static::createClient();
        // One container for both requests, so the broken connection below is the
        // one `/ready` sees.
        $client->disableReboot();

        static::getContainer()->set(
            SqliteConnection::class,
            SqliteConnection::fromPath('/nonexistent-directory/that/cannot/be/created/memory.db'),
        );

        $client->request('GET', '/ready');
        self::assertResponseStatusCodeSame(503);
        $ready = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unavailable', $ready['status']);
        self::assertArrayHasKey('error', $ready);

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testProbeResponsesAreNotCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
