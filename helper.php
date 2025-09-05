<?php
/**
 * mod_bears_aichatbot - AI Knowledgebase Chatbot for Joomla 5
 * Helper for building a knowledge base dataset from Joomla content and Kunena (if available)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Component\ComponentHelper;

// Use Content route helper if available (J4/5 site helper)
if (!class_exists('ContentHelperRoute') && class_exists('Joomla\\Component\\Content\\Site\\Helper\\RouteHelper')) {
    class ContentHelperRoute extends Joomla\Component\Content\Site\Helper\RouteHelper {}
}

class ModBearsAichatbotHelper
{
    /**
     * Provide template configuration and simple data.
     *
     * @param \Joomla\Registry\Registry $params
     * @return array
     */
    public static function getChatbotData($params): array
    {
        return [
            'chat_position'      => (string) $params->get('chat_position', 'bottom-right'),
            'chat_offset_bottom' => (int) $params->get('chat_offset_bottom', 20),
            'chat_offset_side'   => (int) $params->get('chat_offset_side', 20),
            'intro_text'         => (string) $params->get('intro_text', "Hello! I'm your AI assistant. Ask me anything about our knowledge base and I'll help you find the information you need."),
        ];
    }

    /**
     * Build the knowledge base dataset from selected sources.
     * Mirrors the category-aware search engine patterns from mod_bearslivesearch
     * using Joomla 5's DB query builder.
     *
     * @param \Joomla\Registry\Registry $params
     * @param int $limitPerSource Limit results per source (articles/kunena)
     * @return array{count:int, items:array<int, array>}
     */
    public static function getKnowledgebase($params, int $limitPerSource = 50): array
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();
        $lang = $app->getLanguage()->getTag();

        $contentCats = $params->get('content_categories', []);
        if (is_string($contentCats)) {
            // Handle CSV or JSON stored values
            $decoded = null;
            if (str_starts_with($contentCats, '[')) {
                $decoded = json_decode($contentCats, true);
            }
            if (is_array($decoded)) {
                $contentCats = $decoded;
            } else {
                $contentCats = array_filter(array_map('intval', preg_split('/\s*,\s*/', $contentCats)));
            }
        }
        if (!is_array($contentCats)) {
            $contentCats = [];
        }
        $contentCats = array_values(array_unique(array_map('intval', $contentCats)));

        $items = [];

        // Articles
        $items = array_merge($items, self::fetchArticles($contentCats, $limitPerSource, $user, $lang));

        // Kunena (optional)
        $useKunena = (int) $params->get('use_kunena', 0);
        if ($useKunena && ComponentHelper::isEnabled('com_kunena')) {
            // No explicit Kunena category filter field per latest request; include all published categories
            $items = array_merge($items, self::fetchKunena([], $limitPerSource, $user));
        }

        // Normalize ordering across sources by modified/created date desc
        usort($items, function ($a, $b) {
            return strcmp(($b['modified'] ?? ''), ($a['modified'] ?? ''));
        });

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Fetch published, accessible Joomla articles under selected categories.
     */
    protected static function fetchArticles(array $categoryIds, int $limit, $user, string $lang): array
    {
        $db       = Factory::getDbo();
        $nullDate = $db->getNullDate();
        $now      = Factory::getDate()->toSql();
        $levels   = Access::getAuthorisedViewLevels($user ? (int) $user->id : 0);

        $query = $db->getQuery(true);
        $query->select([
                'a.id', 'a.title', 'a.alias', 'a.introtext', 'a.fulltext',
                'a.catid', 'a.language',
                'COALESCE(NULLIF(a.modified, ' . $db->quote($nullDate) . '), a.created) AS mdate',
                'c.title AS category_title'
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up = ' . $db->quote($nullDate) . ' OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down = ' . $db->quote($nullDate) . ' OR a.publish_down >= ' . $db->quote($now) . ')')
            ->where('a.access IN (' . implode(',', array_map('intval', $levels)) . ')');

        if (Multilanguage::isEnabled()) {
            $query->where('(a.language = ' . $db->quote($lang) . ' OR a.language = ' . $db->quote('*') . ')');
        }

        if (!empty($categoryIds)) {
            $query->where('a.catid IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
        }

        $query->order('mdate DESC');

        $db->setQuery($query, 0, $limit);
        $rows = (array) $db->loadAssocList();

        $items = [];
        foreach ($rows as $r) {
            $id       = (int) $r['id'];
            $catid    = (int) $r['catid'];
            $language = (string) ($r['language'] ?? '*');
            $mdate    = (string) ($r['mdate'] ?? '');

            $url = '';
            if (class_exists('ContentHelperRoute')) {
                try {
                    $url = Route::_(ContentHelperRoute::getArticleRoute($id, $catid, $language));
                } catch (\Throwable $e) {
                    $url = Route::_('index.php?option=com_content&view=article&id=' . $id);
                }
            }

            $text = self::prepareArticleText((string) ($r['introtext'] ?? ''), (string) ($r['fulltext'] ?? ''));

            $items[] = [
                'type'           => 'article',
                'id'             => $id,
                'title'          => (string) $r['title'],
                'url'            => $url,
                'category_id'    => $catid,
                'category_title' => (string) ($r['category_title'] ?? ''),
                'text'           => $text,
                'language'       => $language ?: '*',
                'modified'       => $mdate,
            ];
        }

        return $items;
    }

    protected static function prepareArticleText(string $introtext = null, string $fulltext = null): string
    {
        $text = (string) $introtext;
        if (!empty($fulltext)) {
            $text .= "\n" . (string) $fulltext;
        }
        // Keep it simple and safe: strip tags and entities; one space collapse
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Fetch published, visible Kunena messages across published categories.
     * Filters to approved (hold=0) topics and messages.
     */
    protected static function fetchKunena(array $categoryIds, int $limit, $user): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                'm.id AS message_id',
                't.id AS topic_id',
                'm.subject',
                'm.message',
                'c.id AS catid',
                'c.name AS category_title',
                'COALESCE(NULLIF(m.modified_time, ' . $db->quote($db->getNullDate()) . '), m.time) AS mdate'
            ])
            ->from($db->quoteName('#__kunena_messages', 'm'))
            ->join('INNER', $db->quoteName('#__kunena_topics', 't') . ' ON t.id = m.thread')
            ->join('INNER', $db->quoteName('#__kunena_categories', 'c') . ' ON c.id = t.category_id')
            ->where('c.published = 1')
            ->where('t.hold = 0')
            ->where('m.hold = 0');

        if (!empty($categoryIds)) {
            $query->where('c.id IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
        }

        $query->order('mdate DESC');

        try {
            $db->setQuery($query, 0, $limit);
            $rows = (array) $db->loadAssocList();
        } catch (\Throwable $e) {
            // Schema or existence mismatch: return nothing silently
            return [];
        }

        $items = [];
        foreach ($rows as $r) {
            $topicId = (int) $r['topic_id'];
            $catid   = (int) $r['catid'];
            $url     = Route::_('index.php?option=com_kunena&view=topic&catid=' . $catid . '&id=' . $topicId);

            $text = self::cleanKunenaMessage((string) ($r['message'] ?? ''));

            $items[] = [
                'type'           => 'kunena',
                'id'             => (int) $r['message_id'],
                'title'          => (string) ($r['subject'] ?? ('Topic #' . $topicId)),
                'url'            => $url,
                'category_id'    => $catid,
                'category_title' => (string) ($r['category_title'] ?? ''),
                'text'           => $text,
                'language'       => '*',
                'modified'       => (string) ($r['mdate'] ?? ''),
            ];
        }

        return $items;
    }

    protected static function cleanKunenaMessage(string $message = null): string
    {
        $text = (string) $message;
        // Remove common BBCode tags conservatively
        $text = preg_replace('/\[(?:\/?)(?:b|i|u|quote|code|url|img|size|color|list|\*|spoiler|hide|attachment|video)(?:=[^\]]*)?\]/i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
