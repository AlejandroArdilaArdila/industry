// Modal «Mi día»: al llegar al tablero muestra, ordenadas por urgencia, las acciones sugeridas para gestionar el día
// (actividades vencidas, agenda de hoy, solicitudes por contactar, cotizaciones sin movimiento, pagos pendientes, entregas vencidas…),
// cada una con ejemplos concretos y un botón para ir directo. Al cerrarlo con «Empezar mi día» arranca la rotación del tablero
// desde Solicitudes. Si el usuario elige una sugerencia, el tablero queda pausado en lo que fue a revisar.
import { esc, dineroCorto } from './dashboard-util.js';


export function iniciarPlanDia({ url, userId, saludo, acciones }) {
    let alcance = null; // null = lo que decida el servidor (equipo si es administrador)
    let el = null;
    let datos = null;
    
    /** Preloading discreto abajo a la derecha mientras se prepara el plan (la consulta tarda un poco). */
    let carga = null;
    const mostrarCarga = () => {
        carga = document.createElement('div');
        carga.className = 'pd-carga';
        carga.setAttribute('role', 'status');
        carga.innerHTML = '<span class="tf-spin"></span><div><b>Preparando tu día…</b><small>Revisando tus pendientes</small></div>';
        document.body.appendChild(carga);
        requestAnimationFrame(() => carga?.classList.add('on'));
    };
    const quitarCarga = () => {
        const n = carga; carga = null;
        if (!n) return;
        n.classList.remove('on');
        setTimeout(() => n.remove(), 250);
    };

    function cerrar(empezar) {
        if (!el) return;
        document.removeEventListener('keydown', teclas);
        const nodo = el; el = null;
        nodo.classList.remove('is-open');
        setTimeout(() => nodo.remove(), 250);
        document.documentElement.style.overflow = '';
        if (empezar) acciones.empezar();
    }
    const teclas = (e) => { if (e.key === 'Escape') cerrar(true); };

    function item(s, i) {
        const docs = s.docs?.length ? `<div class="pd-docs">${s.docs.map((d) => `<button type="button" data-doc="${esc(d.modulo)}:${d.doc}" title="Abrir ${esc(d.ref)}"><b>${esc(d.ref)}</b><span>${esc(d.empresa)}${d.gestor ? ` · ${esc(d.gestor)}` : ''}</span><small>${d.dias} d</small></button>`).join('')}</div>` : '';

        return `<article class="pd-item ${s.tono}" style="--k:${i}">
            <span class="pd-ico"><i class="fa-solid ${esc(s.icono)}"></i></span>
            <div class="pd-cuerpo"><h3>${esc(s.titulo)}${s.valor ? ` <em title="Valor en juego">${esc(dineroCorto(s.valor))}</em>` : ''}</h3><p>${esc(s.detalle)}</p>${docs}</div>
            <button type="button" class="pd-btn" data-i="${i}">${esc(s.boton)} <i class="fa-solid fa-arrow-right"></i></button></article>`;
    }

    function pintar() {
        const hoy = new Date().toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });
        const lista = datos.vacio
            ? `<div class="pd-vacio"><span><i class="fa-solid fa-check"></i></span><h3>¡Estás al día!</h3><p>No tienes nada urgente. Aprovecha para adelantar: programa el próximo contacto con un cliente o retoma una cotización.</p></div>`
            : datos.items.map(item).join('');
        el.innerHTML = `<div class="pd-modal" role="dialog" aria-modal="true" aria-label="Mi día">
            <header class="pd-head"><span class="pd-sol"><i class="fa-solid fa-sun"></i></span>
                <div class="min-w-0 flex-1"><h2>${esc(saludo)}</h2><p>${esc(hoy)} · ${datos.vacio ? 'sin pendientes urgentes' : `${datos.items.length} ${datos.items.length === 1 ? 'sugerencia' : 'sugerencias'} para gestionar ${datos.alcance === 'equipo' ? 'el día del equipo' : 'tu día'}`}</p></div>
                ${datos.esAdmin ? `<div class="pd-alc"><button type="button" data-alcance="mios" class="${datos.alcance === 'mios' ? 'on' : ''}">Mío</button><button type="button" data-alcance="equipo" class="${datos.alcance === 'equipo' ? 'on' : ''}">Equipo</button></div>` : ''}
                <button type="button" data-cerrar class="pd-x" title="Cerrar (Esc)"><i class="fa-solid fa-xmark"></i></button></header>
            <div class="pd-lista">${lista}</div>
            <footer class="pd-pie"><span><i class="fa-solid fa-play"></i> Al empezar, el tablero rota desde Solicitudes</span>
                <div><button type="button" data-programar class="pd-sec"><i class="fa-solid fa-plus"></i> Programar</button><button type="button" data-empezar class="pd-pri"><i class="fa-solid fa-play"></i> Empezar mi día</button></div></footer></div>`;
    }

    async function onClick(e) {
        if (e.target === el || e.target.closest('[data-cerrar]')) return cerrar(true);
        if (e.target.closest('[data-empezar]')) return cerrar(true);
        const alc = e.target.closest('[data-alcance]');
        if (alc) { alcance = alc.dataset.alcance; await cargarDatos(); pintar(); return null; }
        if (e.target.closest('[data-programar]')) { cerrar(false); return acciones.programar(); }
        const d = e.target.closest('[data-doc]');
        if (d) { const [m, id] = d.dataset.doc.split(':'); cerrar(false); return acciones.abrirDoc(m, id); }
        const b = e.target.closest('[data-i]');
        if (!b) return null;
        const a = datos.items[Number(b.dataset.i)].accion;
        cerrar(false);
        if (a.tipo === 'vencidas') acciones.vencidas();
        else if (a.tipo === 'actividades') acciones.actividades(a.filtro);
        else if (a.tipo === 'etapa') acciones.etapa(a.clave, a.nivel);
        else if (a.tipo === 'url') window.location.href = a.href;
        return null;
    }

    async function cargarDatos() {
        try {
            const res = await fetch(alcance ? `${url}?alcance=${alcance}` : url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(`Error ${res.status}`);
            datos = await res.json();
            alcance = datos.alcance;

            return true;
        } catch (e) { console.error(e); return false; }
    }

    /** Abre el plan del día (siempre que se llega al tablero y con el botón «Mi día»). Devuelve true si el modal se mostró. */
    async function abrir() {
        if (el) return true;
        mostrarCarga();
        const ok = await cargarDatos();
        quitarCarga();
        if (!ok) return false;
        acciones.pausar(); // mientras se lee el plan el tablero no rota
        el = document.createElement('div');
        el.className = 'pd-overlay';
        document.body.appendChild(el);
        document.documentElement.style.overflow = 'hidden';
        pintar();
        el.addEventListener('click', onClick);
        document.addEventListener('keydown', teclas);
        void el.offsetWidth;
        el.classList.add('is-open');

        return true;
    }

    return { abrir };
}
