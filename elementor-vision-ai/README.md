# Elementor Vision AI (MVP)

## Simple setup for most users: Gemini
Most WordPress users only need a Google Gemini API key.

1. Install and activate the plugin.
2. Open **Elementor Vision AI → Settings**.
3. Choose **Gemini Vision (Recommended)**.
4. Leave **gemini-2.5-flash (recommended)** selected unless you need another Gemini model.
5. Open Google AI Studio: `https://aistudio.google.com/app/apikey`.
6. Sign in, click **Create API Key**, copy the key, and paste it into the plugin.
7. Save settings.
8. Upload a screenshot and click **Generate Template**.

No AI Server URL, Node.js, managed worker, or server setup is required for Gemini mode.

## Advanced setup: Ollama
Ollama is only for agencies or advanced users who want free per-generation AI on their own server.

In Ollama mode, the WordPress plugin sends the screenshot to your AI server. Your developer or agency must provide the **AI Server URL**, for example:

```text
https://ai.youragency.com
```

### On the AI server
1. Install Ollama: `https://ollama.com/download`.
2. Pull the model:
   ```bash
   ollama pull llama3
   ```
3. Start the AI worker service from `services/orchestrator`:
   ```bash
   npm install
   npm start
   ```
4. Put the service behind HTTPS and paste that public URL into the plugin settings.

## Backend decision guide
### Gemini
- Easiest setup
- Only requires Google API key
- Default model: gemini-2.5-flash
- Optional models: gemini-2.5-pro or gemini-1.5-flash
- No server needed
- Recommended for most users

### Ollama
- Free per generation
- Requires your own server
- Best for agencies/advanced users

## Architecture
- Gemini mode: WordPress calls Google Gemini directly and generates Elementor JSON inside the plugin.
- Ollama mode: WordPress calls your AI server because Ollama must run on your own infrastructure.
- Admin UI: upload screenshot, generate template, preview image, download JSON.

## Temporary Gemini debug test
If template generation times out, use **Test Gemini Only** on the upload screen.

This sends the uploaded screenshot to Gemini with only this prompt:

```text
Describe this screenshot in 5 bullet points.
```

If the test is fast but **Generate Template** times out, the issue is likely the Elementor JSON generation prompt or JSON processing pipeline. Debug details are written to the WordPress PHP error log, including model, timestamps, image size, prompt length, exact prompt, raw response, and the processing stage reached.

The plugin now also returns debug details in the admin screen when generation fails, including the last reached stage and response sizes. The Elementor JSON prompt is intentionally compact and capped to reduce overly large Gemini responses while debugging.
