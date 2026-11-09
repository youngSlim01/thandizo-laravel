// Cartões de voucher para imprimir: folhas A4 ao alto com 10 cartões de
// 85 × 54 mm (tamanho de cartão bancário) e linhas de corte. Corre no browser.

export type VoucherCard = {
  voucher_code: string;
  household_code: string;
  representative_name: string;
  community: string | null;
  locality: string | null;
  district: string | null;
  province: string | null;
};

const CARD_W = 85;
const CARD_H = 54;
const COLS = 2;
const ROWS = 5;
const GAP_X = 6;
const GAP_Y = 3;
const MARGIN_X = (210 - COLS * CARD_W - (COLS - 1) * GAP_X) / 2;
const MARGIN_Y = (297 - ROWS * CARD_H - (ROWS - 1) * GAP_Y) / 2;
const BRAND: [number, number, number] = [0, 91, 55];
const PALE: [number, number, number] = [225, 245, 229];
const ACCENT: [number, number, number] = [112, 190, 65];

type Logo = { data: string; ratio: number };

// Desenha a imagem num canvas e devolve-a em PNG, seja qual for o formato
// de origem, para o jsPDF receber sempre PNG. Um logótipo que não carrega é
// ignorado e o cartão sai sem ele.
async function loadLogo(src: string): Promise<Logo | null> {
  try {
    const response = await fetch(src, { credentials: 'same-origin' });
    if (!response.ok || !(response.headers.get('content-type') ?? '').startsWith('image/')) return null;
    const url = URL.createObjectURL(await response.blob());
    try {
      const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = reject;
        el.src = url;
      });
      // Pixéis suficientes para 8 mm de altura na impressão.
      const scale = Math.min(1, 400 / Math.max(img.naturalHeight, 1));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
      canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
      return { data: canvas.toDataURL('image/png'), ratio: canvas.width / canvas.height };
    } finally {
      URL.revokeObjectURL(url);
    }
  } catch {
    return null;
  }
}

// Símbolo oficial do Thandizo (public/thandizo-icon.svg), quadrado, em PNG de
// 400 px: o SVG não tem tamanho próprio, por isso desenha-se com tamanho fixo.
async function loadMark(): Promise<Logo | null> {
  try {
    const svg = await (await fetch('/thandizo-icon.svg')).text();
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    try {
      const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image(400, 400);
        el.onload = () => resolve(el);
        el.onerror = reject;
        el.src = url;
      });
      const canvas = document.createElement('canvas');
      canvas.width = 400;
      canvas.height = 400;
      canvas.getContext('2d')!.drawImage(img, 0, 0, 400, 400);
      return { data: canvas.toDataURL('image/png'), ratio: 1 };
    } finally {
      URL.revokeObjectURL(url);
    }
  } catch {
    return null;
  }
}

type Doc = InstanceType<typeof import('jspdf').jsPDF>;
type Rgb = [number, number, number];

const INK: Rgb = [28, 33, 30];
const GREY: Rgb = [96, 104, 99];
const QR_BORDER: Rgb = [160, 222, 176];
const WAVE: Rgb = [230, 243, 233];

// Ícones desenhados em vector (traço verde), à volta do ponto (cx, cy).
function personIcon(doc: Doc, cx: number, cy: number) {
  doc.setDrawColor(...BRAND).setLineWidth(0.38);
  doc.circle(cx, cy - 1.1, 1.05, 'S');
  doc.lines([[0, -1.9, 3.6, -1.9, 3.6, 0]], cx - 1.8, cy + 1.9, [1, 1], 'S', false);
  doc.line(cx - 1.8, cy + 1.9, cx + 1.8, cy + 1.9);
}

function cardIcon(doc: Doc, cx: number, cy: number) {
  doc.setDrawColor(...BRAND).setLineWidth(0.38);
  doc.roundedRect(cx - 2.3, cy - 1.5, 4.6, 3, 0.5, 0.5, 'S');
  doc.line(cx - 2.3, cy - 0.5, cx + 2.3, cy - 0.5);
  doc.setFillColor(...BRAND).circle(cx + 1.3, cy + 0.65, 0.22, 'F');
}

function pinIcon(doc: Doc, cx: number, cy: number) {
  doc.setDrawColor(...BRAND).setLineWidth(0.38);
  doc.lines([[0, -1.3, 1.1, -2.3, 1.85, -2.3], [0.75, 0, 1.85, 1.0, 1.85, 2.3], [0, 1.4, -1.85, 3.5, -1.85, 3.5], [0, 0, -1.85, -2.1, -1.85, -3.5]], cx - 1.85, cy - 0.6, [1, 1], 'S', true);
  doc.circle(cx, cy - 0.6, 0.55, 'S');
}

