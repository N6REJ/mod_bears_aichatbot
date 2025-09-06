<?php
/**
 * mod_bears_aichatbot - AI Knowledgebase Chatbot for Joomla 5
 * Helper functions
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Http\HttpFactory;

class ModBearsAichatbotHelper
{
    protected static $lastContextStats = [];
    /**
     * AJAX endpoint: Answer a user question using IONOS Model Hub with knowledge from selected categories.
     * Route example (via com_ajax):
     *   index.php?option=com_ajax&module=bears_aichatbot&method=ask&format=json&module_id=123
     * Input parameters: message (string), module_id (int)
     *
     * @return array
     */
    public static function askAjax()
    {
        $app   = Factory::getApplication();
        $input = $app->input;

        $moduleId = $input->getInt('module_id');
        if (!$moduleId) {
            return ['success' => false, 'error' => 'Missing module_id'];
        }

        $module = ModuleHelper::getModuleById($moduleId);
        if (!$module || !isset($module->params)) {
            return ['success' => false, 'error' => 'Module not found'];
        }
        $params = new Registry($module->params);

        $message = trim($input->getString('message', ''));
        if ($message === '') {
            return ['success' => false, 'error' => 'Empty message'];
        }

        // Build knowledge base context from selected categories
        $context = self::buildKnowledgeContext($params, $message);
        $kbStats = self::$lastContextStats;

        // IONOS configuration (read from module params defined in XML)
        $tokenId = trim((string) $params->get('ionos_token_id', ''));
        $token   = trim((string) $params->get('ionos_token', ''));
        // Allow custom model and endpoint via module settings
        $model   = trim((string) $params->get('ionos_model', ''));
        $endpoint = trim((string) $params->get('ionos_endpoint', 'https://openai.inference.de-txl.ionos.com/v1/chat/completions'));

        // If params seem empty, try a direct DB fetch of the module params by id as a fallback
        if ($tokenId === '' || $token === '' || $model === '') {
            try {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $q  = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__modules'))
                    ->where($db->quoteName('id') . ' = ' . (int) $moduleId)
                    ->setLimit(1);
                $db->setQuery($q);
                $rawParams = (string) $db->loadResult();
                if ($rawParams !== '') {
                    $p2 = new Registry($rawParams);
                    if ($tokenId === '') { $tokenId = trim((string) $p2->get('ionos_token_id', '')); }
                    if ($token === '')   { $token   = trim((string) $p2->get('ionos_token', '')); }
                    if ($model === '')   { $model   = trim((string) $p2->get('ionos_model', '')); }
                }
            } catch (\Throwable $e) {
                // ignore fallback errors; will report missing below
            }
        }

        // Build a detailed missing-list for easier diagnosis (Bearer token and model required)
        $missing = [];
        if ($token === '')   { $missing[] = 'Token'; }
        if ($model === '')   { $missing[] = 'Model'; }
        if (!empty($missing)) {
            return ['success' => false, 'error' => 'Missing: ' . implode(', ', $missing)];
        }

        // System prompt includes the KB context from Joomla articles
        $systemPrompt = "You are a helpful AI assistant for a Joomla website. Use ONLY the provided knowledge base context when possible. If the context lacks the answer, say you don't know and suggest related topics. Knowledge base context follows between <kb> tags.\n<kb>" . $context . '</kb>';

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $message],
            ],
            'max_tokens'  => 512,
            'temperature' => 0.2,
        ];

        // OpenAI-compatible chat completions endpoint (IONOS Model Hub)
        $url = $endpoint !== '' ? $endpoint : 'https://openai.inference.de-txl.ionos.com/v1/chat/completions';

        try {
            $http = HttpFactory::getHttp();
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];

            $response = $http->post($url, json_encode($payload), $headers);

            if ($response->code < 200 || $response->code >= 300) {
                $respBody = '';
                try {
                    if (method_exists($response, 'getBody')) {
                        $respBody = (string) $response->getBody();
                    } elseif (isset($response->body)) {
                        $respBody = (string) $response->body;
                    }
                } catch (\Throwable $ignore) {}
                $detail = '';
                if ($respBody !== '') {
                    $errJson = json_decode($respBody, true);
                    if (is_array($errJson)) {
                        if (isset($errJson['error'])) {
                            // Could be string or object with message
                            if (is_array($errJson['error']) && isset($errJson['error']['message'])) {
                                $detail = (string) $errJson['error']['message'];
                            } elseif (is_string($errJson['error'])) {
                                $detail = $errJson['error'];
                            }
                        } elseif (isset($errJson['message'])) {
                            $detail = (string) $errJson['message'];
                        } elseif (isset($errJson['detail'])) {
                            $detail = (string) $errJson['detail'];
                        }
                    }
                }
                $errMsg = 'IONOS request failed (status ' . $response->code . ')';
                if ($detail !== '') {
                    $errMsg .= ': ' . $detail;
                }
                return [
                    'success'  => false,
                    'error'    => $errMsg,
                    'status'   => $response->code,
                    'endpoint' => $url,
                    'model'    => $model,
                    'body'     => $respBody !== '' ? mb_substr($respBody, 0, 2000) : null,
                ];
            }

            $body = json_decode($response->body, true);
            if (!is_array($body) || empty($body['choices'][0]['message']['content'])) {
                return ['success' => false, 'error' => 'Unexpected response from IONOS'];
            }

            $answer = trim((string) $body['choices'][0]['message']['content']);

            return [
                'success' => true,
                'answer'  => $answer,
                'kb'      => $kbStats,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'kb' => $kbStats ?? null];
        }
    }

    /**
     * Build a compact knowledge context string from selected Joomla content categories
     * and optionally Kunena forum content when enabled.
     *
     * @param Registry $params
     * @return string
     */
    protected static function buildKnowledgeContext(Registry $params, string $userMessage): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Stats holder
        self::$lastContextStats = [
            'article_count' => 0,
            'kunena_count'  => 0,
            'url_count'     => 0,
            'article_titles'=> [],
            'kunena_titles' => [],
            'urls'          => [],
            'note'          => '',
        ];

        // Budget for context to avoid exceeding token limits
        $maxTotal = 30000;
        $contextParts = [];
        $total = 0;

        // Article fetch limit (configurable, default 500)
        $limit = (int) $params->get('article_limit', 500);
        if ($limit < 1) { $limit = 500; }

        // Additional knowledge URLs
        $extraUrls = trim((string) $params->get('additional_urls', ''));
        if ($extraUrls !== '') {
            $urls = preg_split('/\r?\n/', $extraUrls);
            $urls = array_map('trim', $urls);
            $urls = array_filter($urls, function ($u) { return $u !== '' && preg_match('#^https?://#i', $u); });
            if (!empty($urls)) {
                $http = HttpFactory::getHttp();
                foreach ($urls as $u) {
                    try {
                        $resp = $http->get($u, [ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ]);
                        if ($resp->code >= 200 && $resp->code < 300) {
                            $body = (string) $resp->body;
                            // Basic HTML to text
                            $text = strip_tags($body);
                            $text = preg_replace('/\s+/', ' ', $text);
                            $snippet = mb_substr($text, 0, 1000);
                            $part = 'URL: ' . $u . "\n" . 'Content:' . "\n" . $text;
                            $len = mb_strlen($part);
                            if ($total + $len <= $maxTotal) {
                                $contextParts[] = $part;
                                $total += $len;
                                self::$lastContextStats['urls'][] = $u;
                                self::$lastContextStats['url_count']++;
                            }
                        } else {
                            self::$lastContextStats['note'] = trim(self::$lastContextStats['note'] . ' URL ' . $u . ' HTTP ' . $resp->code);
                        }
                    } catch (\Throwable $e) {
                        self::$lastContextStats['note'] = trim(self::$lastContextStats['note'] . ' URL ' . $u . ' error: ' . $e->getMessage());
                    }
                }
            }
        }

        // Joomla Articles by selected categories
        $catIds = $params->get('content_categories', []);
        if (is_string($catIds)) {
            $catIds = array_filter(array_map('intval', explode(',', $catIds)));
        } elseif (is_array($catIds)) {
            $catIds = array_map('intval', $catIds);
        } else {
            $catIds = [];
        }

        $items = [];
        if (!empty($catIds)) {
            // Expand to include descendant categories
            $allCatIds = [];
            try {
                $cq = $db->getQuery(true)
                    ->select($db->quoteName(['id','lft','rgt']))
                    ->from($db->quoteName('#__categories'))
                    ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                    ->where($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $catIds)) . ')');
                $db->setQuery($cq);
                $ranges = (array) $db->loadAssocList();
                if ($ranges) {
                    $ors = [];
                    foreach ($ranges as $r) {
                        $ors[] = '(' . $db->quoteName('lft') . ' >= ' . (int) $r['lft'] . ' AND ' . $db->quoteName('rgt') . ' <= ' . (int) $r['rgt'] . ')';
                    }
                    $dcq = $db->getQuery(true)
                        ->select($db->quoteName('id'))
                        ->from($db->quoteName('#__categories'))
                        ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                        ->where($db->quoteName('published') . ' = 1')
                        ->where(implode(' OR ', $ors));
                    $db->setQuery($dcq);
                    $allCatIds = array_map('intval', (array) $db->loadColumn());
                }
            } catch (\Throwable $e) {
                $allCatIds = $catIds;
            }
            if (empty($allCatIds)) { $allCatIds = $catIds; }

            // Base query within selected categories (and descendants)
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title', 'introtext', 'fulltext']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('state') . ' = 1')
                ->where($db->quoteName('catid') . ' IN (' . implode(',', $allCatIds) . ')');

            // Apply simple keyword relevance if user provided a message
            $userMessage = trim($userMessage);
            if ($userMessage !== '') {
                $terms = preg_split('/\s+/', mb_strtolower($userMessage));
                $likes = [];
                $maxTerms = 5;
                foreach ($terms as $t) {
                    $t = trim($t);
                    if (mb_strlen($t) < 3) continue;
                    $kw = $db->escape($t, true);
                    $like = $db->quote('%' . $kw . '%', false);
                    $likes[] = '(' . $db->quoteName('title') . ' LIKE ' . $like . ' OR ' . $db->quoteName('introtext') . ' LIKE ' . $like . ' OR ' . $db->quoteName('fulltext') . ' LIKE ' . $like . ')';
                    if (count($likes) >= $maxTerms) break;
                }
                if (!empty($likes)) {
                    $query->where('(' . implode(' OR ', $likes) . ')');
                }
            }

            $query->order($db->escape('modified DESC, created DESC'));

            $db->setQuery($query, 0, $limit);
            $items = (array) $db->loadAssocList();

            // Fallback to recent items if no keyword matches
            if (!$items) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title', 'introtext', 'fulltext']))
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->where($db->quoteName('catid') . ' IN (' . implode(',', $allCatIds) . ')')
                    ->order($db->escape('modified DESC, created DESC'));
                $db->setQuery($query, 0, $limit);
                $items = (array) $db->loadAssocList();
                if (!$items) {
                    self::$lastContextStats['note'] = 'No matches in selected categories; falling back to site-wide recent articles.';
                }
            }
        }

        // If no categories are selected or no items found, fall back to site-wide published articles
        if (empty($items)) {
            $qAll = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title', 'introtext', 'fulltext']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('state') . ' = 1')
                ->order($db->escape('modified DESC, created DESC'));
            $db->setQuery($qAll, 0, $limit);
            $items = (array) $db->loadAssocList();
        }

        if ($items) {
            foreach ($items as $row) {
                $content = strip_tags((string)($row['introtext'] ?? '') . "\n" . (string)($row['fulltext'] ?? ''));
                $content = preg_replace('/\s+/', ' ', $content);
                $snippet = mb_substr($content, 0, 600);
                $part = 'Title: ' . $row['title'] . "\n" . 'Content:' . "\n" . $content;

                $len = mb_strlen($part);
                if ($total + $len > $maxTotal) {
                    break;
                }
                $contextParts[] = $part;
                $total += $len;
                if (count(self::$lastContextStats['article_titles']) < 5) {
                    self::$lastContextStats['article_titles'][] = (string) $row['title'];
                }
                self::$lastContextStats['article_count']++;
            }
        }

        // Kunena forum content when enabled
        $useKunena = (int) $params->get('use_kunena', 1) === 1;
        if ($useKunena && $total < $maxTotal) {
            try {
                $kquery = $db->getQuery(true)
                    ->select($db->quoteName(['m.id', 'm.subject']))
                    ->select($db->quoteName('mt.message'))
                    ->from($db->quoteName('#__kunena_messages', 'm'))
                    ->join('INNER', $db->quoteName('#__kunena_messages_text', 'mt') . ' ON ' . $db->quoteName('mt.mesid') . ' = ' . $db->quoteName('m.id'))
                    ->join('INNER', $db->quoteName('#__kunena_categories', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('m.catid'))
                    ->where($db->quoteName('c.published') . ' = 1')
                    ->where($db->quoteName('m.hold') . ' = 0')
                    ->order($db->escape('m.time DESC'));

                $db->setQuery($kquery, 0, min($limit, 100));
                $kitems = $db->loadAssocList();

                if ($kitems) {
                    foreach ($kitems as $row) {
                        $content = strip_tags((string)($row['message'] ?? ''));
                        $content = preg_replace('/\s+/', ' ', $content);
                        $snippet = mb_substr($content, 0, 600);
                        $subject = trim((string)($row['subject'] ?? 'Forum Post'));
                        $part = 'Forum: ' . ($subject !== '' ? $subject : 'Forum Post') . "\n" . 'Content: ' . $snippet;

                        $len = mb_strlen($part);
                        if ($total + $len > $maxTotal) {
                            break;
                        }
                        $contextParts[] = $part;
                        $total += $len;
                        if (count(self::$lastContextStats['kunena_titles']) < 5) {
                            self::$lastContextStats['kunena_titles'][] = $subject !== '' ? $subject : 'Forum Post';
                        }
                        self::$lastContextStats['kunena_count']++;
                    }
                }
            } catch (\Throwable $e) {
                // Kunena likely not installed or tables missing; ignore silently
            }
        }

        if (empty($contextParts)) {
            self::$lastContextStats['note'] = self::$lastContextStats['note'] ?: 'No knowledge available from selected sources.';
            return 'No knowledge available from selected sources.';
        }

        return implode("\n---\n", $contextParts);
    }
}
