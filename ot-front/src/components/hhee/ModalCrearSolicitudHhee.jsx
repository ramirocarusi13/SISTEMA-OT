// Alta/edición de una solicitud de HHEE (formulario papel FO-008-RRH):
// cabecera + tabla editable de empleados. Reusable para crear (solicitud=null)
// o editar un borrador propio (solicitud=objeto ya cargado, ver flags.puede_editar
// en components/hhee/ModalDetalleSolicitudHhee.jsx).
//
// Carga simplificada: por empleado solo se pide nombre, motivo, transporte y
// el horario previsto (desde/hasta). Las horas las calcula el backend a
// partir del horario (si "hasta" <= "desde" asume que el turno cruza la
// medianoche automáticamente; "hasta" == "desde" es inválido) — acá se
// espeja ese cálculo en vivo (Utils/hhee.js::calcularHoras) solo para
// mostrarle al usuario cuánto va a quedar, nunca se manda al servidor.
import React, { useEffect, useState } from 'react';
import { Modal, DatePicker, Select, Input, Switch, TimePicker, Button, AutoComplete, Tooltip, message } from 'antd';
import { PlusOutlined, DeleteOutlined, UserOutlined } from '@ant-design/icons';
import moment from 'moment';
import { crearSolicitudHhee, actualizarSolicitudHhee } from '../../Utils/hheeApi';
import { calcularHoras, cruzaMedianocheHhee, formatearHoras, horaCorta, SECTORES_HHEE_FALLBACK } from '../../Utils/hhee';

const { TextArea } = Input;

let contadorFilas = 0;
const nuevaFilaVacia = () => ({
    key: `nueva-${Date.now()}-${contadorFilas++}`,
    id: null,
    nombre: '',
    user_id: null,
    motivo: '',
    necesita_transporte: false,
    localidad: '',
    hora_desde: null,
    hora_hasta: null,
});

const filaDesdeDetalle = (detalle) => ({
    key: `existente-${detalle.id}`,
    id: detalle.id,
    nombre: detalle.nombre || '',
    user_id: detalle.user_id || null,
    motivo: detalle.motivo || '',
    necesita_transporte: !!detalle.necesita_transporte,
    localidad: detalle.localidad || '',
    hora_desde: detalle.hora_desde ? moment(horaCorta(detalle.hora_desde), 'HH:mm') : null,
    hora_hasta: detalle.hora_hasta ? moment(horaCorta(detalle.hora_hasta), 'HH:mm') : null,
});

