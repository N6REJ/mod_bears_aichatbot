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
$bottomOffset = (int) $chatbotData['chat_offset_bottom'];
$sideOffset = (int) $chatbotData['chat_offset_side'];
$introText = json_encode($chatbotData['intro_text'], JSON_HEX_APOS | JSON_HEX_QUOT);
$positionStyle = "bottom: {$bottomOffset}px; " . ($chatPosition === 'bottom-right' ? "right: {$sideOffset}px;" : "left: {$sideOffset}px;");
$app = \Joomla\CMS\Factory::getApplication();
$menu = $app->getMenu();
$active = $menu->getActive();
$itemId = $active ? (int) $active->id : 0;
?>
<button id="bears-aichatbot-toggle" class="bears-aichatbot-toggle" style="position:fixed;z-index:9999;<?php echo $positionStyle; ?>" aria-controls="bears-aichatbot" aria-expanded="false" title="Knowledge Base Chat">KB</button>
<div id="bears-aichatbot" class="bears-aichatbot bears-aichatbot-<?php echo $chatPosition; ?> bears-aichatbot-hidden" style="position:fixed;z-index:10000;<?php echo $positionStyle; ?>; margin-bottom: 56px;">
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
(function(){
  var toggle = document.getElementById('bears-aichatbot-toggle');
  var panel = document.getElementById('bears-aichatbot');
  var messages = document.getElementById('bears-aichatbot-messages');
  var introShown = false;
  
  // Function to scroll messages to bottom
  function scrollToBottom() {
    messages.scrollTop = messages.scrollHeight;
  }
  
  toggle.addEventListener('click', function(){
    var hidden = panel.classList.toggle('bears-aichatbot-hidden');
    toggle.setAttribute('aria-expanded', String(!hidden));
    
    // Show intro text on first open
    if (!hidden && !introShown) {
      var introMsg = document.createElement('div');
      introMsg.className = 'bears-aichatbot-message ai intro';
      introMsg.textContent = <?php echo $introText; ?>;
      messages.appendChild(introMsg);
      scrollToBottom();
      introShown = true;
    }
  });

  document.getElementById('bears-aichatbot-form').addEventListener('submit', function() {
      var input = document.getElementById('bears-aichatbot-input');
      var msg = input.value.trim();
      if (!msg) return;
      var messages = document.getElementById('bears-aichatbot-messages');
      var userMsg = document.createElement('div');
      userMsg.className = 'bears-aichatbot-message user';
      userMsg.textContent = msg;
      messages.appendChild(userMsg);
      scrollToBottom();
      input.value = '';
      var aiMsg = document.createElement('div');
      aiMsg.className = 'bears-aichatbot-message ai';
      aiMsg.textContent = '...';
      messages.appendChild(aiMsg);
      scrollToBottom();

      // AJAX call to com_ajax -> helper::ask (IONOS backend)
      var xhr = new XMLHttpRequest();
      var url = 'index.php?option=com_ajax&module=bears_aichatbot&method=ask&format=json&Itemid=<?php echo $itemId; ?>&_=' + Date.now();
      xhr.open('POST', url, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.timeout = 30000;

      xhr.onreadystatechange = function() {
          if (xhr.readyState === 4) {
              var text = '';
              if (xhr.status >= 200 && xhr.status < 300) {
                  try {
                      var res = JSON.parse(xhr.responseText || '{}');

                      if (res && res.success && res.data) {
                          if (Array.isArray(res.data) && res.data.length > 0) {
                              text = String(res.data[0]);
                          } else if (typeof res.data === 'string') {
                              text = res.data;
                          } else {
                              text = JSON.stringify(res.data);
                          }
                      } else if (res && res.message) {
                          text = String(res.message);
                      } else if (res && res.data) {
                          text = String(res.data);
                      } else if (typeof res === 'string') {
                          text = res;
                      } else {
                          text = 'Empty response from server';
                      }
                  } catch (e) {
                      text = xhr.responseText || 'No response from server';
                      console.error('AJAX parse error', e, xhr.responseText);
                  }
              } else {
                  text = 'HTTP ' + xhr.status + ' ' + xhr.statusText;
              }
              aiMsg.textContent = text;
              scrollToBottom();
          }
      };

      xhr.onerror = function() {
          aiMsg.textContent = 'Network error contacting server';
          scrollToBottom();
      };

      xhr.ontimeout = function() {
          aiMsg.textContent = 'Request timed out';
          scrollToBottom();
      };

      xhr.send('q=' + encodeURIComponent(msg));
  });
})();
</script>
