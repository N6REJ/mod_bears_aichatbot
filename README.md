# Bears AI Chatbot (Joomla 5)

AI knowledgebase chatbot module for Joomla 5 powered by IONOS AI Model Hub using its OpenAI-compatible API. It grounds answers in your site’s content (selected Joomla categories and optional Kunena forum), supports a floating chat UI with bottom and side positions, and provides fine-grained UI controls.

Key features
- Knowledge grounding
  - Select one or more Joomla categories; published articles are summarized into a context sent to the model.
  - Optionally include Kunena forum posts.
  - Optional Additional Knowledge URLs: fetches and includes HTML-stripped page content.
- IONOS AI Model Hub integration
  - Uses OpenAI-compatible chat completions.
  - Bearer token auth (token only) and configurable endpoint (region host).
  - Dynamic Model dropdown fetches from /v1/models (falls back to a provided list if unavailable).
- UI/UX
  - Positions: bottom-left | bottom-right | middle-left | middle-right (side).
  - Starts closed as a bubble (or vertical label button on side), opens into a resizable window.
  - Configurable open width, height (px), height (% of viewport).
  - Customizable side-button label.
  - Hidden automatically on phones (<= 767px).
  - Auto-scrolls to newest message; grows to fit answers within set height.
- Diagnostics
  - Front-end console logging for request/response.
  - Server returns detailed error info (status, endpoint, model, body) on IONOS failures.
  - Knowledge stats are returned with responses (counts, titles, URLs, notes) for verification.

Requirements
- Joomla 5.x
- PHP 8.x
- IONOS AI Model Hub account with a valid API token
- Outbound HTTPS access to the IONOS OpenAI-compatible endpoint

Quick start
1) Install the module (mod_bears_aichatbot).
2) Publish and assign it to desired pages.
3) Configure (in Module > Basic/Configuration tabs):
   - Article Categories: select categories containing your knowledgebase articles.
   - Use Kunena (optional): include forum posts when Kunena is installed.
   - Additional Knowledge URLs (optional): one URL per line for extra pages to include.
   - IONOS Endpoint: e.g. https://openai.inference.de-txl.ionos.com/v1/chat/completions (use your token’s region host).
   - IONOS Token: paste your Bearer token (do not include quotes/spaces).
   - Model: after saving token/endpoint, select a model from the dynamic dropdown.
   - Positions: bottom-left/right or middle-left/right (side).
   - Open size: set open width (px), open height (px), and open height (%).
   - Button label: text for the vertical toggle on side positions.
   - Set module Caching to No caching.
4) Save, then hard refresh the front-end (Ctrl+F5).

Troubleshooting
- Invalid model (400): ensure the selected model ID exists for your token/region. Check /v1/models.
- Unauthorized (401/403): verify token is correct and authorized.
- 404: wrong region host; use your token’s region endpoint.
- Empty/irrelevant answers: ensure selected categories contain published, accessible articles with the relevant content. Consider adding Additional Knowledge URLs for key pages.
- See browser dev console logs and com_ajax response payloads for detailed context stats and error info.

Developer notes
- Endpoint: defaults to the IONOS OpenAI-compatible chat completions; fully configurable.
- Auth: Bearer token passed via Authorization header.
- Prompt: System prompt + user message; server assembles knowledge context from content sources.
- Admin dynamic field: custom field type fetches models from /v1/models or falls back to a provided list.
