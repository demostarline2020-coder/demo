# Elementor Vision AI (MVP)

## Managed Worker model (recommended)
For agencies and non-technical clients, deploy one managed worker (Node orchestrator) once, then point all client sites to that URL from plugin settings.

Clients only do:
1. Install plugin
2. Add Worker URL
3. Choose AI backend
4. Upload screenshot and generate

No Node.js required on client hosting.

## AI backend options
- **Gemini Vision**: cloud API key based.
- **Ollama LLaVA (free/local)**: no per-request AI cost; run Ollama on managed worker infrastructure.

## Install
1. Copy plugin folder into `wp-content/plugins/elementor-vision-ai`.
2. Deploy/start your managed worker:
   ```bash
   cd services/orchestrator
   npm install
   npm start
   ```
3. Activate plugin in WordPress.
4. Go to **Elementor Vision AI > Settings**:
   - set **Managed Worker URL**
   - select **AI Backend** (Gemini or Ollama)
   - if Gemini selected, add Gemini API key
5. Generate template from screenshot.

## Worker health check
Your worker must expose:
- `GET /health`
- `POST /generate-template`

## Docker note
If WordPress runs in Docker/K8s, do not use `127.0.0.1` unless worker is in same container. Use service hostname instead.
