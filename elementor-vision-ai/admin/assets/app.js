(function () {
  const { useState } = wp.element;

  function App() {
    const [file, setFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('Upload a website screenshot to begin.');
    const [result, setResult] = useState(null);

    const onGenerate = async () => {
      if (!file) return;
      setLoading(true);
      setStatus('Analyzing screenshot and generating Elementor template...');
      const fd = new FormData();
      fd.append('image', file);

      const res = await fetch(`${evaiConfig.restUrl}/generate`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': evaiConfig.nonce },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) {
        setStatus(data.error || 'Generation failed.');
        setLoading(false);
        return;
      }
      setResult(data);
      setStatus(`Similarity score: ${Math.round((data.similarityScore || 0) * 100)}%`);
      setLoading(false);
    };

    return wp.element.createElement('div', { className: 'evai-card' }, [
      wp.element.createElement('input', { type: 'file', accept: 'image/*', onChange: (e) => setFile(e.target.files[0]), className: 'evai-input' }),
      wp.element.createElement('button', { className: 'button button-primary', disabled: !file || loading, onClick: onGenerate }, loading ? 'Generating...' : 'Generate Template'),
      wp.element.createElement('p', { className: 'evai-status' }, status),
      result?.previewImage ? wp.element.createElement('img', { src: `data:image/png;base64,${result.previewImage}`, className: 'evai-preview' }) : null,
      result?.elementorJson ? wp.element.createElement('a', { className: 'button', href: URL.createObjectURL(new Blob([JSON.stringify(result.elementorJson, null, 2)], { type: 'application/json' })), download: 'elementor-template.json' }, 'Download JSON') : null,
    ]);
  }

  wp.element.render(wp.element.createElement(App), document.getElementById('evai-root'));
})();
