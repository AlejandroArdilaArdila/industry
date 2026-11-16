// Tarjetas de documentos con el formato del tablero de Dolibarr: solicitud, cotización, orden de compra y entrega / solución.
// Los datos llegan ya calculados (TarjetasDocumentoService / SolicitudesTableroService): clasificaciones de la leyenda, moras con su
// color de salud, fechas de despacho y entrega, guía, importación, OC del cliente, valor y último seguimiento con su autor.
// Borde izquierdo = clasificación (urgencia / oportunidad / problema); borde derecho y punto = salud de la mora principal.
import '../../css/tarjetas-documento.css';
import { esc } from './dashboard-util.js';

const nf = new Intl.NumberFormat('es-CO');
const F = (iso) => (iso ? `${String(iso).slice(8, 10)}/${String(iso).slice(5, 7)}/${String(iso).slice(0, 4)}` : '—');
const GRIS = '#94a3b8';
const VERDE = '#16a34a';
const NARANJA = '#ea580c';

const dias = (n, color) => `<b class="td-n" style="color:${color}">${nf.format(n)} <small>días</small></b>`;
const clasif = (c) => (c ? `<span class="td-cl" style="--c:${c.color}"><i></i>${esc(c.valor)}</span>` : '<span class="td-cl sin"><i></i>—</span>');
const tag = (titulo, cuerpo, extra = '') => `<div class="td-tag ${extra}"><div class="lbl">${titulo}</div><div class="val">${cuerpo}</div></div>`;
const colorMora = (n, color) => (n > 0 ? color : VERDE);

function cabecera(ref, salud, extra, badge) {
    return `<div class="td-h"><span class="td-dot" style="--c:${salud.color}" title="${esc(salud.nombre)}"></span><b class="td-ref">${esc(ref)}</b>${extra}${badge}</div>`;
}

const badge = (texto, color) => (texto ? `<span class="td-bd" style="--b:${color}">${esc(texto)}</span>` : '<span class="td-bd sin">—</span>');

const ocCliente = (t) => `<span class="td-oc" title="Orden(es) de compra del cliente">OC CLIENTE <b>${t.ocCliente ? esc(t.ocCliente) : '—'}</b></span>`;
const importacion = (t) => (t.importacion ? `<span class="td-im" title="Importación">${esc(t.importacion)}</span>` : '');

function pie(t) {
    const valor = t.valor ? `<b class="td-val">${esc(t.moneda)} ${nf.format(t.valor)}</b>` : '<span></span>';
    const seg = t.seguimiento ?? t.ultimoSeguimiento;

    return `<div class="td-ft"><span title="Fecha de ingreso">&#128197; Ingreso: ${F(t.ingreso)}</span><span class="td-resp" title="Gestor">&#128100; ${esc(t.gestor || '—')}</span>${valor}</div>
        ${seg ? `<div class="td-sg" title="${esc(seg.texto)}">&#128204; <b>${F(seg.fecha)}</b>${seg.autor ? ` · ${esc(seg.autor)}` : ''}</div>` : '<div class="td-sg sin">&#128204; Sin seguimiento</div>'}`;
}

const guia = (g, vacio) => {
    if (!g || !g.numero) return vacio ? '<div class="td-guia sin">Sin guía de despacho</div>' : '';
    return `<div class="td-guia"><span class="td-tr">${esc(g.transporte || 'Guía')}</span><b>${esc(g.numero)}</b></div>`;
};

function solicitud(i, t) {
    const urg = t.urgencia;
    const l = urg?.color ?? GRIS;
    const desde = t.reabierta ?? t.ingreso;

    return `<article class="td solicitud" style="--l:${l};--r:${t.salud.color}" data-modulo="solicitud" data-doc="${i.doc}" tabindex="0" title="Abrir seguimiento">
        ${cabecera(i.ref, t.salud, '', badge(urg?.valor?.toUpperCase(), l))}
        <div class="td-cli">${esc(i.empresa || t.cliente || 'Sin cliente')}</div>
        <div class="td-ds">${esc(t.asunto || i.titulo) || '<em>Sin asunto</em>'}</div>
        <div class="td-tags c2">${tag('Urgencia', clasif(urg))}${tag('Estado', `<span class="td-cl" style="--c:${t.salud.color}"><i></i>${esc(t.salud.nombre)}</span>`)}</div>
        <div class="td-mora"><div><span class="lbl">MORA TOTAL</span><small>desde ${t.reabierta ? 'reapertura' : 'ingreso'} ${F(desde)}</small></div>${dias(t.moraTotal, t.salud.color)}</div>
        ${pie(t)}</article>`;
}

