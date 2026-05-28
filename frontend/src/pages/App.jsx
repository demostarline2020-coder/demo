import { useMemo, useState } from 'react';
import UploadArea from '../components/UploadArea';
import LoadingState from '../components/LoadingState';
import { vectorizeImage } from '../services/api';
import { validateFile } from '../utils/fileValidation';

export default function App() {
  const [file, setFile] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [svg, setSvg] = useState('');

  const previewUrl = useMemo(() => (file ? URL.createObjectURL(file) : ''), [file]);

  const handleFile = (candidate) => {
    setSvg('');
    const msg = validateFile(candidate);
    if (msg) {
      setError(msg);
      setFile(null);
      return;
    }
    setError('');
    setFile(candidate);
  };

  const process = async () => {
    if (!file) return setError('Upload an image first.');
    setLoading(true);
    setError('');
    try {
      const res = await vectorizeImage(file);
      setSvg(res.svg);
    } catch (e) {
      setError(e?.response?.data?.error || 'Processing failed. Try another image.');
    } finally {
      setLoading(false);
    }
  };

  const downloadSvg = () => {
    const blob = new Blob([svg], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'vectorized.svg';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <main className="min-h-screen bg-black px-4 py-14 text-white">
      <div className="mx-auto max-w-4xl space-y-6">
        <header className="text-center">
          <h1 className="text-4xl font-semibold tracking-tight">AI SVG Vectorizer</h1>
          <p className="mt-3 text-white/60">Upload an image and instantly get clean downloadable SVG artwork.</p>
        </header>

        <UploadArea onFile={handleFile} />

        {error && <p className="rounded-xl border border-red-500/50 bg-red-500/10 p-3 text-sm text-red-300">{error}</p>}

        {previewUrl && (
          <section className="rounded-2xl border border-white/20 bg-white/5 p-4">
            <h2 className="mb-2 text-sm text-white/70">Image preview</h2>
            <img src={previewUrl} alt="Upload preview" className="max-h-72 rounded-xl object-contain" />
          </section>
        )}

        <button
          onClick={process}
          disabled={!file || loading}
          className="w-full rounded-xl bg-white px-4 py-3 font-semibold text-black transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {loading ? 'Processing...' : 'Process'}
        </button>

        {loading && <LoadingState />}

        {svg && (
          <section className="rounded-2xl border border-white/20 bg-white/5 p-4">
            <h2 className="mb-3 text-sm text-white/70">SVG result</h2>
            <div className="rounded-xl bg-white p-4" dangerouslySetInnerHTML={{ __html: svg }} />
            <button onClick={downloadSvg} className="mt-4 rounded-xl border border-white/30 px-4 py-2 hover:bg-white/10">
              Download SVG
            </button>
          </section>
        )}
      </div>
    </main>
  );
}
