<?php

declare(strict_types=1);

/**
 * COMPAT shim — route bodies live in owner addons.
 * Registration group still owned by SeoPanelProvider (`prefix('api')`).
 */

require base_path('addons/wordpress/routes/seo-wp-bridge.php');
require base_path('addons/content-projects/routes/api-v1.php');
