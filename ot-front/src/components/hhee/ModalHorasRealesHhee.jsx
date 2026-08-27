// Carga de horas reales de una solicitud APROBADA (§FO-008-RRH): por cada
// empleado se precargan las horas teóricas como default editable, más la
// fecha en que se realizaron las horas (default = fecha_hhee de la cabecera).
import React, { useEffect, useState } from 'react';
import { Modal, Table, InputNumber, DatePicker, message } from 'antd';
import moment from 'moment';
import { cargarHorasRealesHhee } from '../../Utils/hheeApi';
import { formatearHoras } from '../../Utils/hhee';

const ModalHorasRealesHhee = ({ open, solicitud, onClose, onSuccess }) => {
    const [filas, setFilas] = useState([]);
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        if (open && solicitud) {
            const fechaDefault = solicitud.fecha_hhee ? moment(solicitud.fecha_hhee) : moment();
            setFilas(
                (solicitud.detalles || []).map((detalle) => ({
                    detalle_id: detalle.id,
                    nombre: detalle.nombre,
                    legajo: detalle.legajo,
                    hs_reales_50: Number(detalle.hs_reales_50) || Number(detalle.hs_teoricas_50) || 0,
                    hs_reales_100: Number(detalle.hs_reales_100) || Number(detalle.hs_teoricas_100) || 0,
                    hs_reales_50n: Number(detalle.hs_reales_50n) || Number(detalle.hs_teoricas_50n) || 0,
                    hs_reales_100n: Number(detalle.hs_reales_100n) || Number(detalle.hs_teoricas_100n) || 0,
                    fecha_realizacion: detalle.fecha_realizacion ? moment(detalle.fecha_realizacion) : fechaDefault,
                }))
            );
        }
    }, [open, solicitud]);

    const actualizarFila = (detalleId, campo, valor) => {
        setFilas((prev) => prev.map((fila) => (fila.detalle_id === detalleId ? { ...fila, [campo]: valor } : fila)));
    };

    const totalFila = (fila) =>
        (Number(fila.hs_reales_50) || 0) +
        (Number(fila.hs_reales_100) || 0) +
        (Number(fila.hs_reales_50n) || 0) +
        (Number(fila.hs_reales_100n) || 0);

    const totalGeneral = filas.reduce((acc, fila) => acc + totalFila(fila), 0);

    const handleGuardar = async () => {
        const faltaFecha = filas.some((fila) => !fila.fecha_realizacion);
        if (faltaFecha) {
            message.error('Todos los empleados deben tener fecha de realización.');
            return;
        }

        setEnviando(true);
        const payload = {
            detalles: filas.map((fila) => ({
                detalle_id: fila.detalle_id,
                hs_reales_50: Number(fila.hs_reales_50) || 0,
                hs_reales_100: Number(fila.hs_reales_100) || 0,
                hs_reales_50n: Number(fila.hs_reales_50n) || 0,
                hs_reales_100n: Number(fila.hs_reales_100n) || 0,
                fecha_realizacion: fila.fecha_realizacion.format('YYYY-MM-DD'),
            })),
        };

        const { ok, error } = await cargarHorasRealesHhee(solicitud.id, payload);
        setEnviando(false);

        if (ok) {
            message.success('Horas reales cargadas. La solicitud quedó cerrada.');
            onSuccess?.();
            onClose();
        } else {
            message.error(error || 'No se pudieron cargar las horas reales.');
        }
    };

    const columnaHoras = (campo, titulo) => ({
        title: titulo,
        key: campo,
        width: 110,
        render: (_, fila) => (
            <InputNumber
                min={0}
                max={24}
                step={0.5}
                style={{ width: '100%' }}
                value={fila[campo]}
                onChange={(valor) => actualizarFila(fila.detalle_id, campo, valor)}
            />
        ),
    });

    const columns = [
        { title: 'Empleado', dataIndex: 'nombre', key: 'nombre', fixed: 'left', width: 180 },
        { title: 'Legajo', dataIndex: 'legajo', key: 'legajo', width: 90, render: (v) => v || '—' },
        columnaHoras('hs_reales_50', '50%'),
        columnaHoras('hs_reales_100', '100%'),
        columnaHoras('hs_reales_50n', '50% noct.'),
        columnaHoras('hs_reales_100n', '100% noct.'),
        {
            title: 'Total',
            key: 'total',
            width: 90,
            render: (_, fila) => <strong>{formatearHoras(totalFila(fila))} h</strong>,
        },
        {
            title: 'Fecha realización',
            key: 'fecha_realizacion',
            width: 160,
            render: (_, fila) => (
                <DatePicker
                    value={fila.fecha_realizacion}
                    format="DD/MM/YYYY"
                    onChange={(fecha) => actualizarFila(fila.detalle_id, 'fecha_realizacion', fecha)}
                    style={{ width: '100%' }}
                />
            ),
        },
    ];

    return (
        <Modal
            title={solicitud ? `Cargar horas reales — Solicitud N° ${solicitud.id}` : 'Cargar horas reales'}
            open={open}
            onCancel={onClose}
            onOk={handleGuardar}
            okText="Guardar horas reales"
            cancelText="Cancelar"
            confirmLoading={enviando}
            width={900}
            destroyOnClose
        >
            <Table
                dataSource={filas}
                columns={columns}
                rowKey="detalle_id"
                pagination={false}
                size="small"
                scroll={{ x: 'max-content' }}
            />
            <div className="hhee-total-general">
                <span>{filas.length} empleado{filas.length === 1 ? '' : 's'}</span>
                <strong>Total real: {formatearHoras(totalGeneral)} h</strong>
            </div>
        </Modal>
    );
};

export default ModalHorasRealesHhee;
