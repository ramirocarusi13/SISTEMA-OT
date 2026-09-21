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

let redirigiendo = false;

/**
 * Sesión muerta (token vencido, revocado o inexistente): limpia y va al login
 * recordando a dónde se quería ir. Idempotente: varias llamadas 401 en paralelo
 * (una pantalla dispara 4 o 5 requests juntos) generan una sola redirección.
 */
export function cerrarSesionYIrAlLogin() {
    localStorage.removeItem('token');
    localStorage.removeItem('user');

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
