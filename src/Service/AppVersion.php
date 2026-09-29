<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The application version reported by `/about`.
 *
 * The version is stamped at image build time — an `APP_VERSION` build arg
 * written to a `VERSION` file (GUIDING-LIGHT §8.13) — and never derived at
 * request time: running `git describe` or a similar command in a request path
 * forks a process per call and makes the endpoint depend on tools and a
 * checkout that the image deliberately does not carry.
 *
 * When nothing was stamped — a local checkout, a development build — the
 * honest answer is `unknown`, not a guess.
 */
final class AppVersion
{
    private const UNKNOWN = 'unknown';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function read(): string
    {
        $file = $this->projectDir.'/VERSION';
        if (!is_file($file)) {
            return self::UNKNOWN;
        }

        $version = trim((string) file_get_contents($file));

        // CI strips the "v" when tagging, but a hand-built image may carry it.
        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }

        return '' === $version || 'dev' === $version ? self::UNKNOWN : $version;
    }
}
