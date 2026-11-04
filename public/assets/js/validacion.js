// Ayudas de formulario en el navegador (bloque 10).
// Son un complemento: la validación definitiva siempre se hace en el servidor (Validador*).
(function () {
  'use strict';

  var formularios = document.querySelectorAll('form[method="post"]');

  /** Botones de envío de un formulario. */
  function botonesDeEnvio(form) {
    return form.querySelectorAll('button[type="submit"], input[type="submit"]');
  }

  /** Vuelve a habilitar el formulario (al regresar con el botón Atrás, o si el envío falla). */
  function habilitar(form) {
    form.dataset.enviando = 'no';
    botonesDeEnvio(form).forEach(function (boton) {
      boton.disabled = false;
    });
  }

  formularios.forEach(function (form) {
    form.addEventListener('submit', function (evento) {
      // Si ya se envió una vez, el segundo clic no hace nada.
      if (form.dataset.enviando === 'si') {
        evento.preventDefault();
        return;
      }

      // Si hay campos obligatorios vacíos o con formato incorrecto, no se bloquea:
      // el navegador muestra su propio mensaje junto al campo.
      if (!form.checkValidity()) {
        return;
      }

      form.dataset.enviando = 'si';

      // Se deshabilitan después de que el navegador arme los datos del formulario,
      // para no afectar a los botones que envían valores.
      setTimeout(function () {
        botonesDeEnvio(form).forEach(function (boton) {
          boton.disabled = true;
        });
      }, 0);
    });
  });

  // Al volver con el historial, la página puede quedar en caché con el formulario bloqueado.
  window.addEventListener('pageshow', function (evento) {
    if (evento.persisted) {
      formularios.forEach(habilitar);
    }
  });
})();
