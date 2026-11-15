// Modal de ficha del usuario (solo admin). El comportamiento común de los
// pasos vive en FichaModal; aquí: buscador y contadores de permisos,
// ayudas de contraseña y la cabecera.
import { FichaModal, normalizar, toast } from '../panel/ficha-modal.js';

const NIVELES_FUERZA = [
    ['w-1/4', 'bg-destructive', 'Débil'],
    ['w-2/4', 'bg-amber-400', 'Aceptable'],
    ['w-3/4', 'bg-sky-500', 'Buena'],
    ['w-full', 'bg-emerald-500', 'Fuerte'],
];

function fuerza(clave) {
    if (!clave) return -1;
    let puntos = 0;
    if (clave.length >= 8) puntos++;
    if (clave.length >= 12) puntos++;
    if (/[a-z]/.test(clave) && /[A-Z]/.test(clave)) puntos++;
    if (/\d/.test(clave) && /[^A-Za-z0-9]/.test(clave)) puntos++;
    return clave.length < 8 ? 0 : Math.min(3, Math.max(0, puntos - 1));
}

function generarClave() {
    const grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%*?-_'];
    const aleatorio = (n) => crypto.getRandomValues(new Uint32Array(1))[0] % n;
    const chars = grupos.map((g) => g[aleatorio(g.length)]);
    const todos = grupos.join('');
    while (chars.length < 14) chars.push(todos[aleatorio(todos.length)]);
    for (let i = chars.length - 1; i > 0; i--) {
        const j = aleatorio(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}

export class UsuarioFichaModal extends FichaModal {
    constructor(opts) {
        super({ etiqueta: 'el usuario', ...opts });
    }

    inicializarExtras() {
        this.inicializarPermisos();
        this.inicializarPassword();
    }

    inicializarPermisos() {
        const form = this.root.querySelector('form[data-step="permisos"]');
        if (!form) return;

        form.querySelector('[data-filtro-permisos]')?.addEventListener('input', (e) => {
            const q = normalizar(e.target.value);
            form.querySelectorAll('[data-modulo]').forEach((mod) => {
                const nombreModulo = normalizar(mod.querySelector('[data-modulo-nombre]').textContent);
                let visibles = 0;
                mod.querySelectorAll('[data-permiso]').forEach((p) => {
                    const ok = !q || nombreModulo.includes(q) || normalizar(p.querySelector('[data-permiso-texto]').textContent).includes(q);
                    p.classList.toggle('hidden', !ok);
                    if (ok) visibles++;
                });
                mod.classList.toggle('hidden', visibles === 0);
                mod.open = Boolean(q) && visibles > 0;
            });
            this.contarPermisos();
        });

        // Marcar / desmarcar todos: con un filtro activo solo afecta a los permisos visibles.
        form.querySelectorAll('[data-permisos-todos]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const marcar = btn.dataset.permisosTodos === '1';
                this.marcarPermisos(this.checksVisibles(form), marcar);
            });
        });

        // Por módulo: el botón del encabezado marca todo si falta alguno; si ya están todos, los desmarca.
        form.querySelectorAll('[data-modulo-toggle]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault(); // que el clic no abra/cierre el <details>
                const checks = this.checksVisibles(btn.closest('[data-modulo]'));
                this.marcarPermisos(checks, checks.some((c) => !c.checked));
            });
        });

        form.addEventListener('change', () => this.contarPermisos());
        this.contarPermisos();
    }

    checksVisibles(contenedor) {
        return [...contenedor.querySelectorAll('[data-permiso]:not(.hidden) input[name="permisos[]"]')];
    }

    marcarPermisos(checks, marcar) {
        if (!checks.length) return;
        checks.forEach((c) => { c.checked = marcar; });
        // Un solo evento para refrescar contadores y el estado del paso.
        checks[0].dispatchEvent(new Event('change', { bubbles: true }));
    }

    contarPermisos() {
        const form = this.root.querySelector('form[data-step="permisos"]');
        if (!form) return;
        form.querySelectorAll('[data-modulo]').forEach((mod) => {
            const total = mod.querySelectorAll('input[name="permisos[]"]').length;
            const marcados = mod.querySelectorAll('input[name="permisos[]"]:checked').length;
            const conteo = mod.querySelector('[data-modulo-conteo]');
            conteo.textContent = `${marcados} / ${total}`;
            conteo.classList.toggle('bg-primary/15', marcados > 0);
            conteo.classList.toggle('text-primary', marcados > 0);

            const icono = mod.querySelector('[data-modulo-toggle] i');
            if (icono) {
                icono.className = marcados === 0 ? 'fa-regular fa-square text-muted-foreground'
                    : marcados === total ? 'fa-solid fa-square-check text-primary'
                        : 'fa-solid fa-square-minus text-primary';
            }
        });

        const todos = form.querySelectorAll('input[name="permisos[]"]');
        const marcados = form.querySelectorAll('input[name="permisos[]"]:checked').length;
        const filtrando = Boolean(form.querySelector('[data-filtro-permisos]')?.value.trim());
        const visibles = this.checksVisibles(form).length;
        const resumen = form.querySelector('[data-permisos-resumen]');
        if (resumen) {
            resumen.textContent = `${marcados} de ${todos.length} permisos marcados`
                + (filtrando ? ` · ${visibles} visibles con el filtro` : '');
        }
        form.querySelectorAll('[data-permisos-todos] [data-texto]').forEach((t) => {
            const marcar = t.closest('[data-permisos-todos]').dataset.permisosTodos === '1';
            t.textContent = filtrando ? `${marcar ? 'Marcar' : 'Desmarcar'} visibles (${visibles})` : `${marcar ? 'Marcar' : 'Desmarcar'} todos`;
        });
    }

    inicializarPassword() {
        const form = this.root.querySelector('form[data-step="password"]');
        if (!form) return;
        const clave = form.querySelector('[name="password"]');
        const confirmacion = form.querySelector('[name="password_confirmation"]');
        const barra = form.querySelector('[data-fuerza]');
        const texto = form.querySelector('[data-fuerza-texto]');

        const pintar = () => {
            const nivel = fuerza(clave.value);
            barra.className = 'h-full rounded-full transition-all';
            if (nivel < 0) {
                barra.classList.add('w-0');
                texto.textContent = 'Mínimo 8 caracteres.';
                return;
            }
            const [ancho, color, etiqueta] = NIVELES_FUERZA[nivel];
            barra.classList.add(ancho, color);
            texto.textContent = clave.value.length < 8 ? `Faltan ${8 - clave.value.length} caracteres.` : `Seguridad: ${etiqueta}`;
        };
        clave.addEventListener('input', pintar);
        form.addEventListener('reset', () => setTimeout(pintar));

        form.querySelector('[data-ver-password]')?.addEventListener('click', (e) => {
            const mostrar = clave.type === 'password';
            clave.type = mostrar ? 'text' : 'password';
            confirmacion.type = clave.type;
            e.currentTarget.innerHTML = `<i class="fa-regular ${mostrar ? 'fa-eye-slash' : 'fa-eye'}"></i>`;
        });

        form.querySelector('[data-generar-password]')?.addEventListener('click', async () => {
            const nueva = generarClave();
            clave.value = nueva;
            confirmacion.value = nueva;
            clave.type = 'text';
            confirmacion.type = 'text';
            pintar();
            this.actualizarEstado(form);
            try {
                await navigator.clipboard.writeText(nueva);
                toast.fire({ icon: 'success', title: 'Contraseña generada y copiada' });
            } catch {
                toast.fire({ icon: 'info', title: 'Contraseña generada' });
            }
        });
    }

    alDescartar(form) {
        if (form.dataset.step === 'permisos') this.contarPermisos();
    }

    actualizarCabecera({ usuario }) {
        if (!usuario) return;
        const titulo = this.root.querySelector('[data-ficha-titulo]');
        if (titulo) titulo.textContent = usuario.nombre;
        if (usuario.login) {
            const btn = this.root.querySelector('[data-ficha-login]');
            if (btn) btn.dataset.copy = usuario.login;
            const txt = this.root.querySelector('[data-ficha-login-texto]');
            if (txt) txt.textContent = usuario.login;
        }
        this.pintarEstado(usuario.statut === '1');
        this.root.querySelector('[data-ficha-admin]')?.classList.toggle('hidden', usuario.admin !== '1');

        const grupos = this.root.querySelectorAll('form[data-step="grupos"] input[name="grupos[]"]:checked').length;
        const kpi = this.root.querySelector('[data-kpi-grupos]');
        if (kpi) kpi.textContent = grupos;
        const sub = this.root.querySelector('[data-step-target="grupos"] [data-step-sub]');
        if (sub) sub.dataset.default = `${grupos} asignado${grupos === 1 ? '' : 's'}`;
        const directos = this.root.querySelectorAll('form[data-step="permisos"] input[name="permisos[]"]:checked').length;
        const subPermisos = this.root.querySelector('[data-step-target="permisos"] [data-step-sub]');
        if (subPermisos) subPermisos.dataset.default = `${directos} directos`;
    }
}
