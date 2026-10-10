/**
 * Visibilidad de contraseñas para toda la tienda.
 *
 * Se aplica automáticamente a cada campo <input type="password"> de la pagina:
 * le inserta un boton de ojo que alterna entre oculto y visible.
 *
 * Reglas:
 *   - Si el campo ya tiene un boton propio (por ejemplo el de crear cliente
 *     en el panel admin), no se toca, para no duplicar iconos.
 *   - Un campo con el atributo data-no-toggle queda fuera.
 *   - Al enviar el formulario, las claves se ocultan de nuevo.
 */
(function () {
    'use strict';

    const CLASE_BOTON = 'toggle-pass';
    const CLASE_MARCADO = 'data-pass-toggle';

    // Estilos del boton. Se inyectan una sola vez para no depender del CSS
    // de cada pagina.
    function inyectarEstilos() {
        if (document.getElementById('estilos-toggle-pass')) return;

        const estilo = document.createElement('style');
        estilo.id = 'estilos-toggle-pass';
        estilo.textContent =
            '.' + CLASE_BOTON + '{' +
                'position:absolute;right:12px;top:50%;transform:translateY(-50%);' +
                'cursor:pointer;font-size:1.15rem;line-height:1;user-select:none;' +
                'padding:6px;border-radius:8px;transition:background .2s,transform .2s;' +
            '}' +
            '.' + CLASE_BOTON + ':hover{background:rgba(67,97,238,.12);transform:translateY(-50%) scale(1.08);}' +
            '.' + CLASE_BOTON + ':focus-visible{outline:2px solid var(--primary,#4361ee);outline-offset:1px;}' +
            '.' + CLASE_BOTON + '.activo{color:var(--primary,#4361ee);}' +
            '.pass-wrapper{position:relative;display:flex;align-items:center;}' +
            '.pass-wrapper > input[type="password"],' +
            '.pass-wrapper > input[data-pass-visible]{padding-right:46px !important;}';
        document.head.appendChild(estilo);
    }

    /** El campo ya trae su propio boton: no hacemos nada. */
    function yaTieneBoton(input) {
        return !!input.closest('.pass-wrapper') ||
               !!input.parentElement.querySelector('.' + CLASE_BOTON);
    }

    function crearBoton(input) {
        const boton = document.createElement('span');
        boton.className = CLASE_BOTON;
        boton.setAttribute('role', 'button');
        boton.setAttribute('tabindex', '0');
        boton.setAttribute('aria-label', 'Mostrar contraseña');
        boton.setAttribute('title', 'Mostrar contraseña');
        boton.textContent = '\u{1F441}\uFE0F';

        const alternar = function () {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            input.setAttribute('data-pass-visible', visible ? '' : '1');
            boton.textContent = visible ? '\u{1F441}\uFE0F' : '\u{1F648}';
            boton.classList.toggle('activo', !visible);
            const texto = visible ? 'Mostrar contraseña' : 'Ocultar contraseña';
            boton.setAttribute('aria-label', texto);
            boton.setAttribute('title', texto);
            boton.setAttribute('aria-pressed', visible ? 'false' : 'true');
        };

        boton.addEventListener('click', alternar);
        boton.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' || evento.key === ' ') {
                evento.preventDefault();
                alternar();
            }
        });

        return boton;
    }

    function preparar(campo) {
        if (campo.hasAttribute(CLASE_MARCADO)) return;
        if (campo.getAttribute('data-no-toggle') !== null) return;
        if (yaTieneBoton(campo)) return;

        campo.setAttribute(CLASE_MARCADO, '1');

        const padre = campo.parentElement;
        if (!padre) return;

        // Envolver solo si el padre no es el contenedor que posiciona el campo.
        if (getComputedStyle(padre).position === 'static') {
            const envoltorio = document.createElement('div');
            envoltorio.className = 'pass-wrapper';
            padre.insertBefore(envoltorio, campo);
            envoltorio.appendChild(campo);
        }

        campo.parentElement.appendChild(crearBoton(campo));
    }

    function aplicar() {
        inyectarEstilos();
        document.querySelectorAll('input[type="password"]').forEach(preparar);
    }

    // Al enviar cualquier formulario, las claves vuelven a quedar ocultas
    // para que no queden a la vista en la pantalla siguiente.
    document.addEventListener('submit', function () {
        document.querySelectorAll('input[data-pass-visible]').forEach(function (campo) {
            campo.type = 'password';
            campo.removeAttribute('data-pass-visible');
            const boton = campo.parentElement.querySelector('.' + CLASE_BOTON);
            if (boton) {
                boton.textContent = '\u{1F441}\uFE0F';
                boton.classList.remove('activo');
                boton.setAttribute('aria-label', 'Mostrar contraseña');
                boton.setAttribute('title', 'Mostrar contraseña');
            }
        });
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', aplicar);
    } else {
        aplicar();
    }

    // Expuesto para paginas que agregan campos despues (modales del panel).
    window.aplicarTogglePassword = aplicar;
})();