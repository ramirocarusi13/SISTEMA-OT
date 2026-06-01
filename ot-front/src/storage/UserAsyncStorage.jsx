const USER_KEY = "token";

async function setItem(user) {
    try {
        await asyncLocalStorage.setItem(USER_KEY, JSON.stringify(user));
        return JSON.stringify(user);
    } catch (error) {
        /* console.log("Error al setear user: ", error.message); */
        return "error";
    }
}

async function getItem() {
    try {
        const item = await asyncLocalStorage.getItem(USER_KEY);
        return item;
    } catch (error) {
        /* console.log("Error al obtener user: ", error.message); */
        return null;
    }
}

async function getItemLocalStorage(key) {
    try {
        const item = await asyncLocalStorage.getItem(key);
        return item;
    } catch (error) {
        /* console.log("Error al obtener local storage: ", key, error.message); */
        return null;
    }
}

async function setItemLocalStorage(key, value) {
    try {
        await asyncLocalStorage.setItem(key, JSON.stringify(value));
        return JSON.stringify(value);
    } catch (error) {
        /* console.log("Error al setear user: ", error.message); */
        return "error";
    }
}

async function removeItem() {
    try {
        await asyncLocalStorage.removeItem(USER_KEY);
        const item = await asyncLocalStorage.getItem(USER_KEY);
        return item == null ? "Usuario removido" : "Usuario no removido";
    } catch (error) {
        /* console.log("Error al remover usuario: ", error.message); */
        return "error";
    }
}

const asyncLocalStorage = {
    setItem: async function (key, value) {
        return Promise.resolve().then(function () {
            localStorage.setItem(key, value);
        });
    },
    getItem: async function (key) {
        return Promise.resolve().then(function () {
            return localStorage.getItem(key);
        });
    },
    removeItem: async function (key) {
        return Promise.resolve().then(function () {
            return localStorage.removeItem(key);
        });
    },
};

export { setItem, getItem, removeItem, getItemLocalStorage, setItemLocalStorage };


// import { useState } from "react";

// export const useLocalStorage = () => {
//     const [value, setValue] = useState(null);

//     const setItem = (key, value) => {
//         localStorage.setItem(key, value);
//         setValue(value);
//     };

//     const getItem = (key) => {
//         const value = localStorage.getItem(key);
//         setValue(value);
//         return value;
//     };

//     const removeItem = (key) => {
//         localStorage.removeItem(key);
//         setValue(null);
//     };

//     return { value, setItem, getItem, removeItem };
// };