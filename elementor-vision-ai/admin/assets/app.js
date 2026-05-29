(function () {
  const { useState, useEffect } = wp.element;

  function App() {
    const [file, setFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('Upload a website screenshot to begin.');
    const [result, setResult] = useState(null);
    const [testText, setTestText] = useState('');
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
      const fd = new FormData();
      fd.append('image', file);

      const res = await fetch(`${evaiConfig.restUrl}/${endpoint}`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': evaiConfig.nonce },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) {
        setStatus(data.error || 'Request failed.');
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
        setStatus(data.similarityScore === null || data.similarityScore === undefined ? (data.message || 'Template generated successfully.') : `Similarity score: ${Math.round(data.similarityScore * 100)}%`);
      }
      setLoading(false);
    };

    return wp.element.createElement('div', { className: 'evai-card' }, [
      wp.element.createElement('p', { className: `evai-health ${health.ok ? 'ok' : 'bad'}` }, health.checking ? 'Checking setup...' : (health.ok ? health.message : health.error)),
      wp.element.createElement('input', { type: 'file', accept: 'image/*', onChange: (e) => setFile(e.target.files[0]), className: 'evai-input' }),
      wp.element.createElement('div', { className: 'evai-actions' }, [
        wp.element.createElement('button', { className: 'button button-primary', disabled: !file || loading || !health.ok, onClick: () => postImage('generate', 'Analyzing screenshot and generating Elementor template...') }, loading ? 'Working...' : 'Generate Template'),
        wp.element.createElement('button', { className: 'button', disabled: !file || loading || !health.ok, onClick: () => postImage('gemini-test', 'Running quick Gemini test...') }, 'Test Gemini Only'),
      ]),
      wp.element.createElement('p', { className: 'evai-status' }, status),
      testText ? wp.element.createElement('pre', { className: 'evai-test-output' }, testText) : null,
      result?.previewImage ? wp.element.createElement('img', { src: `data:image/png;base64,${result.previewImage}`, className: 'evai-preview' }) : null,
      result?.elementorJson ? wp.element.createElement('a', { className: 'button', href: URL.createObjectURL(new Blob([JSON.stringify(result.elementorJson, null, 2)], { type: 'application/json' })), download: 'elementor-template.json' }, 'Download JSON') : null,
    ]);
  }

  wp.element.render(wp.element.createElement(App), document.getElementById('evai-root'));
})();