function cotizacion(i, t) {
    const l = t.oportunidad?.color ?? t.urgencia?.color ?? NARANJA;

    return `<article class="td cotizacion" style="--l:${l};--r:${t.salud.color}" data-modulo="cotizacion" data-doc="${i.doc}" tabindex="0" title="Abrir seguimiento">
        ${cabecera(i.ref, t.salud, '', badge(t.urgencia?.valor?.toUpperCase(), t.urgencia?.color ?? GRIS))}
        <div class="td-cli">${esc(i.empresa || 'Sin cliente')}</div>
        <div class="td-ds">${esc(i.titulo) || '<em>Sin título</em>'}</div>
        <div class="td-tags c3">${tag('Urgencia', clasif(t.urgencia))}${tag('Estado', `<span class="td-cl" style="--c:${t.salud.color}"><i></i>${esc(t.salud.nombre)}</span>`)}${tag('Oportunidad', clasif(t.oportunidad))}</div>
        <div class="td-moras">
            <div class="td-mora"><div><span class="lbl">MORA TOTAL</span><small>Desde ingreso</small></div>${dias(t.moraTotal, t.salud.color)}</div>
            <div class="td-mora"><div><span class="lbl">MORA ESTADO ACTUAL</span><small>Desde que está en Cotización</small></div>${dias(t.moraEstado, t.salud.color)}</div>
        </div>
        ${pie(t)}</article>`;
}

function orden(i, t) {
    const l = t.problema?.color ?? VERDE;
    const pago = t.pago === 'pagado' ? badge('PAGADO', VERDE) : badge('PENDIENTE PAGO', NARANJA);

    return `<article class="td orden" style="--l:${l};--r:${t.salud.color}" data-modulo="orden" data-doc="${i.doc}" tabindex="0" title="Abrir seguimiento">
        ${cabecera(i.ref, t.salud, ocCliente(t) + importacion(t), pago)}
        <div class="td-cli">${esc(i.empresa || 'Sin cliente')}</div>
        <div class="td-ds">${esc(i.titulo) || '<em>Sin descripción</em>'}</div>
        <div class="td-cajas">
            <div class="td-caja"><div class="lbl">DESPACHO PROVEEDOR</div><div class="td-par"><span><small>Fecha despacho</small><b>${F(t.despacho.fecha)}</b></span><span><small>Mora proveedor</small>${dias(t.despacho.mora, colorMora(t.despacho.mora, t.despacho.color))}</span></div></div>
            <div class="td-caja"><div class="lbl">ENTREGA CLIENTE (COMPROMISO) <i class="fa-solid fa-handshake" title="Compromiso con el cliente"></i></div><div class="td-par"><span><small>Fecha entrega</small><b>${F(t.entregaCliente.fecha)}</b></span><span><small>Mora cliente</small>${dias(t.entregaCliente.mora, colorMora(t.entregaCliente.mora, t.entregaCliente.color))}</span></div></div>
        </div>
        <div class="td-fila"><div><span class="lbl">MORA ETAPA OC</span>${dias(t.moraEtapa, t.salud.color)}</div><div><span class="lbl">PROBLEMA</span>${clasif(t.problema)}</div><div class="der"><span class="lbl">FACTURADO</span>${t.facturado ? `<b class="td-fact" title="Factura">${esc(t.facturado)}</b>` : '<span class="td-cl sin">—</span>'}</div></div>
        ${guia(t.guia, true)}
        ${pie(t)}</article>`;
}

function entrega(i, t) {
    const l = t.problema?.color ?? VERDE;
    const g = i.guiaFila?.numero ? i.guiaFila : t.guia;

    return `<article class="td entrega" style="--l:${l};--r:${t.salud.color}" data-modulo="orden" data-doc="${i.doc}" tabindex="0" title="Abrir seguimiento">
        ${cabecera(i.ref, t.salud, importacion(t) + ocCliente(t), badge(t.cumplimiento?.valor?.toUpperCase(), t.cumplimiento?.color ?? GRIS))}
        <div class="td-cli">${esc(i.empresa || 'Sin cliente')}</div>
        <div class="td-ds">${esc(i.titulo) || ''}</div>
        <div class="td-caja"><div class="lbl">FECHA ENTREGA CLIENTE <i class="fa-solid fa-handshake" title="Compromiso con el cliente"></i></div>
            <div class="td-par tres"><span><small>Compromiso</small><b class="td-comp">${F(t.entregaCliente.fecha)}</b></span><span><small>Mora cliente</small>${dias(t.entregaCliente.mora, colorMora(t.entregaCliente.mora, t.entregaCliente.color))}</span><span><small>Mora etapa</small>${dias(t.moraEtapa, t.salud.color)}</span></div></div>
        <div class="td-fila"><div><span class="lbl">PROBLEMA</span>${clasif(t.problema)}</div><div class="der"><span class="lbl">FACTURADO</span>${t.facturado ? `<b class="td-fact">${esc(t.facturado)}</b>` : '<span class="td-cl sin">—</span>'}</div></div>
        ${guia(g, false)}
        ${pie(t)}</article>`;
}

/**
 * HTML de la tarjeta de un documento de las listas del dashboard / tablero.
 * @param {object} i  item con ref, empresa, titulo, doc, modulo, tarjeta (datos calculados) y, en entregas, guiaFila
 * @returns {string|null} null si el item no trae datos de tarjeta (se usa la tarjeta simple)
 */
export function tarjetaDocumento(i) {
    const t = i.tarjeta;
    if (!t) return null;
    if (i.modulo === 'solicitud') return solicitud(i, t);
    if (t.tipo === 'cotizacion') return cotizacion(i, t);
    if (t.tipo === 'entrega') return entrega(i, t);
    if (t.tipo === 'orden') return orden(i, t);

    return null;
}
