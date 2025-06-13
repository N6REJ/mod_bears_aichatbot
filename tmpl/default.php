<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_bears_aichatbot
 * @copyright   Copyright (C) 2024 BearLeeAble (N6REJ). All rights reserved.
 * @license     GNU General Public License version 2 or later; see License.txt
 */

// No direct access
defined('_JEXEC') or die;

$chatPosition = $chatbotData['chat_position'];
$chatMargin = (int) $chatbotData['chat_margin'];
$marginStyle = '';
switch ($chatPosition) {
    case 'top':
        $marginStyle = "top: {$chatMargin}px; left: 50%; transform: translateX(-50%);";
        break;
    case 'right':
        $marginStyle = "top: 50%; right: {$chatMargin}px; transform: translateY(-50%);";
        break;
    case 'bottom':
        $marginStyle = "bottom: {$chatMargin}px; left: 50%; transform: translateX(-50%);";
        break;
    case 'left':
        $marginStyle = "top: 50%; left: {$chatMargin}px; transform: translateY(-50%);";
        break;
}
?>
<div id="bears-aichatbot" class="bears-aichatbot bears-aichatbot-<?php echo $chatPosition; ?>" style="position:fixed;z-index:9999;<?php echo $marginStyle; ?>">
    <div class="bears-aichatbot-header">
        <span><?php echo JText::_('MOD_BEARS_AICHATBOT_TITLE'); ?></span>
    </div>
    <div class="bears-aichatbot-messages" id="bears-aichatbot-messages"></div>
    <form class="bears-aichatbot-form" id="bears-aichatbot-form" onsubmit="return false;">
        <input type="text" id="bears-aichatbot-input" placeholder="<?php echo JText::_('MOD_BEARS_AICHATBOT_PLACEHOLDER'); ?>" autocomplete="off" />
        <button type="submit"><?php echo JText::_('MOD_BEARS_AICHATBOT_SEND'); ?></button>
    </form>
</div>
<link rel="stylesheet" href="modules/mod_bears_aichatbot/css/chatbot.css" />
<script src="modules/mod_bears_aichatbot/js/chatbot.js"></script>
<script>
document.getElementById('bears-aichatbot-form').addEventListener('submit', function() {
    var input = document.getElementById('bears-aichatbot-input');
    var msg = input.value.trim();
    if (!msg) return;
    var messages = document.getElementById('bears-aichatbot-messages');
    var userMsg = document.createElement('div');
    userMsg.className = 'bears-aichatbot-message user';
    userMsg.textContent = msg;
    messages.appendChild(userMsg);
    input.value = '';
    // TODO: AJAX call to PHP backend for AI response
    var aiMsg = document.createElement('div');
    aiMsg.className = 'bears-aichatbot-message ai';
    aiMsg.textContent = '...';
    messages.appendChild(aiMsg);
    // Simulate response for now
    setTimeout(function() {
        aiMsg.textContent = 'AI response placeholder for: ' + msg;
    }, 1000);
});
</script>
