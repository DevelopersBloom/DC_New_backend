import {Dismiss} from "flowbite";
import router from "@/router";

const makeMoney = (value, with_sign = false) => {
    value += ''
    let minus = false;
    let float = '';

    let dotIndex = value.indexOf('.');

    if (dotIndex !== -1) {
        float = value.substring(dotIndex);
        value = value.substring(0, dotIndex);
    }
    if (value[0] === '-') {
        minus = true;
    }
    value = value.replace(/\D/g, '')

    if (value[0] === '0') {
        value = value.substr(1, value.length);
    }
    let result = '';
    let check = true;
    while (check) {
        if (value.length > 3) {
            result = ', ' + value.substr(value.length - 3, 3) + result;
            value = value.substr(0, value.length - 3)
        } else {
            result = value + result;
            check = false;
        }
    }
    if (!result.length) {
        result = '0'
    }
    if (minus) {
        result = '- ' + result
    }
    if (float) {
        result = result + float
    }
    return result;
}
const alertSuccess = (message, timeout = 5000, link = null) => {
    const alert = document.createElement('div')
    let className = link ? 'is-link' : ''
    alert.innerHTML = '<div class="fixed ' + className + ' alert-element flex categories-center p-4 mb-4 text-green-500 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400" role="alert">\n' +
        '      <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">\n' +
        '        <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/>\n' +
        '      </svg>\n' +
        '      <div class="ml-3 text-sm font-medium message_part">\n' +
        message +
        '      </div>\n' +
        '    </div>'
    document.body.appendChild(alert)
    const dismiss = new Dismiss(alert, null, {
        transition: 'transition-opacity',
        duration: 1000,
        timing: 'ease-out',
        onHide: (context, targetEl) => {
        }
    });
    if (link) {
        alert.addEventListener('click', function () {
            router.push(link)
            dismiss.hide()
        })
    }
    setTimeout(() => {
        dismiss.hide()
    }, timeout)
}
const alertError = (message, timeout = 5000) => {
    const alert = document.createElement('div')
    alert.innerHTML = '<div class="fixed flex alert-element categories-center p-4 mb-4 text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">\n' +
        '        <svg class="flex-shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">\n' +
        '          <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/>\n' +
        '        </svg>\n' +
        '        <div class="ml-3 text-sm font-medium">\n' +
        message +
        '        </div>\n' +
        '      </div>'
    document.body.appendChild(alert)
    const dismiss = new Dismiss(alert, null, {
        transition: 'transition-opacity',
        duration: 1000,
        timing: 'ease-out',
        onHide: (context, targetEl) => {
        }
    });
    setTimeout(() => {
        dismiss.hide()
    }, timeout)
}


export {makeMoney, alertSuccess, alertError}
