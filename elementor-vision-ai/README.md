# Elementor Vision AI (MVP)

Elementor Vision AI converts a website screenshot into an importable Elementor template JSON.

## Simple setup for most users: Gemini
Most WordPress users only need a Google Gemini API key.

1. Install and activate the plugin.
2. Open **Elementor Vision AI → Settings**.
3. Choose **Gemini Vision (Recommended) — Powered by Gemini 2.5 Flash**.
4. Open Google AI Studio: `https://aistudio.google.com/app/apikey`.
5. Sign in, click **Create API Key**, copy the key, and paste it into the plugin.
6. Save settings.
7. Upload a screenshot and click **Generate Template**.
8. Click **Download Elementor Template** to save `elementor-template-{timestamp}.json`, then import it into Elementor.

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
- Powered by Gemini 2.5 Flash
- No server needed
- Recommended for most users

### Ollama
- Free per generation
- Requires your own server
- Best for agencies/advanced users

## Production architecture
Screenshot → Gemini 2.5 Flash → Design Specification JSON → Elementor Builder Layer → Valid Elementor JSON → Rendered Page

- Gemini analyzes layout, spacing, typography, colors, alignment, and visual hierarchy.
- Gemini does **not** generate Elementor internal schema.
- WordPress converts the design specification into Elementor containers and widgets.
- The plugin validates the Elementor template before the download button is shown.

## Elementor import compatibility
The exported JSON follows Elementor template structure:

- `version`
- `title`
- `type`
- `content`
- `page_settings`

Every generated element is validated before export:

- containers use valid `elType`, `settings`, `elements`, and `isInner`
- widgets use valid `elType`, `widgetType`, `settings`, and empty `elements`
- unsupported widget types are rejected before export

If validation fails, the plugin disables the download button and shows an error instead of offering a broken JSON file.