const ModalCrearSolicitudHhee = ({ open, onClose, solicitud, sectores = SECTORES_HHEE_FALLBACK, usuarios = [], maxHorasPorEmpleado = 12, onSuccess }) => {
    const esEdicion = !!solicitud;
    const [fechaHhee, setFechaHhee] = useState(null);
    const [sector, setSector] = useState(null);
    const [turno, setTurno] = useState('');
    const [observaciones, setObservaciones] = useState('');
    const [filas, setFilas] = useState([nuevaFilaVacia()]);
    const [guardando, setGuardando] = useState(null); // null | 'borrador' | 'enviar'

    // El departamento_id ya NO se elige a mano: el backend lo resuelve del
    // usuario logueado (ver ordenes-sar/app/Http/Controllers/SolicitudHheeController.php,
    // validarPayload() ya no acepta ese campo, se ignora si viaja). Lo único
    // que carga el solicitante es el sector del turno.
    const opcionesSector = sectores.length ? sectores : SECTORES_HHEE_FALLBACK;

    useEffect(() => {
        if (!open) return;

        if (solicitud) {
            setFechaHhee(solicitud.fecha_hhee ? moment(solicitud.fecha_hhee) : null);
            setSector(solicitud.sector || null);
            setTurno(solicitud.turno || '');
            setObservaciones(solicitud.observaciones || '');
            setFilas((solicitud.detalles || []).length > 0
                ? solicitud.detalles.map(filaDesdeDetalle)
                : [nuevaFilaVacia()]);
        } else {
            setFechaHhee(null);
            setSector(null);
            setTurno('');
            setObservaciones('');
            setFilas([nuevaFilaVacia()]);
        }
    }, [open, solicitud]);

    const actualizarFila = (key, campo, valor) => {
        setFilas((prev) => prev.map((fila) => (fila.key === key ? { ...fila, [campo]: valor } : fila)));
    };

    // El nombre y el vínculo a un usuario del sistema (user_id) se actualizan
    // siempre juntos: al elegir de la lista quedan ligados; al tipear libre
    // (o al cambiar el texto de un empleado ya vinculado) se desvincula.
    const actualizarNombreFila = (key, nombre, userId) => {
        setFilas((prev) => prev.map((fila) => (fila.key === key ? { ...fila, nombre, user_id: userId } : fila)));
    };

    // options del AutoComplete de empleados: tolerante a que 'usuarios' venga
    // vacío (todavía sin desplegar en el backend) — en ese caso el campo sigue
    // funcionando como texto libre, sin sugerencias.
    const opcionesUsuarios = usuarios.map((u) => ({ value: u.name, id: u.id }));

    const agregarFila = () => setFilas((prev) => [...prev, nuevaFilaVacia()]);
    const quitarFila = (key) => setFilas((prev) => (prev.length > 1 ? prev.filter((fila) => fila.key !== key) : prev));

    const horasDeFila = (fila) => calcularHoras(
        fila.hora_desde ? fila.hora_desde.format('HH:mm') : null,
        fila.hora_hasta ? fila.hora_hasta.format('HH:mm') : null
    );

    const validar = () => {
        if (!fechaHhee) return 'Debe indicar la fecha de la solicitud.';
        if (!sector) return 'Debe seleccionar un sector.';
        if (filas.length === 0) return 'Debe agregar al menos un empleado.';

        for (let i = 0; i < filas.length; i += 1) {
            const fila = filas[i];
            const numero = i + 1;
            if (!fila.nombre.trim()) return `Fila ${numero}: falta el nombre del empleado.`;
            if (!fila.motivo.trim()) return `Fila ${numero}: falta el motivo.`;
            if (fila.necesita_transporte && !fila.localidad.trim()) return `Fila ${numero}: falta la localidad para el transporte.`;
            if (!fila.hora_desde || !fila.hora_hasta) return `Fila ${numero}: falta el horario previsto.`;

            const horas = horasDeFila(fila);
            if (horas === null) return `Fila ${numero}: la hora de inicio y la de fin no pueden ser iguales.`;
            if (horas > maxHorasPorEmpleado) return `Fila ${numero}: supera el tope de ${maxHorasPorEmpleado} h por empleado.`;
        }

        return null;
    };

    const construirPayload = (enviar) => ({
        fecha_hhee: fechaHhee.format('YYYY-MM-DD'),
        sector,
        turno: turno || null,
        observaciones: observaciones || null,
        enviar,
        detalles: filas.map((fila) => ({
            nombre: fila.nombre.trim(),
            user_id: fila.user_id || null,
            motivo: fila.motivo.trim(),
            necesita_transporte: !!fila.necesita_transporte,
            localidad: fila.necesita_transporte ? fila.localidad.trim() : null,
            hora_desde: fila.hora_desde.format('HH:mm'),
            hora_hasta: fila.hora_hasta.format('HH:mm'),
        })),
    });

    const enviarFormulario = async (enviar) => {
        const errorValidacion = validar();
        if (errorValidacion) {
            message.error(errorValidacion);
            return;
        }

        setGuardando(enviar ? 'enviar' : 'borrador');
        const payload = construirPayload(enviar);
        const resultado = esEdicion
            ? await actualizarSolicitudHhee(solicitud.id, payload)
            : await crearSolicitudHhee(payload);
        setGuardando(null);

        if (resultado.ok) {
            message.success(enviar ? 'Solicitud enviada a aprobación.' : 'Borrador guardado.');
            window.dispatchEvent(new Event('hhee:actualizado'));
            onSuccess?.(resultado.data);
            onClose();
        } else {
            message.error(resultado.error || 'No se pudo guardar la solicitud.');
        }
    };

    const confirmarEnvio = () => {
        Modal.confirm({
            title: 'Enviar a aprobación',
            content: 'Una vez enviada, la solicitud no se podrá editar. ¿Confirma el envío?',
            okText: 'Sí, enviar',
            cancelText: 'Cancelar',
            onOk: () => enviarFormulario(true),
        });
    };

    const totalGeneral = filas.reduce((acc, fila) => acc + (horasDeFila(fila) || 0), 0);

    return (
        <Modal
            title={esEdicion ? `Editar solicitud N° ${solicitud.id}` : 'Nueva solicitud de horas extras'}
            open={open}
            onCancel={onClose}
            footer={null}
            width={1080}
            destroyOnClose
        >
            <div className="hhee-card">
            <div className="hhee-form-grid">
                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-fecha">Fecha prevista</label>
                    <DatePicker
                        id="hhee-fecha"
                        value={fechaHhee}
                        onChange={setFechaHhee}
                        format="DD/MM/YYYY"
                        style={{ width: '100%' }}
                    />
                </div>

                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-sector">Sector</label>
                    <Select
                        id="hhee-sector"
                        placeholder="Seleccione un sector"
                        value={sector}
                        onChange={setSector}
                        options={opcionesSector.map((s) => ({ value: s, label: s }))}
                        style={{ width: '100%' }}
                    />
                </div>

                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-turno">Turno</label>
                    <Input
                        id="hhee-turno"
                        value={turno}
                        onChange={(e) => setTurno(e.target.value)}
                        placeholder="Ej.: Mañana, Tarde, Noche"
                        maxLength={50}
                    />
                </div>
            </div>

            <div className="form-field" style={{ marginTop: 12 }}>
                <label className="form-label" htmlFor="hhee-observaciones">Observaciones</label>
                <TextArea
                    id="hhee-observaciones"
                    rows={2}
                    maxLength={1000}
                    value={observaciones}
                    onChange={(e) => setObservaciones(e.target.value)}
                    placeholder="Observaciones generales de la solicitud (opcional)"
                />
            </div>
            </div>

            <div className="hhee-card">
            <div className="hhee-empleados-header">
                <h3>Empleados</h3>
                <span className="hhee-tope-nota">Tope: {maxHorasPorEmpleado} h por empleado</span>
            </div>

            <div className="hhee-tabla-scroll">
                <table className="hhee-tabla-editable">
                    <thead>
                        <tr>
                            <th style={{ minWidth: 200 }}>Nombre</th>
                            <th style={{ minWidth: 200 }}>Motivo</th>
                            <th style={{ minWidth: 90 }}>Transporte</th>
                            <th style={{ minWidth: 160 }}>Localidad</th>
                            <th style={{ minWidth: 100 }}>Desde</th>
                            <th style={{ minWidth: 100 }}>Hasta</th>
                            <th style={{ minWidth: 150 }}>Horas</th>
                            <th style={{ minWidth: 50 }} />
                        </tr>
                    </thead>
                    <tbody>
                        {filas.map((fila) => {
                            const horas = horasDeFila(fila);
                            const rangoCargado = !!(fila.hora_desde && fila.hora_hasta);
                            const horasInvalidas = rangoCargado && horas === null;
                            const superaTope = horas !== null && horas > maxHorasPorEmpleado;
                            const cruzaMedianoche = rangoCargado && !horasInvalidas && cruzaMedianocheHhee(
                                fila.hora_desde.format('HH:mm'),
                                fila.hora_hasta.format('HH:mm')
                            );

                            return (
                                <tr key={fila.key}>
                                    <td>
                                        <AutoComplete
                                            value={fila.nombre}
                                            options={opcionesUsuarios}
                                            style={{ width: '100%' }}
                                            // Enter nunca pisa lo tipeado con una sugerencia: los operarios
                                            // sin usuario en el sistema se cargan escribiendo el nombre libre.
                                            defaultActiveFirstOption={false}
                                            filterOption={(texto, option) => (option?.value || '').toLowerCase().includes(texto.toLowerCase())}
                                            onSelect={(valor, opcion) => actualizarNombreFila(fila.key, valor, opcion?.id || null)}
                                            onChange={(valor) => {
                                                // Si lo que quedó tipeado ya no coincide con el nombre
                                                // del usuario vinculado, se desvincula (vuelve a ser texto libre).
                                                const usuarioVinculado = usuarios.find((u) => u.id === fila.user_id);
                                                const sigueVinculado = usuarioVinculado && usuarioVinculado.name === valor;
                                                actualizarNombreFila(fila.key, valor, sigueVinculado ? fila.user_id : null);
                                            }}
                                        >
                                            <Input
                                                placeholder="Elegí de la lista o escribí el nombre"
                                                maxLength={150}
                                                suffix={fila.user_id ? (
                                                    <Tooltip title="Vinculado a un usuario del sistema">
                                                        <UserOutlined className="hhee-input-icono-vinculado" />
                                                    </Tooltip>
                                                ) : null}
                                            />
                                        </AutoComplete>
                                    </td>
                                    <td>
                                        <Input
                                            value={fila.motivo}
                                            onChange={(e) => actualizarFila(fila.key, 'motivo', e.target.value)}
                                            placeholder="Motivo"
                                            maxLength={500}
                                        />
                                    </td>
                                    <td style={{ textAlign: 'center' }}>
                                        <Switch
                                            checked={fila.necesita_transporte}
                                            onChange={(checked) => actualizarFila(fila.key, 'necesita_transporte', checked)}
                                        />
                                    </td>
                                    <td>
                                        <Input
                                            value={fila.localidad}
                                            onChange={(e) => actualizarFila(fila.key, 'localidad', e.target.value)}
                                            disabled={!fila.necesita_transporte}
                                            placeholder={fila.necesita_transporte ? 'Localidad' : '—'}
                                            maxLength={150}
                                        />
                                    </td>
                                    <td>
                                        <TimePicker
                                            value={fila.hora_desde}
                                            onChange={(valor) => actualizarFila(fila.key, 'hora_desde', valor)}
                                            format="HH:mm"
                                            minuteStep={5}
                                            style={{ width: '100%' }}
                                        />
                                    </td>
                                    <td>
                                        <TimePicker
                                            value={fila.hora_hasta}
                                            onChange={(valor) => actualizarFila(fila.key, 'hora_hasta', valor)}
                                            format="HH:mm"
                                            minuteStep={5}
                                            style={{ width: '100%' }}
                                        />
                                    </td>
                                    <td className={(horasInvalidas || superaTope) ? 'hhee-total-mismatch' : 'hhee-total-ok'}>
                                        {horasInvalidas ? (
                                            'El desde y el hasta no pueden ser iguales'
                                        ) : horas !== null ? (
                                            <>
                                                {formatearHoras(horas)} h
                                                {cruzaMedianoche && ' (cruza medianoche)'}
                                                {superaTope && ' — supera el tope'}
                                            </>
                                        ) : '—'}
                                    </td>
                                    <td>
                                        <Button
                                            danger
                                            type="text"
                                            icon={<DeleteOutlined />}
                                            onClick={() => quitarFila(fila.key)}
                                            disabled={filas.length === 1}
                                        />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <Button type="dashed" icon={<PlusOutlined />} onClick={agregarFila} className="hhee-agregar-empleado">
                Agregar empleado
            </Button>

            <div className="hhee-total-general">
                <span>{filas.length} empleado{filas.length === 1 ? '' : 's'}</span>
                <strong>Total: {formatearHoras(totalGeneral)} h</strong>
            </div>
            </div>

            <div className="modal-actions">
                <button type="button" className="secondary-action" onClick={onClose}>
                    Cancelar
                </button>
                <button
                    type="button"
                    className="secondary-action"
                    onClick={() => enviarFormulario(false)}
                    disabled={guardando !== null}
                >
                    {guardando === 'borrador' ? 'Guardando...' : 'Guardar borrador'}
                </button>
                <button
                    type="button"
                    className="primary-action"
                    onClick={confirmarEnvio}
                    disabled={guardando !== null}
                >
                    {guardando === 'enviar' ? 'Enviando...' : 'Enviar a aprobación'}
                </button>
            </div>
        </Modal>
    );
};

export default ModalCrearSolicitudHhee;
