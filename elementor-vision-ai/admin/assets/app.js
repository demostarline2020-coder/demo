(function () {
  const { useState, useEffect } = wp.element;

  function App() {
    const [file, setFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('Upload a website screenshot to begin.');
    const [result, setResult] = useState(null);
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

    const generateTemplate = async () => {
      if (!file) return;
      setLoading(true);
      setResult(null);
      setStatus('Analyzing screenshot with Gemini 2.5 Flash...');

      const fd = new FormData();
      fd.append('image', file);

      try {
        const res = await fetch(`${evaiConfig.restUrl}/generate`, {
          method: 'POST',
          headers: { 'X-WP-Nonce': evaiConfig.nonce },
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) {
          setStatus(data.error || 'Template generation failed. Please try again.');
          return;
        }
        setResult(data);
        setStatus(data.message || 'Valid Elementor template generated.');
      } catch (e) {
        setStatus(e.message || 'Template generation failed. Please try again.');
      } finally {
        setLoading(false);
      }
    };

    return wp.element.createElement('div', { className: 'evai-card' }, [
      wp.element.createElement('p', { className: `evai-health ${health.ok ? 'ok' : 'bad'}` }, health.checking ? 'Checking setup...' : (health.ok ? health.message : health.error)),
      wp.element.createElement('p', { className: 'evai-powered' }, 'Powered by Gemini 2.5 Flash'),
      wp.element.createElement('input', { type: 'file', accept: 'image/*', onChange: (e) => setFile(e.target.files[0]), className: 'evai-input' }),
      wp.element.createElement('div', { className: 'evai-actions' }, [
        wp.element.createElement('button', { className: 'button button-primary', disabled: !file || loading || !health.ok, onClick: generateTemplate }, loading ? 'Working...' : 'Generate Template'),
      ]),
      wp.element.createElement('p', { className: 'evai-status' }, status),
      result?.elementorJson ? wp.element.createElement('a', { className: 'button button-primary', href: URL.createObjectURL(new Blob([JSON.stringify(result.elementorJson, null, 2)], { type: 'application/json' })), download: 'elementor-template.json' }, 'Download Elementor JSON') : null,
      result?.previewImage ? wp.element.createElement('img', { src: `data:image/png;base64,${result.previewImage}`, className: 'evai-preview' }) : null,
    ]);
  }

  wp.element.render(wp.element.createElement(App), document.getElementById('evai-root'));
})();
