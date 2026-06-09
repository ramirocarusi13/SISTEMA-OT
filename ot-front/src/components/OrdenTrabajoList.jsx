import { Button, DatePicker, Input, Modal, notification, Select, Table } from 'antd';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons';
import moment from 'moment';
import React, { useEffect, useState } from 'react';
import { FaPaperPlane, FaTrash } from 'react-icons/fa';
import ModalCrearOrdenTrabajo from '../components/ModalCrearOrdenTrabajo';
import { getItem } from '../storage/UserAsyncStorage';
import ImprimirOrden from './ImprimirOrden';
import ModalDescripcion from './ModalDescripcion';
import { departamentosArr } from '../Utils/Departamentos';
import ModalFinalizarOrdenTrabajo from '../components/ModalFinalizarOrdenTrabajo';
import ModalMostrarFotoFinalizada from './ModalMostrarFotoFinalizada';
import ModalAgregarArchivos from './ModalAgregarArchivos';


import LoadingIcon from './LoadingIcon';

const APIURI = import.meta.env.VITE_API




const OrdenTrabajoList = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isModalOpenModalAgregar, setIsModalOpenModalAgregar] = useState(false);
    const [selectedOrdenIdAgregar, setSelectedOrdenIdAgregar] = useState(null);
    const [selectedOrderId, setSelectedOrderId] = useState(null);
    const [ordenes, setOrdenes] = useState([]);
    const [ordenesSinFiltro, setOrdenesSinFiltro] = useState([]);
    const [selectedDepartamento, setSelectedDepartamento] = useState(null);
    const [selectedUsuarioMantenimiento, setSelectedUsuarioMantenimiento] = useState(null);
    const [selectedDateRange, setSelectedDateRange] = useState([]);
    const [departamentos, setDepartamentos] = useState([]);
    const [file, setFile] = useState(null);
    const [loading, setIsLoading] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const [isOpenCrearOrden, setIsOpenCrearOrden] = useState(false);
    const [isOpenDetalleModal, setIsOpenDetalleModal] = useState(false);
    const [isOpenMensajesModal, setIsOpenMensajesModal] = useState(false);
    const [idOrden, setIdOrden] = useState(null);
    const [usuario, setUsuario] = useState(null);
    const [isModalEliminarOpen, setIsModalEliminarOpen] = useState(false);
    const [mensajeFinalizacion, setMensajeFinalizacion] = useState("");
    const [ordenIdEliminar, setOrdenIdEliminar] = useState(null);
    const [mensajeCambio, setMensajeCambio] = useState('');
    const [ordenIdCambio, setOrdenIdCambio] = useState(null);
    const [mensajes, setMensajes] = useState([]);
    const [visibleTable, setVisibleTable] = useState(null);
    const [ordenIdMensajes, setOrdenIdMensajes] = useState(null);
    const [user, setUser] = useState(null);
    const [isOpenAsignarModal, setIsOpenAsignarModal] = useState(false);
    const [ordenIdAsignar, setOrdenIdAsignar] = useState(null);
    const [usuariosMantenimiento, setUsuariosMantenimiento] = useState([]);
    const [usuariosMantenimientoFiltro, setUsuariosMantenimientoFiltro] = useState([]);
    const [fechaEstimacion, setFechaEstimacion] = useState(null);
    const [usuarioSeleccionado, setUsuarioSeleccionado] = useState(null);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [tiempo, setTiempo] = useState('');
    const [mensaje, setMensaje] = useState('');
    const [ordenId, setOrdenId] = useState(null);
    const [print, setPrint] = useState(false)

    const [isOpenFinalizarModal, setIsOpenFinalizarModal] = useState(false);


    // const printRef = useRef();


    /* const handlePrint = useReactToPrint({
        content: () => printRef.current, // Referencia al contenido a imprimir
        onAfterPrint: () => console.log("¡Impresión exitosa!"), // Callback opcional
    });
    
 */
    const abrirModalEliminar = (ordenId) => {
        setOrdenIdEliminar(ordenId);
        setIsModalEliminarOpen(true);
    };
    const abrirModalFinalizar = (id) => {
        setIdOrden(id);
        setIsOpenFinalizarModal(true);
    };
    const abrirModalFotoFinalizada = (idOrden) => {
        setSelectedOrderId(idOrden);
        setIsModalOpen(true);
    };
    const handleOpenModal = (ordenId) => {

        setSelectedOrdenIdAgregar(ordenId);
        setIsModalOpenModalAgregar(true);
    };
    const handleEliminarOrden = async () => {
        if (!mensajeFinalizacion.trim()) {
            notification.error({
                message: "Error",
                description: "Debes ingresar un motivo de eliminación.",
            });
            return;
        }

        try {
            const token = await getItem(); // Obtener el token
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenIdEliminar}/finalizar2`, {
                method: "PUT",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({ mensaje_finalizacion: mensajeFinalizacion }),
            });

            if (response.ok) {
                notification.success({
                    message: "Orden Eliminada",
                    description: "La orden se eliminó correctamente.",
                });
                FetchData(); // Refresca la lista de órdenes
            } else {
                throw new Error("No se pudo eliminar la orden.");
            }
        } catch (error) {
            notification.error({
                message: "Error",
                description: error.message || "Hubo un problema al eliminar la orden.",
            });
        }

        setIsModalEliminarOpen(false);
        setMensajeFinalizacion("");
    };


    const applyFilters = async () => {
        setIsLoading(true);
        const token = await getItem();
        const queryParams = new URLSearchParams();

        // Si no es "Todas", agregar el filtro de departamento
        if (selectedDepartamento && selectedDepartamento !== 'todas') {
            queryParams.append('departamento_id', selectedDepartamento);
        }

        if (selectedUsuarioMantenimiento && selectedUsuarioMantenimiento !== 'todos') {
            queryParams.append('usuario_mantenimiento_id', selectedUsuarioMantenimiento);
        }

        if (selectedDateRange?.length === 2) {
            queryParams.append('fecha_inicio', selectedDateRange[0].format('YYYY-MM-DD'));
            queryParams.append('fecha_fin', selectedDateRange[1].format('YYYY-MM-DD'));
        }

        try {
            const data = await fetch(`${APIURI}ordenes-trabajo?${queryParams.toString()}`, {
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Accept': 'application/json',
                },
            });

            const res = await data.json();
            if (Array.isArray(res)) {
                setOrdenes(res);  // Asegúrate de que la respuesta sea un arreglo
            } else {

                setOrdenes([]);
            }
        } catch (error) {

            setOrdenes([]);  // Si hay un error, establece un arreglo vacío
        } finally {
            setIsLoading(false);
        }
    };



    /* const actualizarOrdenFinalizada = async (ordenId, horas_ot, mensaje_finalizacion, fotoFinalizada) => {
        const token = localStorage.getItem('token');
 
        try {
            const formData = new FormData();
            formData.append('estado', 'finalizada');
            formData.append('horas_ot', horas_ot);
            formData.append('mensaje_finalizacion', mensaje_finalizacion);
            if (fotoFinalizada) {
                formData.append('foto_finalizada', fotoFinalizada);
            }
 
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/estado`, {
                method: 'PUT',
                headers: {
                    Authorization: `Bearer ${token}`,
                },
                body: formData,
            });
 
            const res = await response.json();
 
            if (response.ok) {
                notification.success({
                    message: 'Orden Finalizada',
                    description: 'La orden ha sido finalizada con éxito.',
                });
                handleCloseModal();
                // Aquí puedes llamar a FetchData para actualizar la lista de órdenes
            } else {
                notification.error({
                    message: 'Error',
                    description: res.error || 'Ocurrió un error al finalizar la orden.',
                });
            }
        } catch (error) {
            notification.error({
                message: 'Error de conexión',
                description: 'No se pudo conectar al servidor.',
            });
        }
    }; */

    const abrirModalAsignar = async (ordenId) => {
        setOrdenIdAsignar(ordenId);
        // Fetch maintenance users with 'group leader' role
        const token = await getItem();
        const data = await fetch(`${APIURI}usuarios-mantenimiento`, {
            headers: {
                Authorization: `Bearer ${token}`,
                'Accept': 'application/json',
            },
        });
        // Ajusta el endpoint según sea necesario

        const response = await data.json();
        setUsuariosMantenimiento(response.filter(p => p.rol === 'group_leader'));
        setIsOpenAsignarModal(true);
    };

    const asignarUsuario = async (usuarioId) => {
        const token = await getItem();
        fetch(`${APIURI}ordenes-trabajo/${ordenIdAsignar}/estado`, {
            method: 'PUT',
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                estado: 'asignada',
                usuario_mantenimiento_id: usuarioId,
                fecha_estimacion: fechaEstimacion,
            }),
        })
            .then(response => {
                if (response.ok) {
                    notification.success({
                        message: 'Usuario Asignado',
                        description: 'El usuario ha sido asignado correctamente a la orden de trabajo.',
                    });
                    setIsOpenAsignarModal(false); // Cerrar el modal
                    FetchData(); // Refresca la lista de órdenes

                    // Limpia los campos del modal
                    setUsuarioSeleccionado(null);
                    setFechaEstimacion(null);
                    setOrdenIdAsignar(null); // Reinicia el ID de la orden asignada
                    setUsuariosMantenimiento([]); // Limpia la lista de usuarios del modal
                } else {
                    throw new Error('Error al asignar usuario');
                }
            })
            .catch(error => {
                notification.error({
                    message: 'Error',
                    description: 'Hubo un error al asignar el usuario. Inténtelo de nuevo.',
                });
            });
    };

    const fetchUsuariosMantenimientoFiltro = async () => {
        try {
            const token = await getItem();
            const data = await fetch(`${APIURI}usuarios-mantenimiento`, {
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Accept': 'application/json',
                },
            });

            const response = await data.json();
            setUsuariosMantenimientoFiltro(
                Array.isArray(response) ? response.filter(p => p.rol === 'group_leader') : []
            );
        } catch {
            setUsuariosMantenimientoFiltro([]);
        }
    };

    // const fetchDepartamentos = async () => {
    //     try {
    //         const token = await getItem();
    //         const data = await fetch(`${APIURI}departamentos`, {
    //             headers: {
    //                 Authorization: `Bearer ${token}`,
    //                 Accept: 'application/json',
    //             },
    //         });

    //         const res = await data.json();

    //         if (Array.isArray(res)) {
    //             setDepartamentos(res);
    //         } else {
    //             console.error('La respuesta no es un arreglo:', res);
    //             setDepartamentos([]);
    //         }
    //     } catch (error) {
    //         console.error('Error al obtener departamentos:', error);
    //         setDepartamentos([]); // Manejo de error: establece un arreglo vacío
    //     }
    // };

    // useEffect(() => {
    //     fetchDepartamentos();
    // }, []);

    // useEffect(() => {

    // }, []);

    useEffect(() => {
        const fetchDepartamentos = async () => {
            // const token = await getItem();
            // const data = await fetch(`${APIURI}departamentos`, {
            //     headers: {
            //         Authorization: `Bearer ${token}`,
            //         'Accept': 'application/json',
            //     },
            // });
            // const res = await data.json();

            // const res = Array.from(Object.entries(Deptos))
            // console.log(res)
            setDepartamentos(departamentosArr);
        };

        fetchDepartamentos();
        fetchUsuariosMantenimientoFiltro();
        FetchData();
    }, []);


    useEffect(() => {
        // Obtener la información del usuario desde localStorage
        const userData = localStorage.getItem('user');

        if (userData) {
            const usuario = JSON.parse(userData)
            setUser(usuario); // Convertir de string a objeto
            setUsuario(usuario)
        }
    }, []);

    const FetchData = async () => {
        setIsLoading(true)
        const token = await getItem();
        // const userData = await fetch(`${APIURI}user`, {
        //     headers: {
        //         Authorization: `Bearer ${token}`,
        //         'Accept': 'application/json'
        //     },
        // });
        // const userRes = await userData.json();
        // setUsuario(userRes);
        const data = await fetch(`${APIURI}ordenes-trabajo`, {
            headers: {
                Authorization: `Bearer ${token}`,
                'Accept': 'application/json'
            },
        });

        const res = await data.json();
        console.log(res)

        setOrdenes(res);
        setOrdenesSinFiltro(res);

        setIsLoading(false)


        /* console.log(userRes); */
    };

    const aprobarOrden = async (ordenId) => {
        const token = await getItem();

        // Actualización del estado a "aprobada"
        const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/aprobar`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ estado: 'aprobada' }), // Cambia el estado a "aprobada"
        });

        if (response.ok) {
            notification.success({
                message: 'Orden aprobada',
                description: 'La orden ha sido aprobada con éxito.',
            });
            FetchData(); // Refresca los datos después de la aprobación
        } else {
            notification.error({
                message: 'Error',
                description: 'Hubo un error al aprobar la orden. Inténtelo de nuevo.',
            });
        }
    };

    const abrirModalDetalle = (ordenId) => {
        setOrdenIdCambio(ordenId);
        setIsOpenDetalleModal(true);
    };

    const cerrarModalDetalle = () => {
        setIsOpenDetalleModal(false);
        setMensajeCambio('');
        setOrdenIdCambio(null);
    };
    const actualizarEstado = async () => {
        if (!mensajeCambio) {
            notification.error({
                message: 'Error',
                description: 'Debes ingresar un mensaje antes de actualizar el estado.',
            });
            return;
        }

        const token = await getItem();

        // Enviar la actualización del estado junto con el mensaje al backend
        const response = await fetch(`${APIURI}ordenes-trabajo/${ordenIdCambio}/aprobar`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({
                estado: 'aprobada',  // Puedes ajustar el estado según tu lógica
                mensaje: mensajeCambio,
            }),
        });
        /* console.log(response) */
        const res = await response.json()
        /* console.log(res) */

        if (response.ok) {

            notification.success({
                message: 'Estado Actualizado',
                description: 'El estado de la orden ha sido actualizado con éxito.',
            });
            cerrarModalDetalle();  // Cierra el modal después de actualizar
            FetchData();  // Refresca la lista de órdenes
        } else {
            notification.error({
                message: 'Error',
                description: response.error,
            });
        }
    };
    const finalizarOrden = async () => {
        if (!mensajeFinalizacion.trim()) {
            notification.error({
                message: "Error",
                description: "Debes ingresar un motivo de finalización.",
            });
            return;
        }

        try {
            const token = await getItem();
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/finalizar`, {
                method: "PUT",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({ mensaje_finalizacion: mensajeFinalizacion }),
            });

            if (response.ok) {
                notification.success({
                    message: "Orden Finalizada",
                    description: "La orden ha sido finalizada correctamente.",
                });
                FetchData(); // Refresca la lista de órdenes
            } else {
                throw new Error("No se pudo finalizar la orden.");
            }
        } catch (error) {
            notification.error({
                message: "Error",
                description: error.message || "Hubo un problema al finalizar la orden.",
            });
        }

        setIsOpenFinalizarModal(false);
        setMensajeFinalizacion("");
    };


    const cargarMensajes = async (ordenId) => {
        const token = await getItem();
        const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/mensajes`, {
            headers: {
                Authorization: `Bearer ${token}`,

            },
        });
        const mensajesData = await response.json();
        /* console.log(mensajesData) */
        setMensajes(mensajesData);
        setIsOpenMensajesModal(true);
        setOrdenIdMensajes(ordenId)
    };
    const grabarMensaje = async () => {
        const payload = {
            mensaje: mensajeCambio, orden_trabajo_id: ordenIdMensajes
        }
        const token = await getItem();
        const response = await fetch(`${APIURI}mensajes`, {
            method: 'POST',
            body: JSON.stringify(payload),
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
        });
        cargarMensajes(ordenIdMensajes)

    }

    const cerrarModalMensajes = () => {
        setIsOpenMensajesModal(false);
        setMensajes([]);
    };

    const cargarDescripcion = (id) => {
        setIdOrden(id);
        setIsOpen(true);
    };
    const eliminarOrden = async (ordenId) => {
        try {
            const token = await getItem(); // Obtén el token
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}`, {
                method: "DELETE",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Accept": "application/json",
                },
            });

            if (response.ok) {
                notification.success({
                    message: "Orden Eliminada",
                    description: "La orden de trabajo se eliminó correctamente.",
                });
                FetchData(); // Actualiza la lista de órdenes
            } else {
                throw new Error("No se pudo eliminar la orden.");
            }
        } catch (error) {
            notification.error({
                message: "Error",
                description: error.message || "Hubo un problema al eliminar la orden.",
            });
        }
    };
    const confirmarEliminacion = (ordenId) => {
        Modal.confirm({
            title: "¿Estás seguro de que deseas eliminar esta Orden de Trabajo?",
            content: "Esta acción no se puede deshacer.",
            okText: "Sí, eliminar",
            okType: "danger",
            cancelText: "Cancelar",
            onOk: () => eliminarOrden(ordenId), // Ejecuta la eliminación al confirmar
        });
    };



    const columns = [
        {
            title: 'N° Orden',
            dataIndex: 'id',
            key: 'id',
            render: (text) => <span className="font-semibold">{text}</span>,
            className: 'text-center',
        },
        { title: 'Título', dataIndex: 'titulo', key: 'titulo' },
        { title: 'Usuario Creador', dataIndex: 'usuario_creador', key: 'usuario_creador' },
        { title: 'Turno', dataIndex: 'turno', key: 'turno' },
        { title: 'Departamento Creador', dataIndex: 'departamento_creador', key: 'departamento_creador' },
        { title: 'Mantenimiento a Cargo', dataIndex: 'usuario_mantenimiento', key: 'usuario_mantenimiento' },
        {
            title: 'Fecha de Creación', // Nueva columna
            dataIndex: 'created_at',
            key: 'created_at',
            render: (text) => text ? moment(text).format('DD/MM/YYYY HH:mm') : 'N/A', // Formatea la fecha
            className: 'text-center', // Alinea el texto al centro
        },
        {
            title: 'Acción',
            key: 'accion',
            render: (text, orden) => (
                <div className="action-row">
                    {orden.estado === 'creada' && usuario?.rol === 'gerente' && (
                        <Button
                            type="primary"
                            onClick={() => aprobarOrden(orden.id)}
                        >
                            Aprobar
                        </Button>
                    )}
                    {orden.estado === 'aprobada' && parseInt(usuario?.departamento_id) === 2 && (
                        <Button type="primary" onClick={() => abrirModalAsignar(orden.id)}>
                            Asignar
                        </Button>
                    )}
                    {usuario?.rol === 'group_leader' && orden.estado === 'asignada' && (
                        <Button
                            type="primary"
                            onClick={() => abrirModalFinalizar(orden.id)}
                        >
                            Finalizar
                        </Button>
                    )}
                    <Button className=' ml-1' onClick={() => cargarDescripcion(orden.id)}>Descripción</Button>


                    <Button className='ml-1' onClick={() => cargarMensajes(orden.id)}>Mensajes</Button>

                    {/* Ejemplo de orden */}

                    <div key={orden.id}>
                        {orden.estado === 'finalizada' && (
                            <Button className=' ml-1' onClick={() => abrirModalFotoFinalizada(orden.id)}>
                                Foto Finalizada
                            </Button>
                        )}
                    </div>
                    {(
                        <Button className='ml-1' onClick={() => {
                            setOrdenId(orden.id);
                            setTimeout(() => {
                                setPrint(true);
                            }, 100);
                        }}>Imprimir</Button>
                    )}
                    {(usuario?.rol === 'gerente' || (usuario?.rol === 'analista' && orden.usuario_creador_id === usuario.id)) && (orden.estado === "creada" ||
                        (orden.estado === "aprobada" && usuario?.rol === "gerente")) &&
                        parseInt(usuario?.departamento_id) !== 2 && (
                            <Button className='ml-1' onClick={() => handleOpenModal(orden.id)}>
                                Agregar Archivos
                            </Button>
                        )}





                    {(usuario?.rol === 'gerente' || (usuario?.rol === 'analista' && orden.usuario_creador_id === usuario.id)) &&
                        (orden.estado === "creada" ||
                            (orden.estado === "aprobada" && usuario?.rol === "gerente") ||
                            (orden.estado === "pendiente")) &&  // 🔹 Se agrega condición para estado "pendiente"
                        (parseInt(usuario?.departamento_id) !== 2 ||
                            (usuario?.rol === "gerente" && parseInt(usuario?.departamento_id) === 2)) && (
                            <Button
                                type="default"
                                icon={<FaTrash />}
                                danger
                                className='ml-1'
                                onClick={() => {
                                    if (orden.estado === "pendiente") {
                                        abrirModalFinalizar(orden.id); // 🔹 Abre el modal de finalización
                                    } else {
                                        abrirModalEliminar(orden.id); // 🔹 Mantiene la eliminación para otros estados
                                    }
                                }}
                            />
                        )
                    }

                </div>
            ),
        },
    ];

    const filtrarOrdenesPorEstadoYUsuarioMantenimiento = (estado) => {
        return ordenes.filter((orden) => {
            const usuarioMantenimiento = usuario?.id; // Verifica si usuario y nombre existen
            return orden.estado === estado && orden.usuario_mantenimiento_id === usuarioMantenimiento;
        });
    };
    const filtrarOrdenesPorEstado = (estado) => ordenes.filter((orden) => orden.estado === estado);
    const filtrarOrdenesPorEstadoYDepartamento = (estado) => {
        /* console.log("Todas las órdenes:", ordenes); */ // Debug: ver todas las órdenes

        const ordenesFiltradas = ordenes.filter((orden) => {
            return (
                orden.estado === estado &&
                orden.usuario_mantenimiento === null &&
                (parseInt(orden.departamento_id) === parseInt(usuario.departamento_id) ||
                    (usuario.rol === 'analista' && orden.creador_id === usuario.id))
            );
        });


        /* e.log("Órdenes filtradas:", ordenesFiltradas);consol */ // Debug: ver resultado final
        return ordenesFiltradas;
    };
    const filtrarOrdenesAsignadasPorUser = (estado) => {
        /* console.log("Todas las órdenes:", ordenes); */ // Debug: ver todas las órdenes

        const ordenesFiltradas = ordenes.filter((orden) => {
            return (
                orden.estado === estado &&
                orden.usuario_mantenimiento_id === usuario.id

            );
        });

        /* e.log("Órdenes filtradas:", ordenesFiltradas);consol */ // Debug: ver resultado final
        return ordenesFiltradas;
    };

    const ordenesList = Array.isArray(ordenes) ? ordenes : [];
    const ordenesSinFiltroList = Array.isArray(ordenesSinFiltro) ? ordenesSinFiltro : [];
    const countByEstado = (estado) => ordenesList.filter((orden) => orden.estado === estado).length;
    const fechaInicioConteo = selectedDateRange?.length === 2
        ? moment(selectedDateRange[0].format('YYYY-MM-DD')).startOf('day')
        : null;
    const fechaFinConteo = selectedDateRange?.length === 2
        ? moment(selectedDateRange[1].format('YYYY-MM-DD')).endOf('day')
        : null;
    const ordenesParaConteoMantenimiento = ordenesSinFiltroList.filter((orden) => {
        const coincideDepartamento = !selectedDepartamento ||
            selectedDepartamento === 'todas' ||
            parseInt(orden.departamento_id) === parseInt(selectedDepartamento);
        const coincideFecha = fechaInicioConteo && fechaFinConteo
            ? moment(orden.created_at).isBetween(fechaInicioConteo, fechaFinConteo, null, '[]')
            : true;

        return coincideDepartamento && coincideFecha;
    });
    const cantidadAsignadaPorMantenimiento = (usuarioMantenimientoId) =>
        ordenesParaConteoMantenimiento.filter((orden) =>
            orden.estado === 'asignada' &&
            parseInt(orden.usuario_mantenimiento_id) === parseInt(usuarioMantenimientoId)
        ).length;
    const totalAsignadasMantenimiento = ordenesParaConteoMantenimiento.filter((orden) =>
        orden.estado === 'asignada' && orden.usuario_mantenimiento_id
    ).length;
    const tableRowClassName = (record, index) => (index % 2 === 0 ? 'ot-table-row-even' : 'ot-table-row-odd');

    return (
        <div className="ot-list">
            <ModalDescripcion
                isOpen={isOpen}
                setIsOpen={setIsOpen}
                idOrden={idOrden}
                usuarioLogueado={usuario}
            />

            {loading && <div className="flex items-center justify-center py-12"><LoadingIcon /></div>}

            {!loading &&
                <>
                    <div className="ot-page-heading">
                        <div>
                            <p className="ot-eyebrow">Sistema OT</p>
                            <h1>Ordenes de Trabajo</h1>
                        </div>
                        <button
                            className="primary-action"
                            onClick={() => setIsOpenCrearOrden(true)}
                            type="button"
                        >
                            <PlusOutlined />
                            Crear Orden
                        </button>
                    </div>

                    <div className="ot-summary-grid">
                        <div className="ot-summary-card">
                            <span>Pendientes</span>
                            <strong>{countByEstado('creada') + countByEstado('aprobada')}</strong>
                        </div>
                        <div className="ot-summary-card">
                            <span>En Proceso</span>
                            <strong>{countByEstado('asignada')}</strong>
                        </div>
                        <div className="ot-summary-card">
                            <span>Finalizadas</span>
                            <strong>{countByEstado('finalizada')}</strong>
                        </div>
                        <div className="ot-summary-card">
                            <span>Total</span>
                            <strong>{ordenesList.length}</strong>
                        </div>
                    </div>

                    <div className="ot-toolbar">
                        <div className="ot-toolbar__row">

                    {/* Grupo de botones de estado, centrados a la izquierda */}
                    <div className="ot-status-group">
                        {parseInt(usuario?.departamento_id) !== 2 && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'creada' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'creada' ? null : 'creada')}
                            >
                                Pendientes a Aprobación
                            </Button>
                        )}
                        {(usuario?.rol === 'gerente' && parseInt(usuario?.departamento_id) !== 2) || usuario?.rol === 'analista' ? (
                            <Button
                                className={`ot-status-button ${visibleTable === 'aprobadas_no_asignadas' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'aprobadas_no_asignadas' ? null : 'aprobadas_no_asignadas')}
                            >
                                Aprobadas no Asignadas
                            </Button>
                        ) : null}
                        {parseInt(usuario?.departamento_id) !== 2 && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'en_proceso' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'en_proceso' ? null : 'en_proceso')}
                            >
                                En Proceso
                            </Button>
                        )}
                        {parseInt(usuario?.departamento_id) === 2 && usuario?.rol === 'gerente' && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'pendiente' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'pendiente' ? null : 'pendiente')}
                            >
                                Pendientes
                            </Button>
                        )}
                        {parseInt(usuario?.departamento_id) === 2 && usuario?.rol === 'group_leader' && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'en_proceso_group_leader' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'en_proceso_group_leader' ? null : 'en_proceso_group_leader')}
                            >
                                Asignadas
                            </Button>
                        )}
                        {parseInt(usuario?.departamento_id) === 2 && usuario?.rol === 'gerente' && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'en_proceso' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'en_proceso' ? null : 'en_proceso')}
                            >
                                En Proceso
                            </Button>
                        )}
                        {(usuario?.rol === 'analista' || usuario?.rol === 'gerente') && (
                            <Button
                                className={`ot-status-button ${visibleTable === 'finalizada' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'finalizada' ? null : 'finalizada')}
                            >
                                Finalizadas
                            </Button>
                        )}




                        {parseInt(usuario?.departamento_id) === 2 && usuario?.rol === 'group_leader' && ( //VISTA FINALIZADAS GL
                            <Button
                                className={`ot-status-button ${visibleTable === 'finalizada_group_leader' ? 'is-active' : ''}`}
                                onClick={() => setVisibleTable(visibleTable === 'finalizada_group_leader' ? null : 'finalizada_group_leader')}
                            >
                                Finalizadas
                            </Button>
                        )}
                    </div>
                    <div className="ot-filter-group">
                        {usuario?.rol === 'gerente' && parseInt(usuario?.departamento_id) === 2 && (
                            <Select
                                placeholder="Seleccionar Departamento"
                                style={{ width: 200 }}
                                onChange={(value) => setSelectedDepartamento(value)}
                                value={selectedDepartamento}
                            >
                                <Select.Option value="todas">Todas</Select.Option> {/* Opción para traer todas las órdenes */}
                                {Array.isArray(departamentos) &&
                                    departamentos.map((dept) => (
                                        <Select.Option key={dept.id} value={dept.id}>
                                            {dept.nombre}
                                        </Select.Option>
                                    ))}
                            </Select>
                        )}

                        {usuario?.rol === 'gerente' && parseInt(usuario?.departamento_id) === 2 && (
                            <Select
                                placeholder="Mantenimiento a cargo"
                                style={{ width: 240 }}
                                onChange={(value) => setSelectedUsuarioMantenimiento(value)}
                                value={selectedUsuarioMantenimiento}
                            >
                                <Select.Option value="todos">
                                    Todos los GL ({totalAsignadasMantenimiento} OT asignadas)
                                </Select.Option>
                                {usuariosMantenimientoFiltro.map((usuarioMantenimiento) => (
                                    <Select.Option key={usuarioMantenimiento.id} value={usuarioMantenimiento.id}>
                                        {usuarioMantenimiento.name} ({cantidadAsignadaPorMantenimiento(usuarioMantenimiento.id)} OT asignadas)
                                    </Select.Option>
                                ))}
                            </Select>
                        )}

                        {/* Selector de Rango de Fechas */}
                        <DatePicker.RangePicker
                            onChange={(dates) => setSelectedDateRange(dates || [])}
                            format="YYYY-MM-DD"
                        />

                        {/* Botón para Aplicar Filtros */}
                        <Button type="primary" onClick={applyFilters} icon={<SearchOutlined />}>
                            Aplicar Filtros
                        </Button>
                    </div>


                        </div>
                    </div>
                </>
            }

            {visibleTable === 'creada' && (
                <>
                    <h2 className="table-section-title">Pendientes a Aprobación</h2>
                    <Table
                        dataSource={filtrarOrdenesPorEstado('creada')}
                        columns={columns}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-pending"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}

            {visibleTable === 'pendiente' && (
                <>
                    <h2 className="table-section-title">Pendientes</h2>
                    <Table
                        dataSource={filtrarOrdenesPorEstado('aprobada')}
                        columns={columns}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-danger"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}
            {visibleTable === 'aprobadas_no_asignadas' && (
                <>
                    <h2 className="table-section-title">Aprobadas no Asignadas</h2>
                    {/* filtrarOrdenesPorEstadoYDepartamento('aprobada') */}
                    <Table
                        dataSource={filtrarOrdenesPorEstadoYDepartamento('aprobada')}
                        columns={[
                            ...columns, // Añade las columnas ya existentes
                            {
                                title: 'Fecha de Aprobación', // Título de la nueva columna
                                dataIndex: 'fecha_aprobacion', // Campo en los datos
                                key: 'fecha_aprobacion',
                                render: (text) => text ? new Date(text).toLocaleDateString() : 'N/A', // Formatea la fecha o muestra 'N/A' si está vacía
                                className: 'text-center', // Clase opcional para centrar el texto
                            }
                        ]}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-info"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}


            {visibleTable === 'en_proceso' && (
                <>
                    <h2 className="table-section-title">En Proceso</h2>
                    <Table
                        dataSource={filtrarOrdenesPorEstado('asignada')}
                        columns={[
                            ...columns, // Keep existing columns
                            {
                                title: 'Fecha de Estimación', // Title for the new column
                                dataIndex: 'fecha_estimacion', // Data field
                                key: 'fecha_estimacion',
                                render: (text) => text ? new Date(text).toLocaleDateString() : 'N/A', // Format the date or display 'N/A' if empty
                                className: 'text-center', // Optional class to center the text
                            }
                        ]}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-pending"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}
            {visibleTable === 'en_proceso_group_leader' && (
                <>
                    <h2 className="table-section-title">En Proceso</h2>
                    <Table
                        dataSource={filtrarOrdenesAsignadasPorUser('asignada')}
                        columns={[
                            ...columns, // Keep existing columns
                            {
                                title: 'Fecha de Estimación', // Title for the new column
                                dataIndex: 'fecha_estimacion', // Data field
                                key: 'fecha_estimacion',
                                render: (text) => text ? new Date(text).toLocaleDateString() : 'N/A', // Format the date or display 'N/A' if empty
                                className: 'text-center', // Optional class to center the text
                            }
                        ]}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-pending"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}


            {visibleTable === 'finalizada' && (
                <>
                    <h2 className="table-section-title">Finalizadas</h2>
                    <Table
                        dataSource={filtrarOrdenesPorEstado('finalizada')}
                        columns={columns}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-success"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}
            {visibleTable === 'finalizada_group_leader' && (
                <>
                    <h2 className="table-section-title">Finalizadas</h2>
                    <Table
                        dataSource={filtrarOrdenesAsignadasPorUser('finalizada')}
                        columns={columns}
                        rowKey="id"
                        pagination={{ pageSize: 10 }}
                        size="small"
                        scroll={{ x: 'max-content' }}
                        className="modern-table status-success"
                        rowClassName={tableRowClassName}
                    />
                </>
            )}


            <Modal
                title="Actualizar Estado"
                open={isOpenDetalleModal}
                onCancel={cerrarModalDetalle}
                onOk={actualizarEstado}  // Aquí está la función definida
            >
                <Input.TextArea
                    rows={4}
                    value={mensajeCambio}
                    onChange={(e) => setMensajeCambio(e.target.value)}
                    placeholder="Escribe un mensaje de cambio de estado"
                />
            </Modal>


            <Modal
                title={`Orden N° #${ordenIdMensajes}`}// Aquí puedes poner dinámicamente el título de la orden
                open={isOpenMensajesModal}
                onCancel={cerrarModalMensajes}
                footer={null}
                className="messages-modal"
            >
                <div className="message-list">
                {mensajes.length === 0 ? (
                    <p className="text-md text-black p-4">No hay mensajes</p>  // Texto más pequeño
                ) : (
                    mensajes.map((mensaje) => (
                        <div
                            className={`message-row ${mensaje.usuario.id === user?.id ? 'is-own' : 'is-other'}`}
                            key={mensaje.id}
                        >
                            <div
                                className={`message-bubble break-words ${mensaje.usuario.id === user?.id ? 'is-own' : 'is-other'}`}
                            >
                                <p className="message-author">
                                    {mensaje.usuario.name}: <span className="font-normal">{mensaje.mensaje}</span> {/* Texto más pequeño para el mensaje */}
                                </p>
                                <p className="message-time">
                                    {moment(mensaje.created_at).format('DD/MM/YYYY HH:mm')}  {/* Asegúrate de usar 'created_at' para la fecha */}
                                </p>
                            </div>
                        </div>
                    ))
                )}

                </div>

                <div className="message-composer">
                    <Input.TextArea
                        rows={4}
                        value={mensajeCambio}
                        onChange={(e) => setMensajeCambio(e.target.value)}
                        placeholder="Escribe un mensaje"
                    />

                    <Button
                        onClick={() => {
                            grabarMensaje();
                            setMensajeCambio('');  // Limpiar el campo después de enviar el mensaje
                        }}
                        type="primary"
                        icon={<FaPaperPlane />}
                    >
                        Enviar
                    </Button>
                </div>
            </Modal>

            <Modal
                title={`Asignar Usuario para Orden ID: ${ordenIdAsignar}`}
                open={isOpenAsignarModal}
                onCancel={() => {
                    setIsOpenAsignarModal(false); // Cierra el modal
                    // Limpia los campos
                    setUsuarioSeleccionado(null);
                    setFechaEstimacion(null);
                }}
                onOk={() => {
                    if (usuarioSeleccionado && fechaEstimacion) {
                        asignarUsuario(usuarioSeleccionado);
                    } else {
                        notification.error({
                            message: 'Campos requeridos',
                            description: 'Por favor, selecciona un usuario y una fecha estimada.',
                        });
                    }
                }}
            >
                <Select
                    placeholder="Selecciona un usuario de mantenimiento"
                    style={{ width: '100%' }}
                    value={usuarioSeleccionado} // Muestra el valor seleccionado
                    onChange={value => setUsuarioSeleccionado(value)} // Guardar el usuario seleccionado
                >
                    {usuariosMantenimiento.map(usuario => (
                        <Select.Option key={usuario.id} value={usuario.id}>
                            {usuario.name}
                        </Select.Option>
                    ))}
                </Select>
                <DatePicker
                    placeholder="Seleccionar fecha estimada"
                    value={fechaEstimacion ? moment(fechaEstimacion) : null} // Muestra el valor seleccionado
                    onChange={(date, dateString) => setFechaEstimacion(dateString)}
                    style={{ width: '100%' }}
                />
            </Modal>
            <ModalAgregarArchivos
                isOpen={isModalOpenModalAgregar}
                setIsOpen={setIsModalOpenModalAgregar}
                ordenId={selectedOrdenIdAgregar}
                onUploadSuccess={FetchData}
            />
            {isModalOpen && (
                <ModalMostrarFotoFinalizada
                    isOpen={isModalOpen}
                    setIsOpen={setIsModalOpen}
                    idOrden={selectedOrderId}
                />
            )}
            <Modal
                title="Eliminar Orden de Trabajo"
                open={isModalEliminarOpen}
                onOk={handleEliminarOrden}
                onCancel={() => setIsModalEliminarOpen(false)}
                okText="Eliminar"
                cancelText="Cancelar"
                okButtonProps={{ danger: true }}
            >
                <p>¿Estás seguro de que deseas eliminar esta orden?</p>
                <Input.TextArea
                    value={mensajeFinalizacion}
                    onChange={(e) => setMensajeFinalizacion(e.target.value)}
                    placeholder="Ingrese el motivo de eliminación..."
                    rows={3}
                />
            </Modal>

            <ModalFinalizarOrdenTrabajo
                isOpen={isOpenFinalizarModal}
                setIsOpen={setIsOpenFinalizarModal}
                ordenId={idOrden}/*  */
                onFinalizarSuccess={FetchData}
            />

            <div>
                <ModalCrearOrdenTrabajo
                    isOpen={isOpenCrearOrden}
                    setIsOpen={setIsOpenCrearOrden}
                    onCreateSuccess={FetchData} // Pasa FetchData como onCreateSuccess
                />

            </div>

            <div>
                <ImprimirOrden ordenId={ordenId} setOrdenId={setOrdenId} />
            </div>
        </div>
    );
};

export default OrdenTrabajoList;
