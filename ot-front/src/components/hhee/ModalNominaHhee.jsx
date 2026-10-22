// Carga masiva de HHEE desde la nómina de RRHH: se elige un sector, se tildan
// los empleados (o "todos") y se les aplica el mismo motivo y horario. Las
// filas resultantes se suman a la tabla de ModalCrearSolicitudHhee, donde
// todavía se pueden ajustar una por una antes de guardar.
import React, { useEffect, useMemo, useState } from 'react';
import { Modal, Select, Input, Table, TimePicker, Switch, Alert, Empty, message } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { calcularHoras, formatearHoras, normalizarTexto } from '../../Utils/hhee';

const TODOS = '__todos__';

let contadorNomina = 0;

const ModalNominaHhee = ({
    open,
    onClose,
    empleados = [],
    cargando = false,
    disponible = true,
    sectorSugerido = null,
    motivos = [],
    legajosCargados = [],
    nombresCargados = [],
    maxHorasPorEmpleado = 12,
    onAgregar,
}) => {
    const [departamento, setDepartamento] = useState(TODOS);
    const [busqueda, setBusqueda] = useState('');
    const [seleccion, setSeleccion] = useState([]);
    const [motivo, setMotivo] = useState(null);
    const [horaDesde, setHoraDesde] = useState(null);
    const [horaHasta, setHoraHasta] = useState(null);
    const [transporte, setTransporte] = useState(false);
    const [localidad, setLocalidad] = useState('');

    // Departamentos de RRHH con cantidad de empleados, para el desplegable.
    const departamentos = useMemo(() => {
        const conteo = new Map();
        empleados.forEach((e) => conteo.set(e.departamento, (conteo.get(e.departamento) || 0) + 1));
        return [...conteo.entries()]
            .sort((a, b) => a[0].localeCompare(b[0], 'es'))
            .map(([nombre, cantidad]) => ({ nombre, cantidad }));
    }, [empleados]);

    // Al abrir: arranca en el departamento de RRHH que coincide con el sector
    // elegido en la solicitud (sin importar mayúsculas/acentos), si existe.
    useEffect(() => {
        if (!open) return;
        const coincide = departamentos.find((d) => normalizarTexto(d.nombre) === normalizarTexto(sectorSugerido));
        setDepartamento(coincide ? coincide.nombre : TODOS);
        setBusqueda('');
        setSeleccion([]);
        setMotivo(null);
        setHoraDesde(null);
        setHoraHasta(null);
        setTransporte(false);
        setLocalidad('');
    }, [open, sectorSugerido, departamentos]);

    const legajosYa = useMemo(() => new Set(legajosCargados.filter(Boolean).map(String)), [legajosCargados]);
    const nombresYa = useMemo(() => new Set(nombresCargados.map(normalizarTexto).filter(Boolean)), [nombresCargados]);

    const yaCargado = (empleado) => (empleado.legajo
        ? legajosYa.has(String(empleado.legajo))
        : nombresYa.has(normalizarTexto(empleado.nombre)));

    const claveEmpleado = (empleado) => `${empleado.legajo || 's/l'}|${empleado.nombre}|${empleado.departamento}`;

    const visibles = useMemo(() => {
        const texto = normalizarTexto(busqueda);
        return empleados.filter((e) => {
            if (departamento !== TODOS && e.departamento !== departamento) return false;
            if (!texto) return true;
            return normalizarTexto(e.nombre).includes(texto)
                || normalizarTexto(e.legajo).includes(texto)
                || normalizarTexto(e.puesto).includes(texto);
        });
    }, [empleados, departamento, busqueda]);

    const horas = calcularHoras(
        horaDesde ? horaDesde.format('HH:mm') : null,
        horaHasta ? horaHasta.format('HH:mm') : null
    );

    const agregar = () => {
        if (seleccion.length === 0) return message.error('Tildá al menos un empleado.');
        if (!motivo) return message.error('Elegí el motivo.');
        if (!horaDesde || !horaHasta) return message.error('Completá el horario (desde y hasta).');
        if (horas === null) return message.error('La hora de inicio y la de fin no pueden ser iguales.');
        if (horas > maxHorasPorEmpleado) return message.error(`Supera el tope de ${maxHorasPorEmpleado} h por empleado.`);
        if (transporte && !localidad.trim()) return message.error('Indicá la localidad para el transporte.');

        const elegidos = empleados.filter((e) => seleccion.includes(claveEmpleado(e)));
        const filas = elegidos.map((e) => ({
            key: `nomina-${Date.now()}-${contadorNomina++}`,
            id: null,
            legajo: e.legajo || null,
            nombre: e.nombre,
            user_id: null,
            motivo,
            necesita_transporte: transporte,
            localidad: transporte ? localidad.trim() : '',
            hora_desde: horaDesde,
            hora_hasta: horaHasta,
        }));

        onAgregar?.(filas);
        message.success(`${filas.length} empleado${filas.length === 1 ? '' : 's'} agregado${filas.length === 1 ? '' : 's'}.`);
        onClose();
    };

    const columnas = [
        { title: 'Legajo', dataIndex: 'legajo', key: 'legajo', width: 90, render: (v) => v || '—' },
        { title: 'Apellido y nombre', dataIndex: 'nombre', key: 'nombre' },
        { title: 'Puesto', dataIndex: 'puesto', key: 'puesto', render: (v) => v || '—' },
        ...(departamento === TODOS ? [{ title: 'Sector', dataIndex: 'departamento', key: 'departamento' }] : []),
        {
            title: '',
            key: 'estado',
            width: 110,
            render: (_, e) => (yaCargado(e) ? <span className="hhee-nomina-ya">Ya en la lista</span> : null),
        },
    ];

    const seleccionables = visibles.filter((e) => !yaCargado(e));

    return (
        <Modal
            title="Agregar empleados desde la nómina"
            open={open}
            onCancel={onClose}
            onOk={agregar}
            okText={seleccion.length > 0 ? `Agregar ${seleccion.length} empleado${seleccion.length === 1 ? '' : 's'}` : 'Agregar'}
            okButtonProps={{ disabled: seleccion.length === 0 }}
            cancelText="Cancelar"
            width={900}
            destroyOnClose
        >
            {!cargando && !disponible && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="La nómina de RRHH no está disponible en este momento"
                    description="Podés seguir cargando los empleados a mano desde la tabla de la solicitud."
                />
            )}

            <div className="hhee-nomina-filtros">
                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-nomina-sector">Sector (nómina RRHH)</label>
                    <Select
                        id="hhee-nomina-sector"
                        value={departamento}
                        onChange={(valor) => { setDepartamento(valor); setSeleccion([]); }}
                        options={[
                            { value: TODOS, label: `Todos los sectores (${empleados.length})` },
                            ...departamentos.map((d) => ({ value: d.nombre, label: `${d.nombre} (${d.cantidad})` })),
                        ]}
                        style={{ width: '100%' }}
                        showSearch
                        optionFilterProp="label"
                    />
                </div>
                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-nomina-buscar">Buscar</label>
                    <Input
                        id="hhee-nomina-buscar"
                        allowClear
                        prefix={<SearchOutlined />}
                        placeholder="Nombre, legajo o puesto"
                        value={busqueda}
                        onChange={(e) => setBusqueda(e.target.value)}
                    />
                </div>
            </div>

            <Table
                className="hhee-nomina-tabla"
                size="small"
                loading={cargando}
                dataSource={visibles}
                columns={columnas}
                rowKey={claveEmpleado}
                pagination={false}
                scroll={{ y: 300 }}
                locale={{ emptyText: <Empty description="No hay empleados para mostrar" /> }}
                rowSelection={{
                    selectedRowKeys: seleccion,
                    onChange: (claves) => setSeleccion(claves),
                    getCheckboxProps: (e) => ({ disabled: yaCargado(e) }),
                    preserveSelectedRowKeys: true,
                }}
            />
            <div className="hhee-nomina-seleccion">
                <span>
                    {seleccion.length} seleccionado{seleccion.length === 1 ? '' : 's'}
                    {' · '}
                    {seleccionables.length} disponible{seleccionables.length === 1 ? '' : 's'} en la vista
                </span>
            </div>

            <div className="hhee-card hhee-nomina-comunes">
                <h4>Datos para todos los seleccionados</h4>
                <div className="hhee-nomina-comunes-grid">
                    <div className="form-field">
                        <label className="form-label" htmlFor="hhee-nomina-motivo">Motivo</label>
                        <Select
                            id="hhee-nomina-motivo"
                            placeholder="Elegí el motivo"
                            value={motivo}
                            onChange={setMotivo}
                            options={motivos.map((m) => ({ value: m, label: m }))}
                            style={{ width: '100%' }}
                        />
                    </div>
                    <div className="form-field">
                        <label className="form-label" htmlFor="hhee-nomina-desde">Desde</label>
                        <TimePicker id="hhee-nomina-desde" value={horaDesde} onChange={setHoraDesde} format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
                    </div>
                    <div className="form-field">
                        <label className="form-label" htmlFor="hhee-nomina-hasta">Hasta</label>
                        <TimePicker id="hhee-nomina-hasta" value={horaHasta} onChange={setHoraHasta} format="HH:mm" minuteStep={5} style={{ width: '100%' }} />
                    </div>
                    <div className="form-field">
                        <label className="form-label">Horas c/u</label>
                        <div className={horas !== null && horas > maxHorasPorEmpleado ? 'hhee-total-mismatch' : 'hhee-total-ok'} style={{ paddingTop: 6 }}>
                            {horas !== null ? `${formatearHoras(horas)} h` : '—'}
                        </div>
                    </div>
                    <div className="form-field">
                        <label className="form-label" htmlFor="hhee-nomina-transporte">Transporte</label>
                        <div>
                            <Switch id="hhee-nomina-transporte" checked={transporte} onChange={setTransporte} />
                        </div>
                    </div>
                    <div className="form-field">
                        <label className="form-label" htmlFor="hhee-nomina-localidad">Localidad</label>
                        <Input
                            id="hhee-nomina-localidad"
                            value={localidad}
                            onChange={(e) => setLocalidad(e.target.value)}
                            disabled={!transporte}
                            placeholder={transporte ? 'Localidad' : '—'}
                            maxLength={150}
                        />
                    </div>
                </div>
            </div>
        </Modal>
    );
};

export default ModalNominaHhee;
