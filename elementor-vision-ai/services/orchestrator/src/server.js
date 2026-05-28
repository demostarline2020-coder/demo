import express from 'express';
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const app = express();
app.use(express.json({ limit: '20mb' }));

const PRESETS = {
  'dark-enterprise-hero': { widget: 'heading', style: { color: '#fff', typography_font_size: { unit: 'px', size: 56 } } },
  'premium-services-grid': { columns: 3, gap: 24 },
  'stats-inline-dark': { columns: 4, textColor: '#fff' },
  'premium-cta-banner': { background: '#111827', radius: 20 },
  'modern-footer': { background: '#0f172a', padding: { top: 56, right: 40, bottom: 56, left: 40 } }
};

const geminiCall = async (apiKey, imageBase64, mimeType) => {
  const body = {
    contents: [{ parts: [{ text: 'Analyze this webpage screenshot and return strict JSON with sections, hierarchy, typography, spacing, colors, widgets, responsive hints.' }, { inlineData: { data: imageBase64, mimeType } }] }],
    generationConfig: { responseMimeType: 'application/json' }
  };
  const resp = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${apiKey}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!resp.ok) throw new Error(`Gemini failed: ${resp.status}`);
  const json = await resp.json();
  return JSON.parse(json.candidates?.[0]?.content?.parts?.[0]?.text || '{}');
};


const ollamaCall = async (imageBase64, mimeType) => {
  const ollamaUrl = process.env.OLLAMA_URL || 'http://127.0.0.1:11434';
  const resp = await fetch(`${ollamaUrl}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: process.env.OLLAMA_MODEL || 'llava:7b',
      stream: false,
      messages: [{
        role: 'user',
        content: 'Analyze this webpage screenshot and return strict JSON with sections, hierarchy, typography, spacing, colors, widgets, responsive hints.',
        images: [imageBase64]
      }],
      format: 'json'
    })
  });
  if (!resp.ok) throw new Error(`Ollama failed: ${resp.status}`);
  const json = await resp.json();
  return JSON.parse(json.message?.content || '{}');
};

const buildElementorJson = (analysis) => ({
  version: '0.4',
  title: 'Elementor Vision AI Template',
  type: 'page',
  content: (analysis.sections || []).map((section, idx) => ({
    id: `evai_${idx}_${Date.now().toString(36)}`,
    elType: 'container',
    isInner: false,
    settings: { content_width: 'full', flex_direction: section.layout?.direction || 'column', padding: section.spacing?.padding || { unit: 'px', top: 48, right: 32, bottom: 48, left: 32 } },
    elements: (section.widgets || []).map((w, wid) => ({
      id: `evai_w_${idx}_${wid}`,
      elType: 'widget',
      widgetType: w.type || 'text-editor',
      settings: w.settings || { editor: w.text || '' },
      elements: []
    }))
  }))
});

const renderTemplateHtml = (analysis) => `<html><body style="margin:0;font-family:Inter,Arial;background:${analysis.page?.background || '#fff'}">${(analysis.sections||[]).map(s=>`<section style="padding:48px 32px"><h2>${(s.title||'')}</h2></section>`).join('')}</body></html>`;

const screenshotHtml = async (html) => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 2400 } });
  await page.setContent(html, { waitUntil: 'networkidle' });
  const buf = await page.screenshot({ fullPage: true, type: 'png' });
  await browser.close();
  return buf;
};

const compare = (imgA, imgB) => {
  const a = PNG.sync.read(imgA);
  const b = PNG.sync.read(imgB);
  const width = Math.min(a.width, b.width);
  const height = Math.min(a.height, b.height);
  const aa = new PNG({ width, height });
  const bb = new PNG({ width, height });
  PNG.bitblt(a, aa, 0, 0, width, height, 0, 0);
  PNG.bitblt(b, bb, 0, 0, width, height, 0, 0);
  const diff = new PNG({ width, height });
  const mismatched = pixelmatch(aa.data, bb.data, diff.data, width, height, { threshold: 0.12 });
  return 1 - mismatched / (width * height);
};

app.get('/health', (req, res) => {
  res.json({ ok: true, service: 'evai-orchestrator' });
});

app.post('/generate-template', async (req, res) => {
  try {
    const { imageBase64, mimeType, geminiApiKey, aiBackend } = req.body;
    if (!imageBase64) return res.status(400).json({ error: 'Missing image' });

    let analysis;
    if (aiBackend === 'ollama') {
      analysis = await ollamaCall(imageBase64, mimeType || 'image/png');
    } else {
      if (!geminiApiKey) return res.status(400).json({ error: 'Missing Gemini API key' });
      analysis = await geminiCall(geminiApiKey, imageBase64, mimeType || 'image/png');
    }
    analysis.presets = Object.keys(PRESETS).filter(p => JSON.stringify(analysis).toLowerCase().includes(p.split('-')[0]));

    let best = { score: 0, json: null, preview: null };
    for (let i = 0; i < 3; i++) {
      const json = buildElementorJson(analysis);
      const preview = await screenshotHtml(renderTemplateHtml(analysis));
      const src = Buffer.from(imageBase64, 'base64');
      const score = compare(src, preview);
      if (score > best.score) best = { score, json, preview };
      analysis = { ...analysis, sections: (analysis.sections || []).map(s => ({ ...s, spacing: { padding: { unit: 'px', top: 48 - i * 4, right: 32 - i * 2, bottom: 48 - i * 4, left: 32 - i * 2 } } })) };
    }

    res.json({ similarityScore: best.score, elementorJson: best.json, previewImage: best.preview.toString('base64') });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

app.listen(4100, () => console.log('EVAI orchestrator listening on 4100'));
