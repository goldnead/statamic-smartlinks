<?php

namespace Goldnead\Smartlinks\Contracts;

/**
 * A resolver that matches by name, not by ISRC or UPC. Its finds are never
 * written to the entry: they are stored as suggestions and wait for someone
 * to accept or reject them in the Control Panel.
 */
interface SuggestsOnly extends Resolver {}
