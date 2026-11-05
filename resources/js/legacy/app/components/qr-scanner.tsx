
import { useEffect, useRef, useState } from 'react';

// Reads a QR code with the device camera and returns its text.
//
// Uses the browser's BarcodeDetector where it exists (Chrome on Android) and
// falls back to jsQR on a canvas everywhere else (Safari on iPhone, desktops).
// The camera needs HTTPS (or localhost) and the user's permission.

type Props = {
  onResult: (text: string) => void;
  onClose: () => void;
};

type Detector = { detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]> };

function cameraError(err: unknown): string {
  const name = (err as { name?: string })?.name ?? '';
  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return 'A câmara foi bloqueada. Autorize o acesso à câmara nas definições do browser e tente de novo.';
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'Não foi encontrada nenhuma câmara neste aparelho.';
  if (name === 'NotReadableError') return 'A câmara está a ser usada por outra aplicação. Feche-a e tente de novo.';
  return 'Não foi possível abrir a câmara. Introduza o código à mão.';
}

export function QrScanner({ onResult, onClose }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(true);

  useEffect(() => {
    let stream: MediaStream | null = null;
    let frame = 0;
    let stopped = false;
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    function stop() {
      stopped = true;
      cancelAnimationFrame(frame);
      stream?.getTracks().forEach((t) => t.stop());
    }

    async function start() {
      if (!navigator.mediaDevices?.getUserMedia) {
        setError('Este browser não permite usar a câmara. Introduza o código à mão.');
        setStarting(false);
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: false,
        });
      } catch (err) {
        setError(cameraError(err));
        setStarting(false);
        return;
      }
      if (stopped) { stream.getTracks().forEach((t) => t.stop()); return; }
      const video = videoRef.current!;
      video.srcObject = stream;
      await video.play().catch(() => undefined);
      setStarting(false);

      const NativeDetector = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
      const native = NativeDetector ? new NativeDetector({ formats: ['qr_code'] }) : null;
      const jsQR = native ? null : (await import('jsqr')).default;

      let busy = false;
      const tick = async () => {
        if (stopped) return;
        if (!busy && video.readyState >= 2 && video.videoWidth > 0) {
          busy = true;
          try {
            let text: string | null = null;
            if (native) {
              const codes = await native.detect(video);
              text = codes[0]?.rawValue ?? null;
            } else if (jsQR && context) {
              // Decode a downscaled frame: faster, and enough for a card held in front of the camera.
              const scale = Math.min(1, 640 / video.videoWidth);
              canvas.width = Math.round(video.videoWidth * scale);
              canvas.height = Math.round(video.videoHeight * scale);
              context.drawImage(video, 0, 0, canvas.width, canvas.height);
              const image = context.getImageData(0, 0, canvas.width, canvas.height);
              text = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
            }
            if (text) {
              stop();
              if (navigator.vibrate) navigator.vibrate(80);
              onResult(text);
              return;
            }
          } catch {
            // A frame that cannot be read is skipped.
          } finally {
            busy = false;
          }
        }
        frame = requestAnimationFrame(tick);
      };
      frame = requestAnimationFrame(tick);
    }

    start();
    return stop;
  }, [onResult]);

  return (
    <div className="qr-overlay" role="dialog" aria-modal="true" aria-label="Ler QR code do cartão">
      <div className="qr-panel">
        <div className="qr-head">
          <strong>Ler QR code do cartão</strong>
          <button className="quick-link" onClick={onClose}>Fechar</button>
        </div>
        {error ? (
          <div className="error-box">{error}</div>
        ) : (
          <div className="qr-viewport">
            <video ref={videoRef} playsInline muted aria-label="Imagem da câmara" />
            <div className="qr-frame" aria-hidden="true" />
            {starting && <div className="qr-hint">A abrir a câmara…</div>}
          </div>
        )}
        <p className="muted-text small-note">Aponte a câmara para o QR code do cartão. A leitura é automática.</p>
      </div>
    </div>
  );
}
