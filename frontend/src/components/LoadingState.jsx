export default function LoadingState() {
  return (
    <div className="mt-6 rounded-2xl border border-white/20 p-6">
      <div className="mx-auto h-8 w-8 animate-spin rounded-full border-2 border-white/20 border-t-white" />
      <p className="mt-3 text-center text-white/70">AI is processing your image...</p>
    </div>
  );
}
