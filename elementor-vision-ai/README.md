# Elementor Vision AI (MVP)

## Simple setup for most users: Gemini
Most WordPress users only need a Google Gemini API key.

1. Install and activate the plugin.
2. Open **Elementor Vision AI → Settings**.
3. Choose **Gemini Vision (Recommended) — Powered by Gemini 2.5 Flash**.
4. The plugin is **Powered by Gemini 2.5 Flash** automatically.
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
- Powered by Gemini 2.5 Flash
- No server needed
- Recommended for most users

### Ollama
- Free per generation
- Requires your own server
- Best for agencies/advanced users

## Architecture
Screenshot → Gemini 2.5 Flash → Design Specification JSON → Elementor Builder Layer → Valid Elementor JSON → Rendered Page

- Gemini mode: WordPress calls Gemini 2.5 Flash directly and generates Elementor JSON inside the plugin.
- Ollama mode: WordPress calls your AI server because Ollama must run on your own infrastructure.
- Admin UI: upload screenshot, generate template, preview image, download JSON.

## Temporary Gemini debug test
If template generation times out, use **Test Gemini Only** on the upload screen.

This sends the uploaded screenshot to Gemini with only this prompt:

```text
Describe this screenshot in 5 bullet points.
```

If the test is fast but **Generate Template** times out, the issue is likely the Elementor JSON generation prompt or JSON processing pipeline. Debug details are written to the WordPress PHP error log, including `model = gemini-2.5-flash`, timestamps, image size, prompt length, exact prompt, raw response, and the processing stage reached.

The plugin now also returns debug details in the admin screen when generation fails, including the last reached stage and response sizes. The Elementor JSON prompt is intentionally compact and capped to reduce overly large Gemini responses while debugging.

## Raw Gemini response inspection mode
**Generate Template** shows inspection details when Gemini responds, then the plugin extracts the design specification and builds Elementor JSON through the PHP builder layer. It shows:

- complete raw Gemini response
- extracted JSON text with markdown fences removed when present
- saved raw response file link

Use **View Raw Gemini Response** to inspect exactly what Gemini returned before any plugin processing.

## MAX_TOKENS truncation handling
If Gemini returns `finishReason = MAX_TOKENS`, the response was cut off before the Elementor JSON finished.

The plugin now:
- increases template generation output allowance to 32,768 tokens
- logs `finishReason`, output tokens, and total tokens
- shows the explicit message: `Gemini response was truncated.`
- keeps raw response inspection mode active
- provides **Generate Minimal JSON** to request the smallest possible design specification first

Use **Generate Minimal JSON** to check whether Gemini can return a complete compact design specification without truncation before expanding the prompt again.

## Strict Elementor builder mode
Gemini no longer generates Elementor internals directly.

Current flow:
1. Gemini returns a simple layout description only (`sections`, headings, text, buttons, items).
2. WordPress converts that description into Elementor JSON using strict predefined containers and widgets.
3. The generated elements always include required keys such as `_column_size`, `elementType`, `widgetType`, `settings`, and `elements`.
4. The template is validated before the JSON download is shown.

This prevents Gemini from inventing invalid Elementor settings and fixes invalid container/column configuration warnings.

## Design specification builder mode
Design quality now comes from a two-step pipeline:

1. Gemini analyzes the screenshot and returns a design specification, not Elementor internals.
   - layout type
   - spacing and section padding
   - typography sizes and weights
   - colors and backgrounds
   - button style
   - column widths
   - visual hierarchy
2. WordPress converts that design specification into Elementor JSON with reusable section generators:
   - Hero
   - Stats
   - Features
   - Services
   - CTA
   - Footer

Gemini is never allowed to invent Elementor internal settings. The PHP builder owns all Elementor keys and validates the final structure before offering the JSON download.
