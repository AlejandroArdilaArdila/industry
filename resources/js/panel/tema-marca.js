// Colores de marca del panel: tres colores editables (primario, acento y barra lateral) que se aplican encima del tema elegido.
// Se pueden sacar del logo (public/logo.png) con un botón. Se guardan en este navegador, igual que el tema.
export const CLAVE_MARCA = 'panel-theme-brand'; // {primary, accent, sidebar} en #rrggbb
export const CLAVE_VARS = 'panel-theme-brand-vars'; // variables CSS ya calculadas (las lee el <head> para no parpadear)

const HEX = /^#[0-9a-f]{6}$/i;

const aRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const aHex = ([r, g, b]) => `#${[r, g, b].map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`;
const luz = (hex) => {
    const [r, g, b] = aRgb(hex).map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
/** Texto negro o blanco, el que mejor se lea sobre `hex`. */
const sobre = (hex) => (luz(hex) > 0.35 ? '#111827' : '#ffffff');
const mezcla = (a, b, t) => aHex(aRgb(a).map((v, i) => v * (1 - t) + aRgb(b)[i] * t));

/** Variables CSS que salen de los tres colores. */
export function variables({ primary, accent, sidebar }) {
    const v = {};
    if (HEX.test(primary)) {
        // Las grillas Syncfusion leen su primario como "r, g, b".
        Object.assign(v, {
            '--grid-light-primary': aRgb(primary).join(', '), '--grid-light-on-primary': aRgb(sobre(primary)).join(', '),
            '--grid-light-primary-container': aRgb(mezcla('#ffffff', primary, 0.14)).join(', '), '--grid-light-on-primary-container': aRgb(mezcla('#000000', primary, 0.45)).join(', '),
        });
    }
    if (HEX.test(primary)) Object.assign(v, { '--primary': primary, '--primary-foreground': sobre(primary), '--ring': primary, '--sidebar-primary': primary, '--sidebar-primary-foreground': sobre(primary) });
    if (HEX.test(accent)) Object.assign(v, { '--brand-accent': accent, '--accent': mezcla('#ffffff', accent, 0.16), '--accent-foreground': '#1f2937' });
    if (HEX.test(sidebar)) {
        const t = sobre(sidebar);
        Object.assign(v, {
            '--sidebar': sidebar, '--sidebar-foreground': t, '--sidebar-accent-foreground': t,
            '--sidebar-accent': t === '#ffffff' ? 'rgba(255,255,255,0.10)' : 'rgba(17,24,39,0.08)',
            '--sidebar-border': t === '#ffffff' ? 'rgba(255,255,255,0.14)' : 'rgba(17,24,39,0.12)',
        });
    }

    return v;
}

export function aplicar(colores) {
    const v = colores ? variables(colores) : {};
    const el = document.documentElement;
    Object.keys(localStorage.getItem(CLAVE_VARS) ? JSON.parse(localStorage.getItem(CLAVE_VARS)) : {}).forEach((k) => el.style.removeProperty(k));
    Object.entries(v).forEach(([k, val]) => el.style.setProperty(k, val));
    try {
        if (colores) { localStorage.setItem(CLAVE_MARCA, JSON.stringify(colores)); localStorage.setItem(CLAVE_VARS, JSON.stringify(v)); } else { localStorage.removeItem(CLAVE_MARCA); localStorage.removeItem(CLAVE_VARS); }
    } catch { /* sin almacenamiento: se aplica solo en esta carga */ }
}

export const guardados = () => { try { return JSON.parse(localStorage.getItem(CLAVE_MARCA) ?? 'null'); } catch { return null; } };

const aHsl = ([r, g, b]) => {
    r /= 255; g /= 255; b /= 255;
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2, d = mx - mn;
    if (!d) return [0, 0, l];
    const s = d / (1 - Math.abs(2 * l - 1));
    const h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;

    return [(h * 60 + 360) % 360, s, l];
};

/** Lee el logo y devuelve {primary, accent, sidebar}: los dos tonos de marca con más presencia y un tinte claro del primario para las barras. */
export async function coloresDelLogo(url) {
    const img = new Image();
    img.src = url;
    await img.decode();
    const w = 160, h = Math.max(1, Math.round((img.naturalHeight / img.naturalWidth) * w));
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.drawImage(img, 0, 0, w, h);
    const px = x.getImageData(0, 0, w, h).data;

    const tonos = new Map(); // cubeta de matiz (20°) => {n, rgb}
    for (let i = 0; i < px.length; i += 4) {
        if (px[i + 3] < 200) continue;
        const rgb = [px[i], px[i + 1], px[i + 2]];
        const [hh, s, l] = aHsl(rgb);
        if (l > 0.92) continue; // fondo blanco
        if (s >= 0.45 && l > 0.2 && l < 0.8) {
            const k = Math.floor(hh / 20);
            const t = tonos.get(k) ?? { n: 0, rgb: [0, 0, 0], h: hh };
            t.n++; t.rgb = t.rgb.map((v, j) => v + rgb[j]);
            tonos.set(k, t);
        }
    }
    const media = (t) => aHex(t.rgb.map((v) => v / t.n));
    const orden = [...tonos.values()].filter((t) => t.n > 8).sort((a, b) => b.n - a.n);
    if (!orden.length) throw new Error('No encontré colores de marca en el logo.');
    const [p, ...resto] = orden;
    const dif = (a, b) => Math.min(Math.abs(a - b), 360 - Math.abs(a - b));
    const a = resto.find((t) => dif(t.h, p.h) > 40) ?? p;
    const primary = media(p);

    // El logo tiene texto gris pensado para fondo claro: la barra sugerida es un tinte muy claro del color primario.
    return { primary, accent: media(a), sidebar: mezcla(primary, '#ffffff', 0.93) };
}