function tagIcon(doc: Doc, x: number, cy: number) {
  doc.setDrawColor(255, 255, 255).setLineWidth(0.3);
  doc.lines([[2.2, -1.3], [1.3, 0], [0, 2.6], [-1.3, 0], [-2.2, -1.3]], x, cy, [1, 1], 'S', true);
  doc.circle(x + 2.8, cy - 0.6, 0.28, 'S');
}

function sproutIcon(doc: Doc, cx: number, cy: number) {
  doc.setDrawColor(255, 255, 255).setLineWidth(0.3);
  doc.line(cx, cy + 1.6, cx, cy - 0.2);
  doc.lines([[-0.4, -0.2, -1.6, -0.8, -1.8, -1.9], [0.9, -0.1, 1.7, 0.5, 1.8, 1.9]], cx, cy - 0.2, [1, 1], 'S', false);
  doc.lines([[0.4, -0.3, 1.6, -1.0, 1.8, -2.1], [-0.9, -0.1, -1.7, 0.6, -1.8, 2.1]], cx, cy - 0.2, [1, 1], 'S', false);
}

function miniQrIcon(doc: Doc, x: number, y: number) {
  doc.setDrawColor(...BRAND).setLineWidth(0.25);
  for (const [dx, dy] of [[0, 0], [1.6, 0], [0, 1.6]]) doc.rect(x + dx, y + dy, 1.2, 1.2, 'S');
  doc.setFillColor(...BRAND).rect(x + 1.75, y + 1.75, 0.9, 0.9, 'F');
}

