(function () {
  const form = document.getElementById('evai-settings-form');
  if (!form) return;

  const backend = document.getElementById('evai-ai-backend');
  const geminiGroup = document.getElementById('evai-gemini-group');
  const ollamaGroup = document.getElementById('evai-ollama-group');
  const geminiInput = document.getElementById('evai-gemini-key');
  const workerInput = document.getElementById('evai-worker-url');

  const update = () => {
    const isGemini = backend.value === 'gemini';

    geminiGroup.style.display = isGemini ? '' : 'none';
    ollamaGroup.style.display = isGemini ? 'none' : '';

    geminiInput.required = isGemini;
    workerInput.required = !isGemini;
  };

  backend.addEventListener('change', update);
  update();
})();
