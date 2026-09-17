<?php

declare(strict_types=1);

/**
 * Architecture presets over the package's own namespaces. Pest applies them to the Composer
 * autoload namespaces outside `tests/`, so test code is not held to them.
 *
 * @command  vendor/bin/pest --compact tests/ArchTest.php
 */
arch()->preset()->php();

arch()->preset()->security();

arch()->preset()->strict();
