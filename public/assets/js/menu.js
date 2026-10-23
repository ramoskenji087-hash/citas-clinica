// Botón "Menú" en celular: abre y cierra la lista de opciones.
// Si la página no tiene menú (por ejemplo, la pantalla de acceso), no hace nada.
(function () {
  'use strict';

  var boton = document.getElementById('btn-menu');
  var nav = document.getElementById('nav-movil');

  if (!boton || !nav) {
    return;
  }

  boton.addEventListener('click', function () {
    var abierto = nav.classList.toggle('abierto');
    boton.setAttribute('aria-expanded', String(abierto));
  });
})();
