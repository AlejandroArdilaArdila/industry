import $ from 'jquery';
import { registerLicense } from '@syncfusion/ej2-base';
import '@fortawesome/fontawesome-free/css/all.min.css';

// Expuesto globalmente para plugins jQuery clásicos que esperan $ / jQuery en window.
window.$ = window.jQuery = $;

if (import.meta.env.VITE_SYNCFUSION_LICENSE_KEY) {
    registerLicense(import.meta.env.VITE_SYNCFUSION_LICENSE_KEY);
}
