<?php

namespace Goldnead\Smartlinks\Contracts;

/**
 * Turns a host name into its IP addresses, for the link check's SSRF guard.
 * Bound so tests (and hosts with their own DNS policy) can swap it.
 */
interface HostResolver
{
    /**
     * Every A and AAAA address of the host; an empty list when it does not
     * resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
