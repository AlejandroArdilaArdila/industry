// Alerta de actividades vencidas: modal que aparece al entrar al panel cuando hay tareas o recordatorios atrasados
// (o de hoy cuya hora ya pasó). Cada tarjeta trae cliente, contacto y una sugerencia, y se gestiona sin salir:
// completar, posponer (mañana / 3 días / 1 semana), abrirla o silenciarla unas horas.
import Swal from 'sweetalert2';
import { toast } from './ficha-modal.js';
import { esc, isoLocal } from './dashboard-util.js';

const HEADERS = (csrf) => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' });
const fechaCorta = (iso) => new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;

export function iniciarAlertas({ url, silenciarUrl, csrf, urls, abrirTarea, abrirDocumento, onCambio, auto = true }) {
    let el = null;
    let abierto = false;
    let ultima = 0;
    let datos = null;

    const api = async (u, method = 'GET', body) => {
        const res = await fetch(u, { method, headers: HEADERS(csrf), body: body ? JSON.stringify(body) : undefined });
        const j = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(j.errors ? Object.values(j.errors)[0][0] : j.message || `Error ${res.status}`);
        return j;
    };

    const cerrar = () => {
        if (!el) return;
        document.removeEventListener('keydown', teclas);
        el.classList.remove('is-open');
        const nodo = el; el = null; abierto = false;
        setTimeout(() => nodo.remove(), 250);
        document.documentElement.style.overflow = '';
    };
    const teclas = (e) => { if (e.key === 'Escape') cerrar(); };

    function cliente(c) {
        if (!c) return '';
        const k = c.contacto;
        const fila = (t, v) => (v ? `<div class="av-fila"><span>${t}</span><b>${v}</b></div>` : '');
        const tels = k?.telefonos?.length ? k.telefonos.map((t) => `<a href="tel:${esc(t.replace(/\s/g, ''))}"><i class="fa-solid fa-phone"></i> ${esc(t)}</a>`).join('<i class="av-sep"></i>') : (c.telefono ? `<a href="tel:${esc(c.telefono.replace(/\s/g, ''))}"><i class="fa-solid fa-phone"></i> ${esc(c.telefono)}</a>` : '');
        const mail = k?.email || c.email;
        return `<div class="av-cliente">${fila('Cliente', esc(c.nombre))}${fila('Contacto', k?.nombre ? `<i class="fa-solid fa-user"></i> ${esc(k.nombre)}` : '')}
            ${fila('Correo', mail ? `<a href="mailto:${esc(mail)}"><i class="fa-regular fa-envelope"></i> ${esc(mail)}</a>` : '')}${fila('Teléfonos', tels)}
            ${c.otros ? `<div class="av-mas"><i class="fa-solid fa-people-group"></i> ${plural(c.otros, 'contacto más', 'contactos más')} en la ficha del cliente</div>` : ''}</div>`;
    }

    function tarjeta(a, i) {
        const hora = a.cuando.slice(11, 16);
        const tag = a.esHoy ? `Venció hoy a las ${hora}` : `Vencida hace ${plural(a.diasAtraso, 'día', 'días')}`;
        const titulo = a.cliente?.nombre || a.empresa || a.titulo;
        const sub = [a.ref ? `Ref: <b>${esc(a.ref)}</b>` : '', a.tipoNombre && a.origen === 'tarea' ? esc(a.tipoNombre) : '', a.creador ? `Creada por ${esc(a.creador)}` : '', `Pactado: <b class="av-pactado">${esc(fechaCorta(a.cuando))}</b>`].filter(Boolean).join(' · ');
        const ficha = a.origen === 'tarea' && a.modulo;
        const posponer = a.puedeCambiar ? `<div class="av-pos"><span>Posponer:</span>
            ${a.esHoy && new Date().getHours() < 21 ? '<button type="button" data-acc="posponer" data-horas="2">2 horas</button>' : ''}
            <button type="button" data-acc="posponer" data-dias="1">Mañana</button><button type="button" data-acc="posponer" data-dias="3">3 días</button><button type="button" data-acc="posponer" data-dias="7">1 semana</button></div>` : '';
        return `<article class="av-card ${a.esHoy ? 'hoy' : ''}" data-clave="${esc(a.clave)}" style="--k:${Math.min(i, 8)}">
            <header><div class="min-w-0"><h3>${esc(titulo)}</h3><p>${sub}</p></div>
                <button type="button" data-acc="silenciar" class="av-sil" title="Silenciar ${datos.horas} horas"><i class="fa-solid fa-bell-slash"></i> Silenciar</button></header>
            <div class="av-asunto"><b>Asunto:</b> ${esc(a.titulo)}${a.observacion ? `<small>${esc(a.observacion.slice(0, 180))}</small>` : ''}</div>
            <div class="av-chips"><span class="av-venc ${a.esHoy ? 'hoy' : ''}"><i class="fa-solid fa-clock"></i> ${tag}</span>
                ${a.prioridad ? `<span><i class="fa-solid fa-flag" style="color:${esc(a.prioridad.color)}"></i> ${esc(a.prioridad.label)}</span>` : ''}
                <span><i class="fa-solid ${esc(a.icono)}"></i> ${esc(a.tipoNombre)}</span></div>
            ${cliente(a.cliente)}
            <p class="av-sug"><i class="fa-solid fa-lightbulb"></i> ${esc(a.sugerencia)}</p>
            <div class="av-acciones">
                <button type="button" data-acc="gestionar" class="av-pri"><i class="fa-solid fa-pen-to-square"></i> Gestionar seguimiento</button>
                ${ficha ? '<button type="button" data-acc="ficha" class="av-sec"><i class="fa-solid fa-arrow-up-right-from-square"></i> Ver ficha</button>' : ''}
                ${a.origen === 'tarea' && a.puedeCambiar ? '<button type="button" data-acc="completar" class="av-ok"><i class="fa-solid fa-check"></i> Completar</button>' : ''}
            </div>${posponer}
            <div class="av-trabajando"><span class="tf-spin"></span></div></article>`;
    }

    function pintar() {
        const n = datos.total;
        el.querySelector('[data-av-sub]').textContent = `${plural(n, 'actividad requiere', 'actividades requieren')} tu atención — gestiona, pospón o silencia`;
        el.querySelector('[data-av-lista]').innerHTML = datos.items.map(tarjeta).join('');
        el.querySelector('[data-av-mas]').textContent = n > datos.items.length ? `Mostrando ${datos.items.length} de ${n}; al gestionarlas aparecen las demás.` : '';
    }

    function abrirModal() {
        if (abierto) return;
        abierto = true;
        el = document.createElement('div');
        el.className = 'av-overlay';
        el.innerHTML = `<div class="av-modal" role="dialog" aria-modal="true" aria-label="Actividades vencidas">
            <header class="av-head"><span class="av-ico"><i class="fa-solid fa-bell"></i></span>
                <div class="min-w-0 flex-1"><h2>Actividades vencidas</h2><p data-av-sub></p></div>
                <button type="button" data-acc="cerrar" class="av-x" title="Cerrar (Esc)"><i class="fa-solid fa-xmark"></i></button></header>
            <div class="av-lista" data-av-lista></div>
            <footer class="av-pie"><span><i class="fa-solid fa-clock-rotate-left"></i> Se volverá a mostrar en ${datos.horas} h si no se gestiona <em data-av-mas></em></span>
                <button type="button" data-acc="silenciar-todas" class="av-todas"><i class="fa-solid fa-bell-slash"></i> Silenciar todas</button></footer></div>`;
        document.body.appendChild(el);
        document.documentElement.style.overflow = 'hidden';
        document.addEventListener('keydown', teclas);
        void el.offsetWidth;
        el.classList.add('is-open');
        pintar();
        el.addEventListener('click', onClick);
    }

    /** Quita una tarjeta (gestionada o silenciada) y cierra el modal cuando no queda ninguna. */
    function quitar(card) {
        datos.items = datos.items.filter((x) => x.clave !== card.dataset.clave);
        datos.total = Math.max(0, datos.total - 1);
        card.classList.add('sale');
        setTimeout(() => {
            card.remove();
            if (!datos.items.length) {
                cerrar();
                if (datos.total === 0) toast.fire({ icon: 'success', title: '¡Estás al día con tus actividades!' });
                else setTimeout(revisar, 300);
            } else pintar();
        }, 280);
    }

    async function onClick(e) {
        const b = e.target.closest('[data-acc]');
        if (!b) { if (e.target === el) cerrar(); return; }
        const acc = b.dataset.acc;
        if (acc === 'cerrar') return cerrar();
        if (acc === 'silenciar-todas') {
            try { await api(silenciarUrl, 'POST', { todas: true }); cerrar(); toast.fire({ icon: 'info', title: `Silenciadas por ${datos.horas} horas` }); } catch (err) { toast.fire({ icon: 'error', title: err.message }); }
            return;
        }
        const card = b.closest('.av-card');
        const a = datos.items.find((x) => x.clave === card?.dataset.clave);
        if (!a) return;

        if (acc === 'gestionar' || acc === 'ficha') {
            cerrar();
            if (acc === 'ficha' || a.origen === 'recordatorio') abrirDocumento(a.modulo, a.fkRecord);
            else abrirTarea(a.id);
            return;
        }
        card.classList.add('trabajando');
        try {
            if (acc === 'silenciar') {
                await api(silenciarUrl, 'POST', { claves: [a.clave] });
                toast.fire({ icon: 'info', title: `Silenciada por ${datos.horas} horas` });
            } else if (acc === 'completar') {
                const r = await api(urls.tareaEstado.replace('__ID__', a.id), 'PUT', { cerrada: true });
                toast.fire({ icon: 'success', title: r.message }); onCambio?.();
            } else if (acc === 'posponer') {
                const d = new Date();
                const body = {};
                if (b.dataset.horas) { d.setHours(d.getHours() + Number(b.dataset.horas)); body.fecha = isoLocal(d); body.hora = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; }
                else { d.setDate(d.getDate() + Number(b.dataset.dias)); body.fecha = isoLocal(d); }
                const u = a.origen === 'tarea' ? urls.tareaPosponer : urls.eventoPosponer;
                const r = await api(u.replace('__ID__', a.id), 'PUT', body);
                toast.fire({ icon: 'success', title: r.message }); onCambio?.();
            }
            quitar(card);
        } catch (err) {
            card.classList.remove('trabajando');
            Swal.fire({ icon: 'error', title: 'No se pudo', text: err.message });
        }
    }

    /** Consulta las vencidas y abre el modal si hay (no interrumpe si el usuario ya tiene un panel o modal abierto). */
    async function revisar(forzar = false) {
        if (abierto || (!forzar && Date.now() - ultima < 60000)) return;
        if (document.querySelector('.ficha-overlay.is-open') || document.querySelector('.swal2-container')) return;
        ultima = Date.now();
        try {
            datos = await api(url);
            if (datos.items.length && !document.querySelector('.ficha-overlay.is-open')) abrirModal();
        } catch (e) { console.error(e); }
    }

    // Primera revisión poco después de cargar y luego cada 30 min (o al volver a la pestaña tras un rato).
    if (auto) setTimeout(() => revisar(true), 1800);
    setInterval(() => { if (document.visibilityState === 'visible') revisar(true); }, 30 * 60 * 1000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Date.now() - ultima > 30 * 60 * 1000) revisar(true); });

    return { revisar: () => revisar(true) };
}
