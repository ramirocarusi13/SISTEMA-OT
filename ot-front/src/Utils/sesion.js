// Manejo central de la sesión del front.
//
// Problema que resuelve: RutaProtegida solo miraba que EXISTIERA un token en
// localStorage. Con un token vencido se entraba igual a /home (o al link de un
// mail de Open Issues), las ~30 llamadas viejas con fetch directo recibían un
// 401 que nadie manejaba, y la pantalla quedaba vacía o rota en vez de ir al
// login. Además el login siempre mandaba a /home, así que el link original se
// perdía.
//
// Piezas:
//  - cerrarSesionYIrAlLogin(): limpia el token, guarda a dónde quería ir el
//    usuario y lo manda a /login. Único camino para "sesión muerta".
//  - instalarInterceptor401(): envuelve window.fetch UNA vez. Cualquier
//    respuesta 401 de NUESTRA API (salvo el propio login) dispara lo de arriba.
//    Cubre a la vez apiFetch y todos los fetch crudos de los componentes viejos,
//    sin tener que tocarlos uno por uno.
//  - guardarRetorno()/tomarRetorno(): el link original viaja en sessionStorage
//    (sobrevive al window.location.replace, muere al cerrar la pestaña).

const APIURI = import.meta.env.VITE_API || '';
const CLAVE_RETORNO = 'ot:retorno';

/** Solo se acepta como retorno una ruta INTERNA de la app (evita open-redirect). */
export function esRetornoValido(ruta) {
    return typeof ruta === 'string'
        && ruta.startsWith('/')
        && !ruta.startsWith('//')
        && !ruta.startsWith('/login');
}

/** Guarda la ruta (pathname + search + hash) a la que se quiso entrar. */
export function guardarRetorno(ruta) {
    if (!esRetornoValido(ruta)) return;
    try {
        sessionStorage.setItem(CLAVE_RETORNO, ruta);
    } catch {
        // sessionStorage bloqueado (modo privado estricto): se pierde el retorno, no la sesión.
    }
}

/** Devuelve la ruta guardada y la borra (se usa una sola vez, después del login). */
export function tomarRetorno() {
    try {
        const ruta = sessionStorage.getItem(CLAVE_RETORNO);
        sessionStorage.removeItem(CLAVE_RETORNO);
        return esRetornoValido(ruta) ? ruta : null;
    } catch {
        return null;
    }
}

// --- Switch de departamento --------------------------------------------------
// Usuarios con más de un departamento (hoy solo Agustín Otero: Mantenimiento +
// Ingeniería, ver user.departamentos_disponibles que manda el login) eligen
// con cuál trabajar. El elegido viaja en el header X-Departamento-Activo en
// TODOS los requests a nuestra API (lo agrega instalarInterceptor401) y el
// backend lo trata como gerente de ese departamento. Además se pisa
// user.departamento_id en localStorage para que las pantallas muestren la
// vista de ese departamento.
const CLAVE_DEPTO_ACTIVO = 'ot:departamento_activo';

export function getDepartamentoActivo() {
    try {
        return localStorage.getItem(CLAVE_DEPTO_ACTIVO);
    } catch {
        return null;
    }
}

export function limpiarDepartamentoActivo() {
    try {
        localStorage.removeItem(CLAVE_DEPTO_ACTIVO);
    } catch {
        // sin storage: no hay nada que limpiar
    }
}

/** Cambia el departamento activo y recarga para que todas las pantallas lo tomen. */
export function cambiarDepartamentoActivo(departamento) {
    const user = JSON.parse(localStorage.getItem('user') || 'null');
    if (!user) return;

    if (Number(departamento.id) === Number(user.departamento_base_id)) {
        limpiarDepartamentoActivo();
    } else {
        localStorage.setItem(CLAVE_DEPTO_ACTIVO, String(departamento.id));
    }

    localStorage.setItem('user', JSON.stringify({
        ...user,
        departamento_id: departamento.id,
        departamento: { ...(user.departamento || {}), id: departamento.id, nombre: departamento.nombre },
    }));

    window.location.reload();
}

let redirigiendo = false;

/**
 * Sesión muerta (token vencido, revocado o inexistente): limpia y va al login
 * recordando a dónde se quería ir. Idempotente: varias llamadas 401 en paralelo
 * (una pantalla dispara 4 o 5 requests juntos) generan una sola redirección.
 */
export function cerrarSesionYIrAlLogin() {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    limpiarDepartamentoActivo();

    if (window.location.pathname.startsWith('/login')) return;
    if (redirigiendo) return;
    redirigiendo = true;

    guardarRetorno(`${window.location.pathname}${window.location.search}${window.location.hash}`);
    window.location.replace('/login');
}

function urlDe(input) {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    return input?.url || '';
}

/**
 * Envuelve window.fetch para detectar el 401 de nuestra API en CUALQUIER llamada.
 * No altera la respuesta: cada caller sigue recibiendo lo mismo que antes.
 */
export function instalarInterceptor401() {
    if (window.__otInterceptor401) return;
    window.__otInterceptor401 = true;

    const fetchOriginal = window.fetch.bind(window);

    window.fetch = async (input, init) => {
        const deptoActivo = getDepartamentoActivo();
        const urlPedido = urlDe(input);
        if (deptoActivo && APIURI !== '' && urlPedido.startsWith(APIURI) && !urlPedido.startsWith(`${APIURI}login`)) {
            const headers = new Headers(init?.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-Departamento-Activo', deptoActivo);
            init = { ...init, headers };
        }

        const respuesta = await fetchOriginal(input, init);

        if (respuesta.status === 401) {
            const url = urlDe(input);
            const esNuestraApi = APIURI !== '' && url.startsWith(APIURI);
            // El 401 del propio login es "usuario o contraseña incorrectos",
            // no una sesión vencida: ese lo muestra el formulario.
            const esLogin = url.startsWith(`${APIURI}login`);
            if (esNuestraApi && !esLogin) {
                cerrarSesionYIrAlLogin();
            }
        }

        return respuesta;
    };
}

// Token ya verificado contra el backend en esta carga de la app: evita repetir
// el GET /user en cada cambio de página (Home -> Reportes -> Open Issues).
let tokenVerificado = null;

/**
 * Verifica contra el backend que el token siga vivo. Devuelve:
 *  'ok'      -> sesión válida
 *  'vencida' -> 401 (el interceptor ya está redirigiendo al login)
 *  'sin_red' -> no se pudo consultar: se deja pasar y cada pantalla muestra su
 *               propio error (no se expulsa a nadie porque el servidor esté caído)
 */
export async function verificarSesion(token) {
    if (!token) return 'vencida';
    if (tokenVerificado === token) return 'ok';

    try {
        const respuesta = await fetch(`${APIURI}user`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (respuesta.status === 401) return 'vencida';
        if (respuesta.ok) {
            tokenVerificado = token;
            return 'ok';
        }
        return 'sin_red';
    } catch {
        return 'sin_red';
    }
}
