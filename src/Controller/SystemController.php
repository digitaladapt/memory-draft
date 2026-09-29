<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Storage\StoreProbe;
use App\Service\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The service's own endpoints: what it is, and whether it can work.
 *
 * The health/readiness split is the point, and it is not cosmetic (GUIDING-LIGHT
 * §8.4):
 *
 *   /health → is the process up?          → never touches the store → liveness
 *   /ready  → can it actually serve now?  → opens and reads the store → readiness
 *
 * A liveness probe that checked its dependencies would be an outage amplifier:
 * the store goes briefly unreachable, every replica is judged unhealthy at the
 * same instant, the orchestrator restarts them all, and a transient blip becomes
 * a restart storm that outlives the original problem. So `/health` cannot be
 * allowed to touch the store even by accident, which is why it takes no
 * dependency that could.
 *
 * The endpoints sit at the root rather than under `/api` because the Docker
 * `HEALTHCHECK` and the example compose file already probe `/health`; a probe
 * path that only worked under a prefix would be a deployment waiting to fail.
 */
final class SystemController extends AbstractController
{
    private const string APP_NAME = 'memory-draft';

    /**
     * What this service is, and which build of it is running.
     *
     * The version comes from {@see AppVersion} — a `VERSION` file stamped at
     * image build time — never from `git describe` or any other command run in
     * the request path (§8.13).
     */
    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(AppVersion $version): JsonResponse
    {
        return new JsonResponse(
            [
                'name' => self::APP_NAME,
                'version' => $version->read(),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Liveness — the process is up and serving.
     *
     * Deliberately dependency-free: no store, no version file, nothing that can
     * fail for a reason other than "this process is broken". See the class
     * docblock for why that distinction is load-bearing.
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok'],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Readiness — the store can be opened and read, so this instance can serve
     * memory traffic.
     *
     * This one *does* touch the store: that is its job. Failure means "take me
     * out of the pool", not "kill me", which is why it answers 503 while the
     * process stays up. Readiness is "the store opened and answered a query",
     * not "a file exists" — a freshly provisioned volume has no file yet and is
     * nonetheless ready, because the first real request would create it.
     *
     * The count is included so a 200 is evidence a real read happened rather
     * than an assertion that it did.
     */
    #[Route('/ready', name: 'ready', methods: ['GET'])]
    public function ready(StoreProbe $probe): JsonResponse
    {
        try {
            $result = $probe->check();
        } catch (\Throwable $exception) {
            return new JsonResponse(
                [
                    'status' => 'unavailable',
                    'error' => $exception->getMessage(),
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(
            [
                'status' => 'ready',
                'keys' => $result['keys'],
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
