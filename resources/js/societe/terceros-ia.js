// "Creación con IA" del listado de terceros: chat (texto o voz) guiado por el prompt que parametriza el administrador.
// La IA solo propone datos; el servidor los valida y es quien crea el tercero al confirmar.
import Swal from 'sweetalert2';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;

export class TerceroIaModal {
    constructor({ inicioUrl, mensajeUrl, rutUrl, crearUrl, csrfToken, onCreado }) {
        Object.assign(this, { inicioUrl, mensajeUrl, rutUrl, crearUrl, csrfToken, onCreado });
        this.el = null;
        this.reiniciar();
        this.hablar = false;
        this.manos = false;
    }

    reiniciar() {
        this.historial = [];
        this.datos = {};
        this.estado = { errores: {}, faltan: [], avisos: [], listo: false, campos: [] };
        this.ocupado = false;
        this.vacios = 0;
    }

    async api(url, method, body) {
        const res = await fetch(url, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken },
            body: body ? JSON.stringify(body) : undefined,
        });
        const j = await res.json().catch(() => ({}));
        if (!res.ok) {
            const e = new Error(j.errors ? Object.values(j.errors).flat().join(' ') : (j.message || `Error ${res.status}`));
            e.status = res.status;
            throw e;
        }
        return j;
    }

    async abrir() {
        if (this.el) return;
        this.reiniciar();
        this.el = document.createElement('div');
        this.el.className = 'fixed inset-0 z-[80] grid place-items-center bg-black/45 p-3 backdrop-blur-[2px]';
        this.el.innerHTML = `
            <div class="flex h-[min(720px,calc(100vh-1.5rem))] w-[min(980px,100%)] flex-col overflow-hidden rounded-2xl border border-border bg-card text-card-foreground shadow-2xl" role="dialog" aria-modal="true" aria-label="Creación de tercero con IA">
                <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                    <h3 class="flex items-center gap-2 text-sm font-bold"><i class="fa-solid fa-wand-magic-sparkles text-primary"></i> Creación de tercero con IA</h3>
                    <div class="flex items-center gap-2">
                        ${Reconocimiento ? '<button type="button" data-manos class="h-8 rounded-full border border-border px-3 text-xs font-medium hover:bg-muted" title="Conversación por voz: te hablo y te escucho sin tocar nada"><i class="fa-solid fa-headset"></i> Conversar por voz</button>' : ''}
                        <button type="button" data-voz class="h-8 w-8 rounded-full border border-border text-xs text-muted-foreground hover:bg-muted" title="Leer las respuestas en voz alta"><i class="fa-solid fa-volume-xmark"></i></button>
                        <button type="button" data-nuevo class="h-8 rounded-full border border-border px-3 text-xs font-medium hover:bg-muted" title="Empezar de nuevo"><i class="fa-solid fa-rotate-left"></i> Reiniciar</button>
                        <button type="button" data-cerrar class="h-8 w-8 rounded-full border border-border text-xs hover:bg-muted" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </header>
                <div class="grid min-h-0 flex-1 md:grid-cols-[1fr_300px]">
                    <div class="flex min-h-0 flex-col">
                        <div data-chat class="flex-1 space-y-3 overflow-y-auto px-5 py-4"></div>
                        <form data-form class="flex items-end gap-2 border-t border-border p-3">
                            <button type="button" data-adjuntar class="hidden h-10 w-10 shrink-0 rounded-full border border-border hover:bg-muted" title="Adjuntar el RUT (PDF o imagen)"><i class="fa-solid fa-paperclip"></i></button>
                            <input type="file" data-archivo class="hidden" accept="application/pdf,image/png,image/jpeg,image/webp">
                            ${Reconocimiento ? '<button type="button" data-mic class="h-10 w-10 shrink-0 rounded-full border border-border hover:bg-muted" title="Hablar"><i class="fa-solid fa-microphone"></i></button>' : ''}
                            <textarea data-txt rows="1" class="max-h-28 min-h-10 flex-1 resize-none rounded-xl border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary" placeholder="Escribe, dicta o adjunta el RUT 📎…"></textarea>
                            <button type="submit" data-enviar class="h-10 shrink-0 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50"><i class="fa-solid fa-paper-plane"></i></button>
                        </form>
                    </div>
                    <aside class="hidden min-h-0 flex-col border-l border-border bg-muted/30 md:flex">
                        <div class="flex-1 overflow-y-auto p-4" data-resumen></div>
                        <div class="border-t border-border p-3"><button type="button" data-crear disabled class="w-full rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-40"><i class="fa-solid fa-check"></i> Crear tercero</button></div>
                    </aside>
                </div>
            </div>`;
        document.body.appendChild(this.el);
        this.teclas = (ev) => { if (ev.key === 'Escape') this.cerrar(); };
        document.addEventListener('keydown', this.teclas);
        this.conectar();

        try {
            const ini = await this.api(this.inicioUrl, 'GET');
            this.estado.campos = ini.campos;
            this.permitirRut = ini.permitirRut;
            this.q('[data-adjuntar]').classList.toggle('hidden', !ini.permitirRut);
            this.burbuja('assistant', ini.saludo);
            if (!Reconocimiento) this.burbuja('assistant', 'Tu navegador no permite dictar por voz. Usa Google Chrome o Microsoft Edge para hablarme; mientras tanto puedes escribir.', true);
            else if (!window.isSecureContext) this.burbuja('assistant', 'El micrófono solo funciona en una conexión segura (https).', true);
            this.pintarResumen();
            this.el.querySelector('[data-txt]').focus();
        } catch (err) {
            this.burbuja('assistant', err.message, true);
        }
    }

    cerrar() {
        if (!this.el) return;
        this.manos = false;
        this.parar();
        speechSynthesis?.cancel();
        document.removeEventListener('keydown', this.teclas);
        this.el.remove();
        this.el = null;
    }

    q(sel) { return this.el.querySelector(sel); }

    conectar() {
        const txt = this.q('[data-txt]');
        this.q('[data-cerrar]').onclick = () => this.cerrar();
        this.q('[data-nuevo]').onclick = () => { this.cerrar(); this.abrir(); };
        this.q('[data-form]').onsubmit = (ev) => { ev.preventDefault(); this.enviar(txt.value); };
        txt.onkeydown = (ev) => { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); this.enviar(txt.value); } };
        txt.oninput = () => { txt.style.height = 'auto'; txt.style.height = `${Math.min(txt.scrollHeight, 112)}px`; };
        this.q('[data-crear]').onclick = () => this.crear();
        this.q('[data-voz]').onclick = () => {
            this.hablar = !this.hablar;
            this.q('[data-voz]').innerHTML = `<i class="fa-solid ${this.hablar ? 'fa-volume-high text-primary' : 'fa-volume-xmark'}"></i>`;
            if (!this.hablar) speechSynthesis?.cancel();
        };
        const archivo = this.q('[data-archivo]');
        this.q('[data-adjuntar]').onclick = () => archivo.click();
        archivo.onchange = () => { if (archivo.files[0]) this.enviarRut(archivo.files[0]); archivo.value = ''; };
        // También se puede arrastrar el RUT sobre la ventana.
        const caja = this.q('[role=dialog]');
        caja.ondragover = (ev) => { if (this.permitirRut) ev.preventDefault(); };
        caja.ondrop = (ev) => { if (!this.permitirRut) return; ev.preventDefault(); const f = ev.dataTransfer.files[0]; if (f) this.enviarRut(f); };
        const manos = this.q('[data-manos]');
        if (manos) manos.onclick = () => (this.manos ? this.salirManos() : this.entrarManos());
        const mic = this.q('[data-mic]');
        if (mic) mic.onclick = () => (this.rec ? this.parar() : this.escuchar());
    }

    // ---- voz
    escuchar() {
        speechSynthesis?.cancel();
        const rec = new Reconocimiento();
        rec.lang = 'es-CO';
        rec.interimResults = true;
        rec.continuous = false;
        const txt = this.q('[data-txt]');
        const base = txt.value ? `${txt.value.trim()} ` : '';
        let final = '';
        rec.onresult = (ev) => {
            let interino = '';
            for (const r of ev.results) { if (r.isFinal) final += r[0].transcript; else interino += r[0].transcript; }
            txt.value = base + final + interino;
        };
        rec.onerror = (ev) => {
            this.rec = null;
            this.parar();
            if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
                this.salirManos();
                this.burbuja('assistant', 'No tengo permiso para usar el micrófono. Habilítalo en el candado de la barra de direcciones del navegador.', true);
            } else if (ev.error === 'audio-capture') {
                this.salirManos();
                this.burbuja('assistant', 'No encuentro un micrófono conectado.', true);
            } else if (ev.error === 'network') {
                this.salirManos();
                this.burbuja('assistant', 'El reconocimiento de voz del navegador necesita internet.', true);
            }
        };
        rec.onend = () => {
            const eraEste = this.rec === rec;
            const dicho = txt.value.trim();
            this.parar();
            if (eraEste && dicho && !this.ocupado) { this.vacios = 0; this.enviar(dicho); } // al terminar de hablar se envía solo
            else if (eraEste && this.manos && !this.ocupado) {
                // En conversación por voz, si no dijo nada se vuelve a escuchar un par de veces y luego se pausa.
                if (++this.vacios >= 3) { this.salirManos(); this.burbuja('assistant', 'No te escuché. Pulsa «Conversar por voz» cuando quieras seguir.'); } else setTimeout(() => this.manos && !this.rec && this.escuchar(), 300);
            }
        };
        this.rec = rec;
        this.q('[data-mic]').innerHTML = '<i class="fa-solid fa-stop text-red-600"></i>';
        this.q('[data-mic]').classList.add('animate-pulse');
        try { rec.start(); } catch { this.rec = null; this.parar(); }
    }

    parar() {
        const rec = this.rec;
        this.rec = null;
        try { rec?.stop(); } catch { /* ya detenido */ }
        const mic = this.el?.querySelector('[data-mic]');
        if (mic) { mic.innerHTML = '<i class="fa-solid fa-microphone"></i>'; mic.classList.remove('animate-pulse'); }
    }

    /** Conversación por voz: el asistente habla y, al terminar, vuelve a escuchar solo. */
    entrarManos() {
        this.manos = true;
        this.vacios = 0;
        this.hablar = true;
        this.q('[data-voz]').innerHTML = '<i class="fa-solid fa-volume-high text-primary"></i>';
        const b = this.q('[data-manos]');
        b.innerHTML = '<i class="fa-solid fa-headset"></i> Detener voz';
        b.classList.add('bg-primary', 'text-primary-foreground');
        if (!this.ocupado) this.escuchar();
    }

    salirManos() {
        if (!this.el) return;
        this.manos = false;
        this.parar();
        speechSynthesis?.cancel();
        const b = this.q('[data-manos]');
        if (b) { b.innerHTML = '<i class="fa-solid fa-headset"></i> Conversar por voz'; b.classList.remove('bg-primary', 'text-primary-foreground'); }
    }

    /** Lee el texto en voz alta (voz en español si existe) y devuelve una promesa que se cumple al terminar. */
    decir(texto) {
        if (!this.hablar || !window.speechSynthesis) return Promise.resolve();
        speechSynthesis.cancel();
        return new Promise((ok) => {
            const u = new SpeechSynthesisUtterance(texto);
            u.lang = 'es-CO';
            const voz = speechSynthesis.getVoices().find((v) => v.lang.toLowerCase().startsWith('es-co')) || speechSynthesis.getVoices().find((v) => v.lang.toLowerCase().startsWith('es'));
            if (voz) u.voice = voz;
            u.onend = u.onerror = () => ok();
            speechSynthesis.speak(u);
        });
    }

    // ---- conversación
    burbuja(rol, texto, error = false) {
        const chat = this.q('[data-chat]');
        const b = document.createElement('div');
        const yo = rol === 'user';
        b.className = `flex ${yo ? 'justify-end' : 'justify-start'}`;
        b.innerHTML = `<div class="max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm ${yo ? 'bg-primary text-primary-foreground' : error ? 'bg-red-500/10 text-red-700' : 'bg-muted'}">${esc(texto)}</div>`;
        chat.appendChild(b);
        chat.scrollTop = chat.scrollHeight;
        return b;
    }

    async enviar(texto) {
        texto = texto.trim();
        if (!texto || this.ocupado) return;
        this.parar();
        this.ocupado = true;
        const txt = this.q('[data-txt]');
        txt.value = '';
        txt.style.height = 'auto';
        this.burbuja('user', texto);
        this.historial.push({ role: 'user', content: texto });
        this.q('[data-enviar]').disabled = true;
        const pensando = this.burbuja('assistant', 'Pensando…');
        pensando.firstChild.classList.add('animate-pulse', 'text-muted-foreground');
        try {
            const r = await this.api(this.mensajeUrl, 'POST', { historial: this.historial, datos: this.datos });
            pensando.remove();
            this.recibir(r);
        } catch (err) {
            pensando.remove();
            this.historial.pop(); // el mensaje no se procesó: se puede reintentar
            this.burbuja('assistant', err.message, true);
        } finally {
            this.ocupado = false;
            this.q('[data-enviar]').disabled = false;
            this.q('[data-txt]').focus();
        }
    }

    recibir(r) {
        this.datos = r.datos;
        this.estado = r;
        this.historial.push({ role: 'assistant', content: r.mensaje });
        this.burbuja('assistant', r.mensaje);
        this.pintarResumen();
        this.decir(r.mensaje).then(() => {
            if (!this.manos || !this.el || this.ocupado) return;
            if (r.listo) { this.salirManos(); return; } // ya está todo: toca confirmar con el botón
            this.escuchar();
        });
    }

    /** Sube el RUT: el servidor lo lee con la IA, valida los datos y responde como un turno más de la conversación. */
    async enviarRut(file) {
        if (this.ocupado) return;
        if (file.size > 8 * 1024 * 1024) { this.burbuja('assistant', 'El archivo no puede superar 8 MB.', true); return; }
        this.parar();
        this.ocupado = true;
        this.q('[data-enviar]').disabled = true;
        const texto = `📎 RUT adjunto: ${file.name}`;
        this.burbuja('user', texto);
        const pensando = this.burbuja('assistant', 'Leyendo el RUT…');
        pensando.firstChild.classList.add('animate-pulse', 'text-muted-foreground');
        try {
            const fd = new FormData();
            fd.append('archivo', file);
            fd.append('historial', JSON.stringify(this.historial));
            fd.append('datos', JSON.stringify(this.datos));
            const res = await fetch(this.rutUrl, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken }, body: fd });
            const r = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(r.errors ? Object.values(r.errors).flat().join(' ') : (r.message || `Error ${res.status}`));
            pensando.remove();
            this.historial.push({ role: 'user', content: texto });
            this.recibir(r);
        } catch (err) {
            pensando.remove();
            this.burbuja('assistant', err.message, true);
        } finally {
            this.ocupado = false;
            this.q('[data-enviar]').disabled = false;
        }
    }

    pintarResumen() {
        const e = this.estado;
        const filas = e.campos.map((c) => {
            const v = this.datos[c.clave];
            const err = e.errores?.[c.clave];
            const ico = err ? '<i class="fa-solid fa-triangle-exclamation text-red-600"></i>' : v ? '<i class="fa-solid fa-circle-check text-emerald-600"></i>' : `<i class="fa-regular fa-circle ${c.obligatorio ? 'text-amber-500' : 'text-muted-foreground'}"></i>`;
            return `<li class="py-1.5"><div class="flex items-start gap-2 text-[13px]">${ico}<div class="min-w-0"><span class="text-xs font-semibold text-muted-foreground">${esc(c.etiqueta)}${c.obligatorio ? ' *' : ''}</span>
                <div class="break-words ${v ? '' : 'text-muted-foreground'}">${v ? esc(v) : '—'}</div>${err ? `<div class="text-xs text-red-600">${esc(err)}</div>` : ''}</div></div></li>`;
        }).join('');
        const avisos = (e.avisos ?? []).map((a) => `<p class="mt-2 rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-700"><i class="fa-solid fa-circle-info"></i> ${esc(a)}</p>`).join('');
        this.q('[data-resumen]').innerHTML = `<h4 class="mb-1 text-xs font-bold uppercase tracking-wide text-muted-foreground">Datos del tercero</h4><ul class="divide-y divide-border">${filas}</ul>${avisos}`;
        this.q('[data-crear]').disabled = !e.listo || this.creando;
    }

    async crear() {
        if (!this.estado.listo || this.creando) return;
        this.creando = true;
        const btn = this.q('[data-crear]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Creando…';
        try {
            const r = await this.api(this.crearUrl, 'POST', { datos: this.datos });
            this.cerrar();
            this.onCreado?.(r.tercero);
            Swal.fire({ icon: 'success', title: 'Tercero creado', text: `${r.tercero.nom} (${r.tercero.siren})`, timer: 2500, showConfirmButton: false });
        } catch (err) {
            this.creando = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Crear tercero';
            this.burbuja('assistant', `No se pudo crear: ${err.message}`, true);
            btn.disabled = false;
        }
    }
}
