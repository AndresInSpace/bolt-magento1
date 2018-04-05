<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the Bolt extension
 * to a newer versions in the future. If you wish to customize this extension
 * for your needs please refer to http://www.magento.com for more information.
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (http://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

// We used to have an autoloader, but it caused problems in some
// environments. So now we manually load the entire library upfront.
//
// The file is still called Autoload so that existing integration
// instructions continue to work.
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Client.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Configuration.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Diagnostics.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Error.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'ErrorTypes.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Notification.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Request.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'Stacktrace.php';
