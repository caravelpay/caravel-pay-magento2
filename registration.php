<?php
/**
 * Caravel Pay for Magento 2 / Adobe Commerce / Mage-OS.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Caravel_Pay', __DIR__);

// The bundled Caravel Pay PHP SDK and connector (copies, refreshed by build.sh).
require_once __DIR__ . '/lib/caravel-connector/autoload.php';
