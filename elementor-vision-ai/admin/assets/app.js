(function () {
  const { useState, useEffect } = wp.element;

  function App() {
    const [file, setFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('Upload a website screenshot to begin.');
    const [result, setResult] = useState(null);
    const [testText, setTestText] = useState('');
    const [showRaw, setShowRaw] = useState(false);
    const [health, setHealth] = useState({ checking: true, ok: false, error: '', message: '' });

    useEffect(() => {
      const check = async () => {
        try {
          const res = await fetch(`${evaiConfig.restUrl}/orchestrator-health`, {
            headers: { 'X-WP-Nonce': evaiConfig.nonce },
          });
          const data = await res.json();
          if (!res.ok) {
            setHealth({ checking: false, ok: false, error: data.error || 'Setup is incomplete.', message: '' });
            return;
          }
          setHealth({ checking: false, ok: true, error: '', message: data.message || 'Ready to generate.' });
        } catch (e) {
          setHealth({ checking: false, ok: false, error: e.message || 'Setup check failed.', message: '' });
        }
      };
      check();
    }, []);

    const postImage = async (endpoint, loadingMessage) => {
      if (!file) return;
      setLoading(true);
      setStatus(loadingMessage);
      setTestText('');
      setShowRaw(false);
      const fd = new FormData();
      fd.append('image', file);

      const res = await fetch(`${evaiConfig.restUrl}/${endpoint}`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': evaiConfig.nonce },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) {
        setStatus(data.message || data.error || 'Request failed.');
        if (data.rawGeminiResponse) {
          setResult(data);
        }
        setTestText(data.debug ? `Debug info:\n${JSON.stringify(data.debug, null, 2)}` : '');
        setLoading(false);
        return;
      }

      if (endpoint === 'gemini-test') {
        setTestText(data.text || 'Gemini returned an empty test response.');
        setStatus(data.message || 'Gemini test completed.');
      } else {
        setResult(data);
        setTestText(data.debug ? `Debug info:\n${JSON.stringify(data.debug, null, 2)}` : '');
        setStatus(data.message || 'Gemini response received. Review the raw response below.');
      }
      setLoading(false);
    };

    const rawResponseBlock = result?.rawGeminiResponse && showRaw
      ? wp.element.createElement('div', { className: 'evai-raw-wrap' }, [
          wp.element.createElement('h3', null, 'Complete Raw Gemini Response'),
          wp.element.createElement('pre', { className: 'evai-raw-output' }, result.rawGeminiResponse),
        ])
      : null;

    return wp.element.createElement('div', { className: 'evai-card' }, [
      wp.element.createElement('p', { className: `evai-health ${health.ok ? 'ok' : 'bad'}` }, health.checking ? 'Checking setup...' : (health.ok ? health.message : health.error)),
      wp.element.createElement('input', { type: 'file', accept: 'image/*', onChange: (e) => setFile(e.target.files[0]), className: 'evai-input' }),
      wp.element.createElement('div', { className: 'evai-actions' }, [
        wp.element.createElement('button', { className: 'button button-primary', disabled: !file || loading || !health.ok, onClick: () => postImage('generate', 'Requesting raw Gemini response...') }, loading ? 'Working...' : 'Generate Template'),
        wp.element.createElement('button', { className: 'button', disabled: !file || loading || !health.ok, onClick: () => postImage('generate-minimal', 'Requesting smallest possible Elementor JSON...') }, 'Generate Minimal JSON'),
        wp.element.createElement('button', { className: 'button', disabled: !file || loading || !health.ok, onClick: () => postImage('gemini-test', 'Running quick Gemini test...') }, 'Test Gemini Only'),
        result?.rawGeminiResponse ? wp.element.createElement('button', { className: 'button', disabled: loading, onClick: () => setShowRaw(!showRaw) }, showRaw ? 'Hide Raw Gemini Response' : 'View Raw Gemini Response') : null,
      ]),
      wp.element.createElement('p', { className: 'evai-status' }, status),
      result?.rawResponseFile?.url ? wp.element.createElement('p', null, wp.element.createElement('a', { href: result.rawResponseFile.url, target: '_blank', rel: 'noopener noreferrer' }, 'Open saved raw response file')) : null,
      result?.extractedJsonText ? wp.element.createElement('div', { className: 'evai-raw-wrap' }, [
        wp.element.createElement('h3', null, 'Extracted JSON Text'),
        wp.element.createElement('pre', { className: 'evai-raw-output' }, result.extractedJsonText),
      ]) : null,
      rawResponseBlock,
      testText ? wp.element.createElement('pre', { className: 'evai-test-output' }, testText) : null,
      result?.previewImage ? wp.element.createElement('img', { src: `data:image/png;base64,${result.previewImage}`, className: 'evai-preview' }) : null,
    ]);
  }

  wp.element.render(wp.element.createElement(App), document.getElementById('evai-root'));
})();
