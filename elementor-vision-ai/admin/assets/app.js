(function () {
  const { useState, useEffect } = wp.element;

  const timestamp = () => new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z').replace('T', '-').replace('Z', '');

  const validateElement = (element) => {
    if (!element || typeof element !== 'object' || Array.isArray(element)) return 'Elementor element must be an object.';
    if (typeof element.id !== 'string' || !/^[a-z0-9]{7,32}$/.test(element.id)) return 'Elementor element has an invalid ID.';
    if (!['container', 'widget'].includes(element.elType)) return 'Elementor element has an invalid type.';
    if (!element.settings || typeof element.settings !== 'object' || Array.isArray(element.settings)) return 'Elementor element settings are invalid.';
    if (!Array.isArray(element.elements)) return 'Elementor child elements are invalid.';

    if (element.elType === 'container' && typeof element.isInner !== 'boolean') return 'Elementor container is missing its inner-container flag.';
    if (element.elType === 'widget') {
      if (typeof element.widgetType !== 'string' || element.widgetType === '') return 'Elementor widget type is missing.';
      if (element.elements.length > 0) return 'Elementor widgets cannot contain child elements.';
    }

    for (const child of element.elements) {
      const childError = validateElement(child);
      if (childError) return childError;
    }
    return '';
  };

  const validateTemplate = (template) => {
    if (!template || typeof template !== 'object' || Array.isArray(template)) return 'Generated template is not a valid JSON object.';
    for (const key of ['version', 'title', 'type', 'content', 'page_settings']) {
      if (!(key in template)) return `Generated template is missing required key: ${key}.`;
    }
    if (template.type !== 'page') return 'Generated template type must be page.';
    if (!Array.isArray(template.content) || template.content.length === 0) return 'Generated template has no Elementor content.';
    if (!Array.isArray(template.page_settings)) return 'Generated template page settings are invalid.';

    for (const element of template.content) {
      const error = validateElement(element);
      if (error) return error;
    }
    return '';
  };

  const confidenceBlock = (scores) => {
    if (!scores) return null;
    const labels = ['layout', 'spacing', 'typography', 'colors'];
    return wp.element.createElement('div', { className: 'evai-confidence' }, [
      wp.element.createElement('strong', null, 'Design confidence'),
      ...labels.map((label) => wp.element.createElement('span', { className: 'evai-confidence-item' }, `${label}: ${Math.round((Number(scores[label]) || 0) * 100)}%`)),
    ]);
  };

  function App() {
    const [file, setFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('Upload a website screenshot to begin.');
    const [result, setResult] = useState(null);
    const [validationError, setValidationError] = useState('');
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
          setHealth({ checking: false, ok: true, error: '', message: data.message || 'Ready to generate.', capabilities: data.capabilities || null });
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
      setValidationError('');
      setStatus('Running multi-pass visual analysis with Gemini 2.5 Flash...');

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

        const error = validateTemplate(data.elementorJson);
        setResult(data);
        setValidationError(error);
        setStatus(error ? `Generated JSON failed validation: ${error}` : (data.message || 'Valid Elementor template generated.'));
      } catch (e) {
        setStatus(e.message || 'Template generation failed. Please try again.');
      } finally {
        setLoading(false);
      }
    };

    const downloadTemplate = () => {
      const error = validateTemplate(result?.elementorJson);
      setValidationError(error);
      if (error) return;

      const blob = new Blob([JSON.stringify(result.elementorJson, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `elementor-template-${timestamp()}.json`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    };

    const canDownload = Boolean(result?.elementorJson) && validationError === '';

    return wp.element.createElement('div', { className: 'evai-card' }, [
      wp.element.createElement('p', { className: `evai-health ${health.ok ? 'ok' : 'bad'}` }, health.checking ? 'Checking setup...' : (health.ok ? health.message : health.error)),
      wp.element.createElement('p', { className: 'evai-powered' }, health.capabilities?.elementor_pro_active ? 'Elementor Pro detected · Powered by Gemini 2.5 Flash' : 'Elementor Free widgets · Powered by Gemini 2.5 Flash'),
      wp.element.createElement('input', { type: 'file', accept: 'image/*', onChange: (e) => setFile(e.target.files[0]), className: 'evai-input' }),
      wp.element.createElement('div', { className: 'evai-actions' }, [
        wp.element.createElement('button', { className: 'button button-primary', disabled: !file || loading || !health.ok, onClick: generateTemplate }, loading ? 'Working...' : 'Generate Template'),
        result?.elementorJson ? wp.element.createElement('button', { className: 'button button-primary', disabled: !canDownload, onClick: downloadTemplate }, 'Download Elementor Template') : null,
      ]),
      wp.element.createElement('p', { className: validationError ? 'evai-status evai-error' : 'evai-status' }, validationError ? `Download disabled: ${validationError}` : status),
      result?.confidenceScores ? confidenceBlock(result.confidenceScores) : null,
      result?.previewImage ? wp.element.createElement('img', { src: `data:image/png;base64,${result.previewImage}`, className: 'evai-preview' }) : null,
    ]);
  }

  wp.element.render(wp.element.createElement(App), document.getElementById('evai-root'));
})();
