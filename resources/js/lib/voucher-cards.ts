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
const BRAND: [number, number, number] = [0, 91, 55];\nconst PALE: [number, number, number] = [225, 245, 229];\nconst ACCENT: [number, number, number] = [112, 190, 65];

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

// subtitle: linha por baixo de THANDIZO no cartão (programa ou organização cliente);
// logoUrls: logótipos do cliente, da esquerda para a direita (Configuração).
export async function buildCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const [{ jsPDF }, QRCode] = await Promise.all([import('jspdf'), import('qrcode')]);
  const logos = (await Promise.all(logoUrls.map(loadLogo))).filter((l): l is Logo => l !== null);
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

    // Cartão com cantos suaves e cabeçalho institucional do mockup.
    doc.setFillColor(255,255,255).setDrawColor(220).setLineWidth(0.2);
    doc.roundedRect(x,y,CARD_W,CARD_H,3.2,3.2,'FD');
    doc.setFillColor(...BRAND);
    doc.roundedRect(x+55,y,30,14,3.2,3.2,'F');
    doc.rect(x+55,y+3,30,11,'F');
    doc.setFillColor(...ACCENT).rect(x,y+13.7,CARD_W,0.45,'F');
    let lx=x+3.5;
    for (const logo of logos.slice(0,3)) {
      const h=6.4,w=Math.min(h*logo.ratio,17);
      if(lx+w>x+53) break;
      doc.addImage(logo.data,'PNG',lx,y+3.1,w,h); lx+=w+3;
      doc.setDrawColor(190).setLineWidth(0.2).line(lx-1.5,y+3,lx-1.5,y+10.5);
    }
    doc.setFont('helvetica','bold').setFontSize(9.2).setTextColor(255);
    doc.text('THANDIZO',x+82,y+5.8,{align:'right'});
    doc.setFont('helvetica','normal').setFontSize(5.1);
    doc.text((subtitle||'PROJECTO LINK GCF').toUpperCase(),x+82,y+9.5,{align:'right',maxWidth:26});

    // Dados do agregado: nome, código AF e localização
    const left = x + 4;
    const textWidth = CARD_W - 35;
    doc.setFont('helvetica', 'normal').setTextColor(105).setFontSize(5.2);
    doc.text('REPRESENTANTE DO AGREGADO', left, y + 17);
    doc.setFont('helvetica', 'bold').setTextColor(25).setFontSize(8.2);
    const name = doc.splitTextToSize(card.representative_name.toUpperCase(), textWidth).slice(0, 2);
    doc.text(name, left, y + 21);
    const nameBottom = y + 21 + (name.length - 1) * 3.3;

    doc.setFont('helvetica', 'normal').setTextColor(105).setFontSize(5.2);
    doc.text('CÓDIGO DO AF', left, nameBottom + 4.2);
    doc.setFont('helvetica', 'bold').setTextColor(25).setFontSize(7.4);
    doc.text(card.household_code, left, nameBottom + 7.5);

    const place = [card.community, card.locality].filter(Boolean).join(', ');
    const region = [card.district, card.province].filter(Boolean).join(' · ');
    doc.setFont('helvetica', 'normal').setTextColor(65).setFontSize(5.4);
    doc.text(doc.splitTextToSize(place || '—', textWidth).slice(0, 1), left, y + 39);
    doc.text(doc.splitTextToSize(region || '—', textWidth).slice(0, 1), left, y + 42);

    // QR e validação em caixa verde-clara, conforme mockup.
    const qr = await QRCode.toDataURL(card.voucher_code, { errorCorrectionLevel: 'M', margin: 1, width: 360 });
    doc.setFillColor(255,255,255).setDrawColor(155,225,175).setLineWidth(0.45);
    doc.roundedRect(x+60.5,y+16,21.5,21.5,2.2,2.2,'FD');
    doc.addImage(qr,'PNG',x+62,y+17.5,18.5,18.5);
    doc.setFillColor(...PALE).roundedRect(x+59.5,y+38.5,24,5.2,1.5,1.5,'F');
    doc.setFont('helvetica','normal').setTextColor(...BRAND).setFontSize(4.3);
    doc.text('VÁLIDO APENAS PARA O',x+71.5,y+40.6,{align:'center'});
    doc.text('AGREGADO INDICADO',x+71.5,y+42.5,{align:'center'});

    // Rodapé tripartido com código e finalidade agrícola.
    doc.setFillColor(...BRAND).roundedRect(x,y+45,CARD_W,9,3.2,3.2,'F');
    doc.rect(x,y+45,CARD_W,3,'F');
    doc.setDrawColor(255,255,255).setLineWidth(0.25);
    doc.line(x+23,y+47,x+23,y+52); doc.line(x+62,y+47,x+62,y+52);
    doc.setFont('helvetica','normal').setFontSize(4.5).setTextColor(255);
    doc.text('CÓDIGO DO VOUCHER',x+4,y+50.5);
    doc.setFont('courier','bold').setFontSize(7.3);
    doc.text(card.voucher_code,x+42.5,y+51,{align:'center',maxWidth:37});
    doc.setFont('helvetica','normal').setFontSize(4.2);
    doc.text('Voucher para aquisição',x+64,y+49.2);
    doc.text('de insumos agrícolas',x+64,y+51.3);

