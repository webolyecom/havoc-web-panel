<?php
declare(strict_types=1);

namespace Havoc\Ctl;

interface WebServer
{
    public function type(): string;
    /** systemd unit that serves the sites */
    public function service(): string;
    public function installed(): bool;
    public function version(): string;
    /** Server-level configuration (listeners, user, default certificate). Idempotent, does not start anything. */
    public function prepare(): void;
    /** Create/replace the virtual host for a domain. Must roll back on invalid config where a test is available. */
    public function apply(array $vh): void;
    public function remove(string $domain): void;
    public function reload(): void;
    public function start(): void;
    public function stop(): void;
}