// subtitle: linha por baixo de Thandizo no cartão (programa ou organização cliente);
// logoUrls: logótipos do cliente, da esquerda para a direita (Configuração).
export async function buildCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const [{ jsPDF }, QRCode] = await Promise.all([import('jspdf'), import('qrcode')]);
  const [mark, ...clientLogos] = await Promise.all([loadMark(), ...logoUrls.map(loadLogo)]);
  const logos = clientLogos.filter((l): l is Logo => l !== null);
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
  const perPage = COLS * ROWS;
  const pages = Math.max(1, Math.ceil(cards.length / perPage));

  for (let i = 0; i < cards.length; i++) {
    const card = cards[i];
    const slot = i % perPage;
    if (i > 0 && slot === 0) doc.addPage();
    if (slot === 0) {
      doc.setFont('helvetica', 'normal').setFontSize(7).setTextColor(120);
      doc.text(`${label} · folha ${Math.floor(i / perPage) + 1} de ${pages} · ${cards.length} cartões`, 105, 297 - 2.5, { align: 'center' });
    }
    const x = MARGIN_X + (slot % COLS) * (CARD_W + GAP_X);
    const y = MARGIN_Y + Math.floor(slot / COLS) * (CARD_H + GAP_Y);

    // Fundo branco, cantos rectos, e ondas suaves (a borda desenha-se no fim, por cima).
    doc.setFillColor(255, 255, 255).rect(x, y, CARD_W, CARD_H, 'F');
    doc.setDrawColor(...WAVE).setLineWidth(0.2);
    for (let k = 0; k < 5; k++) {
      doc.lines([[12, -2 - k * 0.3, 28, 2 - k * 0.2, 50, -3 - k * 0.4]], x + 0.5, y + 45.6 - k * 0.6, [1, 1], 'S', false);
    }

    // Cabeçalho: logótipos à esquerda, painel verde inclinado à direita.
    const headH = 11;
    doc.setFillColor(...BRAND);
    doc.rect(x + 60, y, CARD_W - 60, headH, 'F');
    doc.lines([[5, 0, 5, -headH, 10, -headH], [2.5, 0], [0, headH]], x + 52.5, y + headH, [1, 1], 'F', true);
    doc.setFillColor(...ACCENT).rect(x, y + headH - 0.45, 54.5, 0.45, 'F');
    let lx = x + 4;
    for (const logo of logos.slice(0, 3)) {
      const h = 6.2;
      const w = Math.min(h * logo.ratio, 15);
      if (lx + w > x + 51) break;
      doc.addImage(logo.data, 'PNG', lx, y + 2.2, w, h);
      lx += w + 2.2;
      doc.setDrawColor(200).setLineWidth(0.2).line(lx - 1.1, y + 2.4, lx - 1.1, y + 8.2);
    }
    // Logótipo oficial do Thandizo (símbolo e nome), no canto superior direito.
    if (mark) doc.addImage(mark.data, 'PNG', x + 60.2, y + 2.3, 6.4, 6.4);
    doc.setFont('helvetica', 'bold').setFontSize(9).setTextColor(255);
    doc.text('Thandizo', x + 67.6, y + 5.5);
    doc.setFont('helvetica', 'normal').setFontSize(4.1);
    doc.text((subtitle || 'Projecto LINK GCF').toUpperCase(), x + 67.7, y + 8.5, { maxWidth: 16 });

    // Dados do agregado, cada um com o seu ícone.
    const left = x + 13;
    const textWidth = 41;
    doc.setFillColor(...PALE).roundedRect(x + 4, y + 13.6, 6.4, 6.4, 1.5, 1.5, 'F');
    personIcon(doc, x + 7.2, y + 16.8);
    doc.setFont('helvetica', 'normal').setTextColor(...GREY).setFontSize(5);
    doc.text('REPRESENTANTE DO AGREGADO', left, y + 15.4);
    doc.setFont('helvetica', 'bold').setTextColor(...INK).setFontSize(card.representative_name.length > 22 ? 7.6 : 9);
    const name = doc.splitTextToSize(card.representative_name.toUpperCase(), textWidth).slice(0, 2);
    doc.text(name, left, y + 19.2);
    const shift = (name.length - 1) * 3;

    cardIcon(doc, x + 7.2, y + 25.4 + shift);
    doc.setFont('helvetica', 'normal').setTextColor(...GREY).setFontSize(5);
    doc.text('CÓDIGO DO AF', left, y + 24.2 + shift);
    doc.setFont('helvetica', 'bold').setTextColor(...INK).setFontSize(8.4);
    doc.text(card.household_code, left, y + 27.6 + shift);

    const place = [card.community, card.locality].filter(Boolean).join(', ');
    const region = [card.district, card.province].filter(Boolean).join(' · ');
    pinIcon(doc, x + 7.2, y + 34 + shift / 2);
    doc.setFont('helvetica', 'normal').setTextColor(...GREY).setFontSize(5);
    doc.text('LOCALIZAÇÃO', left, y + 32 + shift / 2);
    doc.setTextColor(...INK).setFontSize(6.4);
    doc.text(doc.splitTextToSize(place || '—', textWidth).slice(0, 1), left, y + 35.4 + shift / 2);
    doc.text(doc.splitTextToSize(region || '—', textWidth).slice(0, 1), left, y + 38.4 + shift / 2);

    doc.setDrawColor(...ACCENT).setLineWidth(0.2).line(x + 57, y + 14, x + 57, y + 42);

    // QR em caixa de cantos redondos e o selo "válido apenas para…".
    const qr = await QRCode.toDataURL(card.voucher_code, { errorCorrectionLevel: 'M', margin: 1, width: 360 });
    doc.setFillColor(255, 255, 255).setDrawColor(...QR_BORDER).setLineWidth(0.45);
    doc.roundedRect(x + 61.5, y + 13.4, 19, 19, 2.2, 2.2, 'FD');
    doc.addImage(qr, 'PNG', x + 62.7, y + 14.6, 16.6, 16.6);
    doc.setFillColor(...PALE).roundedRect(x + 60.5, y + 34, 21, 6.6, 1.8, 1.8, 'F');
    miniQrIcon(doc, x + 62, y + 35.9);
    doc.setFont('helvetica', 'normal').setTextColor(...INK).setFontSize(4.7);
    doc.text('Válido apenas para o', x + 66.2, y + 36.8);
    doc.text('agregado indicado', x + 66.2, y + 38.9);

    // Rodapé verde em três partes: código do voucher ao centro.
    const footY = y + CARD_H - 7.8;
    doc.setFillColor(...BRAND).rect(x, footY, CARD_W, 7.8, 'F');
    const mid = footY + 3.9;
    tagIcon(doc, x + 3.2, mid);
    doc.setFont('helvetica', 'normal').setFontSize(4.2).setTextColor(255);
    doc.text('CÓDIGO DO VOUCHER', x + 7.8, mid + 0.6);
    doc.setDrawColor(255, 255, 255).setLineWidth(0.25);
    doc.line(x + 26, mid - 2.3, x + 26, mid + 2.3);
    doc.line(x + 59.5, mid - 2.3, x + 59.5, mid + 2.3);
    doc.setFont('courier', 'bold').setFontSize(8.6);
    doc.text(card.voucher_code, x + 42.75, mid + 1.1, { align: 'center', maxWidth: 32 });
    sproutIcon(doc, x + 62.5, mid);
    doc.setFont('helvetica', 'normal').setFontSize(3.6);
    doc.text('Voucher para aquisição de', x + 65.2, mid - 0.2);
    doc.text('insumos agrícolas', x + 65.2, mid + 1.6);

    // Borda sólida, de cantos rectos, por cima de tudo.
    doc.setDrawColor(130).setLineWidth(0.3).rect(x, y, CARD_W, CARD_H, 'S');
  }

  return doc;
}

export async function downloadCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const doc = await buildCardsPdf(cards, label, subtitle, logoUrls);
  doc.save(`${label}.pdf`);
}
