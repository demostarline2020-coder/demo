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
Screenshot → Visual Block Detection → Visual Structure Analysis → Section Classification → Elementor Widget Reasoning → Design System Extraction → Design Specification JSON → Elementor Builder Layer → Valid Elementor JSON

On initialization the plugin detects Elementor capabilities:

- Elementor Free widgets such as Heading, Text Editor, Image, Icon Box, Button, HTML, Container, Spacer, Divider, and Social Icons.
- Elementor Pro widgets such as Form, Nav Menu, Loop Grid, Slides, Popup, Price Table, Posts, and Theme widgets when Elementor Pro is installed.

Gemini does **not** generate Elementor internal schema. The planner starts with open-ended visual observation instead of predefined marketing-page sections:

1. Detect visual blocks by screenshot coordinates, boundaries, whitespace, backgrounds, and content groupings.
2. Analyze each block structure: containers, rows, columns, nested groupings, alignment, spacing relationships, image areas, icon areas, and CTA areas.
3. Classify a block only after visual evidence is captured. Unknown layouts stay generic instead of being forced into a hero/services/footer pattern.
4. Reason like an Elementor expert about the best implementation for each observed element, including overlays, repeated cards, menus, forms, image/video areas, and free/pro fallbacks.
5. Extract the design system from visible evidence: typography, spacing, colors, borders, shadows, radii, and overlays.
6. Synthesize a design specification with confidence scores.
7. WordPress converts the design specification into Elementor containers and widgets.

The AI planner may only choose widgets that exist in the current Elementor installation. If a Pro widget is unavailable, the planner uses a free fallback such as HTML placeholders or container/card structures. The plugin returns confidence scores for layout, spacing, typography, and colors, then validates the Elementor template before the download button is shown.

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
