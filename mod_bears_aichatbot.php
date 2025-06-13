<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_bears_aichatbot
 * @copyright   Copyright (C) 2024 BearLeeAble (N6REJ). All rights reserved.
 * @license     GNU General Public License version 2 or later; see License.txt
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Factory;

require_once __DIR__ . '/helper.php';

$chatbotData = ModBearsAichatbotHelper::getChatbotData($params);

require ModuleHelper::getLayoutPath('mod_bears_aichatbot', $params->get('layout', 'default'));
