<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The memory REST surface, exercised over HTTP the way the commands exercise it
 * over the console.
 *
 * One thing about these tests is worth knowing before reading them, because it
 * looks like a workaround and is not: **the kernel boots once per request.**
 * With the store configured as `:memory:` (see `.env.test`), a reboot therefore
 * hands each request a brand-new, empty database, and a write followed by a read
 * would appear to lose the write. That is a property of the *test* store, not of
 * the controller — the same sequence against a file-backed store persists
 * normally.
 *
 * So the tests that need a store to survive more than one request call
 * {@see \Symfony\Bundle\FrameworkBundle\Test\KernelBrowser::disableReboot()},
 * which keeps a single booted container — and therefore a single in-memory
 * store — across the requests in that test. Tests that only make one request
 * leave the default in place, so the common case stays realistic.
 */
final class MemoryControllerTest extends WebTestCase
{
    /**
     * A client whose in-memory store survives more than one request.
     */
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $uri, array $payload): Response
    {
        $client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return $client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Decode a response whose top level is a JSON *list* (the keyspace).
     *
     * Rebuilt element by element rather than returned straight from
     * `json_decode`, so the element type is established by an assertion static
     * analysis can follow. An inline `@var` would read the same to a human and
     * stop PHPStan from seeing the offsets below as proven.
     *
     * @return list<array<mixed>>
     */
    private function decodeList(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsList($decoded);

        $list = [];
        foreach ($decoded as $item) {
            self::assertIsArray($item);
            $list[] = $item;
        }

        return $list;
    }

    public function testRememberThenRecallReturnsTheWrittenSentences(): void
    {
        $client = $this->client();

        $remember = $this->post($client, '/api/remember', [
            'items' => [['key' => 'deploy', 'sentences' => 'Uses blue-green deploys. Rollback is one command.']],
        ]);
        self::assertResponseIsSuccessful();
        $result = $this->decode($remember)['results'][0];
        self::assertSame('deploy', $result['key']);
        self::assertSame(2, $result['added']);
        self::assertSame(1, $result['revision']);

        $recall = $this->post($client, '/api/recall', ['queries' => [['key' => 'deploy', 'depth' => 2]]]);
        self::assertResponseIsSuccessful();
        $body = $this->decode($recall);

        self::assertCount(1, $body['hits']);
        self::assertSame([], $body['misses']);
        self::assertSame('deploy', $body['hits'][0]['key']);
        self::assertSame('exact', $body['hits'][0]['match']);
        self::assertSame(
            ['Uses blue-green deploys.', 'Rollback is one command.'],
            array_column($body['hits'][0]['entries'], 'text'),
        );
    }

    /**
     * A variant spelling resolves to the canonical key in the same round trip,
     * and says so — the caller should never have to make a second call to learn
     * that it asked with the wrong spelling.
     */
    public function testRecallResolvesAVariantSpellingInOneRoundTrip(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'context-shuttle', 'sentences' => 'Ships tool calls over HTTP.']],
        ]);
        self::assertResponseIsSuccessful();

        $body = $this->decode($this->post($client, '/api/recall', ['queries' => [['key' => 'ContextShuttle']]]));

        self::assertCount(1, $body['hits']);
        self::assertSame('context-shuttle', $body['hits'][0]['key']);
        self::assertSame('ContextShuttle', $body['hits'][0]['resolved_from']);
        self::assertSame('Ships tool calls over HTTP.', $body['hits'][0]['entries'][0]['text']);
    }

    /**
     * The failure mode this whole project exists to avoid: an unknown key must
     * come back as an explicit, reported miss — never as an empty hit, and never
     * as a 404 that would be indistinguishable from a broken route.
     */
    public function testRecallReportsAMissExplicitlyRatherThanAsAnEmptyHit(): void
    {
        $body = $this->decode($this->post($this->client(), '/api/recall', ['queries' => [['key' => 'nobody-knows']]]));

        self::assertSame([], $body['hits']);
        self::assertCount(1, $body['misses']);
        self::assertSame('nobody-knows', $body['misses'][0]['key']);
        self::assertArrayHasKey('suggestions', $body['misses'][0]);
    }

    public function testRecallReportsNearMissSuggestions(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'context-shuttle', 'sentences' => 'Knows about tools.']],
        ]);
        self::assertResponseIsSuccessful();

        $body = $this->decode($this->post($client, '/api/recall', ['queries' => [['key' => 'context-shuttl']]]));

        self::assertSame([], $body['hits']);
        self::assertContains('context-shuttle', $body['misses'][0]['suggestions']);
    }

    /**
     * A batch resolves per keyword: one miss must not spoil the hits beside it.
     */
    public function testOneMissDoesNotSpoilTheRestOfTheBatch(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'known', 'sentences' => 'A fact.']]]);
        self::assertResponseIsSuccessful();

        $body = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'known'], ['key' => 'unknown-thing']],
        ]));

        self::assertCount(1, $body['hits']);
        self::assertCount(1, $body['misses']);
    }

    /**
     * `replace` retires the current sentences rather than deleting them, so the
     * write reports what it demoted.
     */
    public function testReplaceRetiresThePreviousSentencesAndReportsThem(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'sentences' => 'The old way.']]]);
        self::assertResponseIsSuccessful();

        $result = $this->decode($this->post($client, '/api/remember', [
            'items' => [['key' => 'deploy', 'sentences' => 'The new way.', 'mode' => 'replace']],
        ]))['results'][0];

        self::assertSame('replace', $result['mode']);
        self::assertSame(['The old way.'], array_column($result['retired'], 'text'));
        self::assertSame(2, $result['revision']);
    }

    public function testKeysEnumeratesTheKeyspaceWithCounts(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'sentences' => 'One. Two.']]]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/keys');
        self::assertResponseIsSuccessful();
        $keys = $this->decodeList($client->getResponse());

        self::assertCount(1, $keys);
        self::assertSame('deploy', $keys[0]['key']);
        self::assertSame(2, $keys[0]['hot']);
        self::assertSame(0, $keys[0]['pinned']);
        self::assertSame(1, $keys[0]['revision']);
    }

    public function testKeysFiltersByPattern(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [
            ['key' => 'project:alpha', 'sentences' => 'Alpha fact.'],
            ['key' => 'person:bob', 'sentences' => 'Bob fact.'],
        ]]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/keys?pattern=project');
        self::assertResponseIsSuccessful();
        $keys = $this->decodeList($client->getResponse());

        self::assertCount(1, $keys);
        self::assertSame('project:alpha', $keys[0]['key']);
    }

    public function testKeysWithoutAQueryStringIsAValidDiscoveryRequest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/keys');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodeList($client->getResponse()));
    }

    public function testStatsReportsCountsAndCaps(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'sentences' => 'One. Two.']]]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/stats');
        self::assertResponseIsSuccessful();
        $stats = $this->decode($client->getResponse());

        self::assertSame(2, $stats['total']);
        self::assertSame(1, $stats['keys']);
        self::assertSame(20, $stats['caps']['hot_per_key']);
        self::assertSame(200, $stats['caps']['cold_per_key']);
    }

    public function testForgetDeletesTheKeyAndItsSentences(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'sentences' => 'One. Two.']]]);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/keys/deploy');
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'deploy', 'deleted' => 2], $this->decode($client->getResponse()));

        $client->request('GET', '/api/keys');
        self::assertSame([], $this->decodeList($client->getResponse()));
    }

    /**
     * Deleting a key that is not there is a caller mistake, so it is a 404 —
     * unlike a recall miss, where the absence is an answer.
     */
    public function testForgetOfAnUnknownKeyIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/keys/never-existed');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->decode($client->getResponse())['deleted']);
    }

    /**
     * The delete resolves spelling variants exactly as a read does, because the
     * store's identity model says those are one key — a delete that silently
     * found nothing over a hyphen would be the worst possible outcome.
     */
    public function testForgetResolvesAVariantSpelling(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'sentences' => 'A fact.']]]);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/keys/DEPLOY');
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'deploy', 'deleted' => 1], $this->decode($client->getResponse()));
    }

    /**
     * An empty batch is a caller bug, not a request for nothing. It must be
     * rejected rather than answered with a cheerful, empty 200 that would look
     * like success.
     */
    public function testAnEmptyRecallBatchIsRejected(): void
    {
        $client = static::createClient();
        $this->post($client, '/api/recall', ['queries' => []]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnEmptyRememberBatchIsRejected(): void
    {
        $client = static::createClient();
        $this->post($client, '/api/remember', ['items' => []]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testABlankKeyIsRejectedBeforeItReachesTheStore(): void
    {
        $client = static::createClient();
        $this->post($client, '/api/recall', ['queries' => [['key' => '']]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $client = static::createClient();
        $this->post($client, '/api/remember', ['items' => [['key' => 'deploy', 'mode' => 'obliterate']]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAMalformedLimitIsRejectedAsABadRequestNotANotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/keys?limit=not-a-number');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testALimitOutsideTheAllowedRangeIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/keys?limit=0');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * `depth` bounds what comes back without hiding the fact that more exists —
     * `shown` and `hot` are reported separately so a caller can tell a short key
     * from a truncated view of a long one.
     */
    public function testDepthBoundsTheViewWithoutHidingTheTotal(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'deploy', 'sentences' => 'One. Two. Three. Four.']],
        ]);
        self::assertResponseIsSuccessful();

        $shallow = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'deploy', 'depth' => 1]],
        ]))['hits'][0];
        self::assertSame(1, $shallow['shown']);
        self::assertSame(4, $shallow['hot']);
        self::assertSame(['One.'], array_column($shallow['entries'], 'text'));

        $deep = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'deploy', 'depth' => 4]],
        ]))['hits'][0];
        self::assertSame(4, $deep['shown']);
    }

    /**
     * `depth` bounds unpinned sentences only. Pinned facts are the ones a
     * caller explicitly asked not to age out, so a shallow recall returns all
     * of them and the requested number of unpinned ones beside them.
     */
    public function testPinnedSentencesSurviveAShallowDepth(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [
                ['key' => 'soul', 'sentences' => 'Pinned one. Pinned two. Pinned three.', 'pin' => true],
                ['key' => 'soul', 'sentences' => 'Noise one. Noise two.'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $hit = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'soul', 'depth' => 1]],
        ]))['hits'][0];

        self::assertSame(3, $hit['pinned']);
        self::assertSame(4, $hit['shown'], 'three pinned plus the one requested');
        self::assertSame(
            ['Pinned one.', 'Pinned two.', 'Pinned three.', 'Noise one.'],
            array_column($hit['entries'], 'text'),
        );
    }

    /**
     * A recall that does not name a depth returns the key's whole current
     * window, not a two-sentence prefix of it.
     *
     * The service's default was 2, which made the cheapest question a model can
     * ask — "what do I know about X?" with no arguments — answer with a sample
     * of the answer. `shown` used to be capped at 2 against a `hot` in double
     * digits, so the caller could not tell a two-sentence key from a fourteen-
     * sentence one it had just been shown two lines of.
     */
    public function testRecallWithoutAnExplicitDepthReturnsTheWholeCurrentWindow(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'soul', 'sentences' => 'One. Two. Three. Four. Five. Six. Seven.']],
        ]);
        self::assertResponseIsSuccessful();

        $hit = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'soul']],
        ]))['hits'][0];

        self::assertSame(7, $hit['hot']);
        self::assertSame(7, $hit['shown'], 'the default depth covers a whole hot window');
        self::assertSame(
            ['One.', 'Two.', 'Three.', 'Four.', 'Five.', 'Six.', 'Seven.'],
            array_column($hit['entries'], 'text'),
        );
    }

    /**
     * A key may be namespaced (`project:alpha`), and the delete route accepts
     * the colon as part of the path segment.
     */
    public function testAKeyContainingAColonCanBeForgotten(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', ['items' => [['key' => 'project:alpha', 'sentences' => 'A fact.']]]);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/keys/project:alpha');
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'project:alpha', 'deleted' => 1], $this->decode($client->getResponse()));
    }

    /**
     * Explicit sentences bypass the prose splitter, which is the escape hatch
     * for when the heuristic would guess wrong.
     */
    public function testExplicitSentencesBypassTheSplitter(): void
    {
        $client = $this->client();

        $result = $this->decode($this->post($client, '/api/remember', [
            'items' => [['key' => 'deploy', 'sentences' => ['Dr. Smith signed off', 'Budget is 3.50']]],
        ]))['results'][0];

        self::assertSame(2, $result['added']);

        $entries = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'deploy', 'depth' => 2]],
        ]))['hits'][0]['entries'];

        self::assertSame(['Dr. Smith signed off', 'Budget is 3.50'], array_column($entries, 'text'));
    }

    /**
     * An omitted `pin` is not an instruction to unpin.
     *
     * The wire is where the distinction has to hold, because the MCP adapter
     * forwards `pin` only when the model actually said something about it — so
     * the common re-statement of a known fact arrives with no `pin` at all, and
     * must leave the existing one alone.
     */
    public function testAnOmittedPinLeavesAnExistingPinAloneOverTheWire(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'soul', 'sentences' => 'Durable fact.', 'pin' => true]],
        ]);

        // No `pin` key at all: exactly what the adapter sends on a re-write.
        $result = $this->decode($this->post($client, '/api/remember', [
            'items' => [['key' => 'soul', 'sentences' => 'Durable fact.']],
        ]))['results'][0];

        self::assertSame(1, $result['renewed']);

        $hit = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'soul']],
        ]))['hits'][0];

        self::assertSame(1, $hit['pinned'], 're-stating a fact must not make it disposable');
    }

    /**
     * `pin: false` is a real instruction, and the only way to take a pin back
     * without `forget` (which would destroy the history the store keeps).
     */
    public function testPinFalseUnpinsOverTheWire(): void
    {
        $client = $this->client();

        $this->post($client, '/api/remember', [
            'items' => [['key' => 'soul', 'sentences' => 'Once durable.', 'pin' => true]],
        ]);
        $this->post($client, '/api/remember', [
            'items' => [['key' => 'soul', 'sentences' => 'Once durable.', 'pin' => false]],
        ]);

        $hit = $this->decode($this->post($client, '/api/recall', [
            'queries' => [['key' => 'soul']],
        ]))['hits'][0];

        self::assertSame(0, $hit['pinned']);
        self::assertSame(1, $hit['hot'], 'unpinning is not the same as forgetting');
    }
}
