# Elementor Vision AI (MVP)

## Who this is for
This plugin is designed for agencies and non-technical site owners.
- Site owners use WordPress settings + upload screenshots.
- Agency/tech team sets up one Managed Worker once.

---

## What is the Managed Worker URL?
**Managed Worker URL** is the web address of your AI processing server (Node orchestrator).

Example:
- `https://worker.youragency.com`

The plugin sends screenshots to this URL. That server must provide:
- `GET /health`
- `POST /generate-template`

So if your worker URL is `https://worker.youragency.com`, plugin calls:
- `https://worker.youragency.com/health`
- `https://worker.youragency.com/generate-template`

---

## Simple setup for non-technical users (client websites)
1. Install and activate plugin.
2. Open **Elementor Vision AI → Settings**.
3. Paste **Managed Worker URL** provided by your agency.
4. Choose **AI Backend**:
   - Gemini Vision
   - Ollama LLaVA
5. If Gemini is selected, paste Gemini API key.
6. Save settings.
7. Go to plugin main page, upload screenshot, click **Generate Template**.

No Node.js installation needed on client hosting.

---

## How agency/technical team gets Managed Worker URL
You have 2 common choices:

### Option A: Deploy on your own server (VPS/cloud VM)
1. Clone/copy `services/orchestrator` to your server.
2. Install Node.js 20+.
3. Run:
   ```bash
   npm install
   npm start
   ```
4. Put Nginx/Apache reverse proxy in front with HTTPS and domain/subdomain.
5. Share public URL with clients (example `https://worker.youragency.com`).

### Option B: Deploy to a platform (Render/Railway/Fly.io)
1. Create new service from `services/orchestrator`.
2. Set start command: `npm start`.
3. Deploy.
4. Copy generated public HTTPS URL and use as Managed Worker URL.

---

## If using **Ollama LLaVA** (free AI model usage)
Ollama has no per-request API cost, but you host compute yourself.

### On Managed Worker server
1. Install Ollama.
2. Pull model:
   ```bash
   ollama pull llava:7b
   ```
3. Start Ollama service (default: `http://127.0.0.1:11434`).
4. Start orchestrator with environment variables (optional):
   - `OLLAMA_URL` (default `http://127.0.0.1:11434`)
   - `OLLAMA_MODEL` (default `llava:7b`)

### In WordPress plugin settings
- Set **AI Backend** = `Ollama LLaVA (free/local)`.
- Gemini key is not required for Ollama mode.

---

## If using **Gemini Vision**
You need a Google AI Studio API key.

### How to get key (non-technical friendly)
1. Go to Google AI Studio: `https://aistudio.google.com`
2. Sign in with Google account.
3. Open **Get API key** / **API Keys** section.
4. Click **Create API key**.
5. Copy the key.

### In WordPress plugin settings
- Set **AI Backend** = `Gemini Vision (API key)`.
- Paste key into **Gemini API Key** field.
- Save settings.

---

## Troubleshooting for non-technical users
### Error: “Cannot reach Elementor Vision AI Orchestrator”
This means your Managed Worker URL is wrong or worker is down.

Do this:
1. Re-check URL in settings (no typo, must start with `https://`).
2. Contact agency/technical team and ask them to confirm worker is running.
3. Ask them to verify health endpoint opens in browser:
   - `https://your-worker-url/health`

### If your site is in Docker/Kubernetes
Do not use `127.0.0.1` unless worker is in same container. Use service/domain URL.

---

## Quick settings decision guide
- Want easiest cloud setup with API key? → **Gemini Vision**
- Want no per-request AI API cost and self-hosted stack? → **Ollama LLaVA**

---

## Architecture
- WP Plugin (PHP): UI + REST proxy + secure settings storage.
- Admin UI: upload, generation trigger, status, preview, JSON download.
- Managed Worker (Node): AI analysis, preset matching, Elementor JSON assembly, visual diff loop.
