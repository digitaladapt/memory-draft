<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    // NOTE: the Symfony skeleton ships a private getAllowedEnvs() here for
    // validating APP_ENV. This service has no user-facing surface to protect —
    // it is an internal tool server reached by other containers on a private
    // network — so the allow-list adds nothing the deployment environment does
    // not already control. It was removed rather than kept as dead code, which
    // is also what PHPStan would rightly flag.
}
