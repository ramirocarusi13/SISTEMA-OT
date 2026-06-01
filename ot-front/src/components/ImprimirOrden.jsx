import React, { useEffect, useRef, useState } from 'react';
import { useReactToPrint } from 'react-to-print';

const APIURI = import.meta.env.VITE_API

export default function ImprimirOrden({ ordenId, setOrdenId }) {
    const [ordenData, setOrdenData] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);

    const componentRef = useRef(null)
    useEffect(() => {
        if (ordenId) {
            fetchOrden()
        }
    }, [ordenId]);

    const handlePrint = useReactToPrint({ contentRef: componentRef })


    const fetchOrden = async () => {
        setIsLoading(true);
        const token = localStorage.getItem('token');
        const data = await fetch(`${APIURI}ordenes-trabajo/${ordenId}`, {
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
        })

        const res = await data.json()
        setOrdenData(res)
        /* console.log(res) */

        setOrdenId(null)
        setTimeout(() => {
            handlePrint()
        }, 50)

    }

    // if (isLoading) {
    //     return <div>Cargando...</div>;
    // }

    // if (error) {
    //     return <div>Error: {error}</div>; // Mostrar mensaje de error si lo hay
    // }

    // if (!ordenData) {
    //     return <div>No hay datos para imprimir.</div>; // Mensaje si no hay datos
    // }

    return (
        <div
            ref={componentRef}
            className="border border-gray-300 rounded-lg shadow-md p-2 mx-auto bg-white max-w-[200mm] print:max-w-[200mm]  print:shadow-none print:border-none text-xs hidden print:block"
        >
            <div className="flex items-center justify-between mb-2">
                <div className="flex items-center">
                    <img src="/LOGO-LETRAS.png" className="w-24 h-12 pt-4 object-contain" alt="Logo" />
                </div>
                <span className="text-lg font-semibold text-gray-700">
                    ORDEN DE TRABAJO DE MANTENIMIENTO
                </span>
                <div></div>
            </div>

            {/* Basic Information Grid */}
            <div className="grid grid-cols-2 gap-2 mb-2">
                <div className="flex flex-col">
                    <label className="text-gray-500">Núm. de Orden:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.id || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Fecha:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{new Date(ordenData?.created_at).toLocaleDateString() || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Solicitado por:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.creador.name || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Equipo:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Área de trabajo:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.creador.departamento.nombre || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Clasificación del trabajo:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                </div>
            </div>

            {/* Job Request Section */}
            <div className="mb-2">
                <label className="text-gray-500">Trabajo solicitado:</label>
                {ordenData?.descripciones
                    ?.filter((d, index, self) =>
                        index === self.findIndex((t) => t.descripcion === d.descripcion)
                    )
                    .map((d, idx) => (
                        <div key={idx} className="border border-gray-300 mt-1 rounded-md h-8 p-1">
                            {d?.descripcion || '_________'}
                        </div>
                    ))}
            </div>

            {/* Dates Section */}
            <div className="grid grid-cols-2 gap-2 mb-2">
                <div className="flex flex-col">
                    <label className="text-gray-500">Fecha de programación:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.fecha_estimacion || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Fecha de realizacion:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.fecha_finalizacion || '_________'}</span>
                </div>
            </div>

            {/* Work Description and Observations */}
            <div className="mb-2">
                <label className="text-gray-500">Descripción de trabajo realizado:</label>
                <div className="border border-gray-300 mt-1 rounded-md h-8 p-1">
                    {ordenData?.mensaje_finalizacion || '_________'}
                </div>
                <label className="text-gray-500 mt-2">Observaciones:</label>
                <div className="border border-gray-300 mt-1 rounded-md h-8 p-1">_________</div>
            </div>

            {/* Signature Section */}
            <div className="grid grid-cols-2 gap-2 mb-2">
                <div className="flex flex-col">
                    <label className="text-gray-500">Realizado por:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.usuario_mantenimiento?.name || '_________'}</span>
                </div>
                <div className="flex flex-col">
                    <label className="text-gray-500">Recibido por:</label>
                    <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                </div>
            </div>

            {/* Maintenance Info */}
            <div className="mb-2">
                <span className="font-semibold text-gray-700">Para ser llenado solo por mantenimiento</span>
                <div className="grid grid-cols-2 gap-2 mt-2">
                    <div className="flex flex-col">
                        <label className="text-gray-500">Horas-hombre:</label>
                        <span className="border-b border-gray-300 text-gray-700 py-0.5">{ordenData?.horas_ot || '_________'}</span>
                    </div>
                    <div className="flex flex-col">
                        <label className="text-gray-500">Costo de mano de obra:</label>
                        <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                    </div>
                    <div className="flex flex-col">
                        <label className="text-gray-500">Costo de materiales:</label>
                        <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                    </div>
                    <div className="flex flex-col">
                        <label className="text-gray-500">Costo total:</label>
                        <span className="border-b border-gray-300 text-gray-700 py-0.5">_________</span>
                    </div>
                </div>
            </div>
        </div>
    );




}
