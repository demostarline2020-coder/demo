# Elementor Vision AI (MVP)

## What it does
Upload a screenshot in WP Admin, generate an Elementor JSON template via Gemini Vision analysis, run iterative visual reconstruction (Playwright + pixelmatch), preview result, and download importable JSON.

## Install
1. Copy plugin folder into `wp-content/plugins/elementor-vision-ai`.
2. Start orchestrator:
   ```bash
   cd services/orchestrator
   npm install
   npm start
   ```
3. Activate plugin in WordPress.
4. Go to **Elementor Vision AI > Settings** and set Gemini API key.
5. Go to **Elementor Vision AI** and generate template.

## Architecture
- WP Plugin (PHP): UI + REST proxy + secure settings storage.
- React Admin UI: upload, generation trigger, status, preview, JSON download.
- Node Orchestrator: Gemini vision analysis, preset matching, Elementor JSON assembly, iterative visual diff loop, schema-friendly output.

## MVP limits
- Currently supports core structure widgets and container-based layouts.
- Similarity scoring is pixel-based and refined in a 3-pass loop.
