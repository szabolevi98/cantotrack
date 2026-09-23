<?php

/**
 * The old name of the asset build, kept so that a note or a habit that still
 * says `php bin/build_css.php` does the right thing. The build now makes the
 * JavaScript as well; see bin/build_assets.php.
 */

require __DIR__ . '/build_assets.php';
