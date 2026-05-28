import { useRef } from 'react';

export default function UploadArea({ onFile }) {
  const inputRef = useRef(null);

  const handleDrop = (e) => {
    e.preventDefault();
    onFile(e.dataTransfer.files?.[0]);
  };

  return (
    <div
      onDrop={handleDrop}
      onDragOver={(e) => e.preventDefault()}
      onClick={() => inputRef.current?.click()}
      className="group cursor-pointer rounded-2xl border border-white/20 bg-white/5 p-10 text-center transition hover:bg-white/10 hover:shadow-glow"
    >
      <input
        ref={inputRef}
        type="file"
        accept="image/png,image/jpeg,image/jpg,image/webp"
        className="hidden"
        onChange={(e) => onFile(e.target.files?.[0])}
      />
      <p className="text-lg font-medium">Drag & drop image here</p>
      <p className="mt-2 text-sm text-white/60">or click to browse (PNG/JPG/WebP)</p>
    </div>
  );
}
