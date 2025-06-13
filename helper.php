<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_bears_aichatbot
 * @copyright   Copyright (C) 2024 BearLeeAble (N6REJ). All rights reserved.
 * @license     GNU General Public License version 2 or later; see License.txt
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class ModBearsAichatbotHelper
{
    /**
     * Get chatbot data and configuration
     */
    public static function getChatbotData($params)
    {
        $useKunena = (bool) $params->get('use_kunena', 1);
        $chatPosition = $params->get('chat_position', 'bottom');
        $chatMargin = (int) $params->get('chat_margin', 20);
        return [
            'use_kunena' => $useKunena,
            'chat_position' => $chatPosition,
            'chat_margin' => $chatMargin,
        ];
    }

    /**
     * Placeholder: Search Joomla articles, fields, and Kunena posts
     * Integrate with open-source LLM here
     */
    public static function searchKnowledgebase($query, $useKunena = true)
    {
        // TODO: Implement search logic for articles, fields, and Kunena posts
        // TODO: Integrate with local LLM (e.g., llama.cpp or similar)
        return 'AI response placeholder for: ' . htmlspecialchars($query);
    }
}
