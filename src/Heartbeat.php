<?php

namespace Veltrom\License;

use Closure;
use Veltrom\License\Wordpress\Wordpress;

/**
 * The daily validate (sdk-wordpress.md "Heartbeat"): one single event at a
 * day ± 6 hours, rescheduled at the end of every run so the jitter
 * re-randomises. A missed or late run is never a licensing failure.
 */
final class Heartbeat
{
    public const INTERVAL = 86400;

    public const JITTER = 21600;

    /**
     * @param  Closure(): Result  $validate
     */
    public function __construct(
        private readonly Config $config,
        private readonly Wordpress $wp,
        private readonly Closure $validate,
    ) {
    }

    public function hook(): string
    {
        return $this->config->prefix() . 'heartbeat';
    }

    public function register(): void
    {
        $this->wp->addAction($this->hook(), fn () => $this->run(), 10, 0);

        if ($this->wp->nextScheduled($this->hook()) === false) {
            $this->schedule();
        }
    }

    public function run(): void
    {
        try {
            ($this->validate)();
        } finally {
            $this->wp->clearScheduledHook($this->hook());
            $this->schedule();
        }
    }

    /**
     * A check as soon as WP-Cron next runs: an unknown signing key or a clock
     * behind the server's needs the network, which can() never touches.
     */
    public function runSoon(): void
    {
        $next = $this->wp->nextScheduled($this->hook());

        if ($next === false || $next > $this->wp->now() + 60) {
            $this->wp->clearScheduledHook($this->hook());
            $this->wp->scheduleSingleEvent($this->wp->now(), $this->hook());
        }
    }

    public function unschedule(): void
    {
        $this->wp->clearScheduledHook($this->hook());
    }

    private function schedule(): void
    {
        $this->wp->scheduleSingleEvent($this->wp->now() + self::INTERVAL + $this->wp->randomInt(-self::JITTER, self::JITTER), $this->hook());
    }
}
