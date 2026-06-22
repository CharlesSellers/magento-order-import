<?php
/**
 * Venuno Order Import — Magento 2 module registration.
 *
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Venuno_OrderImport', __DIR__);
