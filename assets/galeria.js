/*
 * Galería de fotos y videos de GeoTerrenos.
 *
 * Uso:
 *   Galeria.montar(elemento, {
 *       terrenoId: 5,
 *       medios: [...],          // filas de terreno_medios
 *       portada: 'archivo.jpg', // terrenos.imagen
 *       puedeEliminar: true,    // super_admin
 *       limiteBytes: 41943040,  // tamaño máximo por archivo
 *       compacto: false,        // true = 2 columnas (panel del mapa)
 *       onCambio: function (medios, portada) {}
 *   });
 *
 * Cada archivo se sube por separado (con barra de progreso), así el límite
 * de PHP se aplica a cada archivo y no a la suma de todos.
 */
var Galeria = (function () {
    var API = 'api_medios.php';
    var EXT_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'm4v', 'webm', 'mov', '3gp'];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function mb(bytes) {
        return (bytes / 1048576).toLocaleString('es-VE', { maximumFractionDigits: 1 }) + ' MB';
    }

    function url(archivo) {
        return 'uploads/' + encodeURIComponent(archivo);
    }

    function fecha(f) {
        if (!f) return '';
        var d = new Date(String(f).replace(' ', 'T'));
        return isNaN(d) ? f : d.toLocaleString('es-VE', { dateStyle: 'short', timeStyle: 'short' });
    }

    function post(datos) {
        var fd = new FormData();
        Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
        return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    // ------------------------------------------------------------------
    // Visor a pantalla completa (uno para toda la página)
    // ------------------------------------------------------------------
    var visor = null;
    var visorEstado = null; // { galeria, indice }

    function crearVisor() {
        if (visor) return;
        visor = document.createElement('div');
        visor.className = 'gal-visor';
        visor.innerHTML =
            '<div class="gal-visor-cabecera">' +
            '  <span class="contador"></span>' +
            '  <div class="acciones">' +
            '    <a class="descargar" download>⬇ Descargar</a>' +
            '    <button type="button" class="portada">★ Usar como portada</button>' +
            '    <button type="button" class="eliminar peligro">🗑 Eliminar</button>' +
            '    <button type="button" class="cerrar">✕ Cerrar</button>' +
            '  </div>' +
            '</div>' +
            '<div class="gal-visor-cuerpo">' +
            '  <button type="button" class="gal-nav anterior" aria-label="Anterior">‹</button>' +
            '  <div class="medio"></div>' +
            '  <button type="button" class="gal-nav siguiente" aria-label="Siguiente">›</button>' +
            '</div>' +
            '<div class="gal-visor-pie"></div>';
        document.body.appendChild(visor);

        visor.querySelector('.cerrar').onclick = cerrarVisor;
        visor.querySelector('.anterior').onclick = function () { moverVisor(-1); };
        visor.querySelector('.siguiente').onclick = function () { moverVisor(1); };
        visor.querySelector('.eliminar').onclick = function () { visorEstado.galeria.eliminar(visorEstado.indice); };
        visor.querySelector('.portada').onclick = function () { visorEstado.galeria.hacerPortada(visorEstado.indice); };
        visor.addEventListener('click', function (e) {
            if (e.target.classList.contains('gal-visor-cuerpo')) cerrarVisor();
        });
        document.addEventListener('keydown', function (e) {
            if (!visor.classList.contains('abierto')) return;
            if (e.key === 'Escape') cerrarVisor();
            if (e.key === 'ArrowLeft') moverVisor(-1);
            if (e.key === 'ArrowRight') moverVisor(1);
        });
    }

    function abrirVisor(galeria, indice) {
        crearVisor();
        visorEstado = { galeria: galeria, indice: indice };
        pintarVisor();
        visor.classList.add('abierto');
    }

    function cerrarVisor() {
        if (!visor) return;
        var v = visor.querySelector('video');
        if (v) v.pause();
        visor.classList.remove('abierto');
        visor.querySelector('.medio').innerHTML = '';
    }

    function moverVisor(paso) {
        var total = visorEstado.galeria.medios.length;
        if (total < 2) return;
        visorEstado.indice = (visorEstado.indice + paso + total) % total;
        pintarVisor();
    }

    function pintarVisor() {
        var g = visorEstado.galeria;
        var m = g.medios[visorEstado.indice];
        if (!m) { cerrarVisor(); return; }

        var total = g.medios.length;
        visor.querySelector('.contador').textContent = (visorEstado.indice + 1) + ' de ' + total;
        visor.querySelector('.medio').innerHTML = m.tipo === 'video'
            ? '<video src="' + url(m.archivo) + '" controls autoplay playsinline></video>'
            : '<img src="' + url(m.archivo) + '" alt="' + esc(m.descripcion || 'Foto del terreno') + '">';

        var desc = visor.querySelector('.descargar');
        desc.href = url(m.archivo);
        desc.setAttribute('download', m.archivo);

        var esPortada = m.tipo === 'foto' && m.archivo === g.portada;
        var btnPortada = visor.querySelector('.portada');
        btnPortada.style.display = g.opc.puedeEliminar && m.tipo === 'foto' ? '' : 'none';
        btnPortada.disabled = esPortada;
        btnPortada.textContent = esPortada ? '★ Es la portada' : '★ Usar como portada';
        visor.querySelector('.eliminar').style.display = g.opc.puedeEliminar ? '' : 'none';

        visor.querySelector('.anterior').style.visibility = total > 1 ? 'visible' : 'hidden';
        visor.querySelector('.siguiente').style.visibility = total > 1 ? 'visible' : 'hidden';

        visor.querySelector('.gal-visor-pie').innerHTML =
            (m.descripcion ? '<b>' + esc(m.descripcion) + '</b><br>' : '') +
            (m.tipo === 'video' ? '🎬 Video' : '📷 Foto') +
            ' · Subido por ' + esc(m.subido_por_nombre || '—') + ' · ' + esc(fecha(m.creado_en)) +
            (m.tamano_bytes > 0 ? ' · ' + mb(m.tamano_bytes) : '');
    }

    // ------------------------------------------------------------------
    // Galería
    // ------------------------------------------------------------------
    function G(el, opc) {
        this.el = el;
        this.opc = opc;
        this.medios = (opc.medios || []).slice();
        this.portada = opc.portada || null;
        this.cola = [];
        this.subiendo = false;
        this.construir();
        this.pintar();
    }

    G.prototype.construir = function () {
        var self = this;
        var limite = this.opc.limiteBytes ? mb(this.opc.limiteBytes) : '';

        this.el.classList.add('gal');
        if (this.opc.compacto) this.el.classList.add('compacto');
        this.el.innerHTML =
            '<div class="gal-resumen"><span class="conteo"></span></div>' +
            '<div class="gal-subida">' +
            '  <div class="gal-botones">' +
            '    <button type="button" class="gal-btn-foto">📷 Tomar foto</button>' +
            '    <button type="button" class="gal-btn-video">🎥 Grabar video</button>' +
            '    <button type="button" class="gal-btn-archivos">📁 Elegir archivos</button>' +
            '  </div>' +
            '  <input type="text" class="gal-descripcion" maxlength="255" placeholder="Descripción (opcional): ej. Vista desde la entrada">' +
            '  <small class="gal-ayuda">También puedes arrastrar archivos aquí. Fotos JPG, PNG, WEBP · Videos MP4, MOV, WEBM' +
            (limite ? ' · Máx. ' + limite + ' por archivo' : '') + '</small>' +
            '  <input type="file" class="in-foto" accept="image/*" capture="environment" hidden>' +
            '  <input type="file" class="in-video" accept="video/*" capture="environment" hidden>' +
            '  <input type="file" class="in-archivos" accept="image/jpeg,image/png,image/webp,video/*,.mov,.3gp" multiple hidden>' +
            '  <ul class="gal-cola"></ul>' +
            '</div>' +
            '<div class="gal-rejilla"></div>';

        var q = function (s) { return self.el.querySelector(s); };
        q('.gal-btn-foto').onclick = function () { q('.in-foto').click(); };
        q('.gal-btn-video').onclick = function () { q('.in-video').click(); };
        q('.gal-btn-archivos').onclick = function () { q('.in-archivos').click(); };
        ['.in-foto', '.in-video', '.in-archivos'].forEach(function (s) {
            q(s).onchange = function (e) {
                self.agregarACola(e.target.files);
                e.target.value = '';
            };
        });

        // Arrastrar y soltar
        var zona = q('.gal-subida');
        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('arrastrando'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('arrastrando'); });
        });
        zona.addEventListener('drop', function (e) { self.agregarACola(e.dataTransfer.files); });
    };

    G.prototype.pintar = function () {
        var self = this;
        var fotos = this.medios.filter(function (m) { return m.tipo === 'foto'; }).length;
        var videos = this.medios.length - fotos;
        this.el.querySelector('.conteo').innerHTML =
            '📷 <b>' + fotos + '</b> foto' + (fotos === 1 ? '' : 's') + ' · 🎬 <b>' + videos + '</b> video' + (videos === 1 ? '' : 's');

        var rejilla = this.el.querySelector('.gal-rejilla');
        if (!this.medios.length) {
            rejilla.innerHTML = '<div class="gal-vacio" style="grid-column:1/-1;">Todavía no hay fotos ni videos de este terreno.</div>';
            return;
        }

        rejilla.innerHTML = this.medios.map(function (m, i) {
            var marca = m.tipo === 'foto' && m.archivo === self.portada
                ? '<span class="gal-marca portada">★ Portada</span>'
                : (m.tipo === 'video' ? '<span class="gal-marca">Video</span>' : '');
            var contenido = m.tipo === 'video'
                ? '<video src="' + url(m.archivo) + '#t=0.5" preload="metadata" muted playsinline></video><span class="gal-play">▶</span>'
                : '<img src="' + url(m.archivo) + '" loading="lazy" alt="' + esc(m.descripcion || 'Foto del terreno') + '">';
            return '<div class="gal-item" data-i="' + i + '" title="' + esc(m.descripcion || 'Ver en grande') + '">' + contenido + marca + '</div>';
        }).join('');

        rejilla.querySelectorAll('.gal-item').forEach(function (item) {
            item.onclick = function () { abrirVisor(self, Number(item.dataset.i)); };
        });
    };

    G.prototype.notificar = function () {
        if (this.opc.onCambio) this.opc.onCambio(this.medios.slice(), this.portada);
    };

    G.prototype.agregarACola = function (archivos) {
        var self = this;
        var lista = this.el.querySelector('.gal-cola');
        var descripcion = this.el.querySelector('.gal-descripcion').value;

        Array.prototype.forEach.call(archivos, function (archivo) {
            var li = document.createElement('li');
            li.innerHTML = '<div class="nombre"><span>' + esc(archivo.name) + '</span><span class="estado">' + mb(archivo.size) + '</span></div><div class="barra"><div></div></div>';
            lista.appendChild(li);

            var ext = archivo.name.split('.').pop().toLowerCase();
            if (EXT_PERMITIDAS.indexOf(ext) === -1) {
                self.marcarError(li, 'Formato no permitido');
                return;
            }
            if (self.opc.limiteBytes && archivo.size > self.opc.limiteBytes) {
                self.marcarError(li, 'Pesa ' + mb(archivo.size) + '; el máximo es ' + mb(self.opc.limiteBytes));
                return;
            }
            self.cola.push({ archivo: archivo, li: li, descripcion: descripcion });
        });

        this.procesarCola();
    };

    G.prototype.marcarError = function (li, mensaje) {
        li.classList.add('error');
        li.querySelector('.estado').textContent = '✖ ' + mensaje;
    };

    G.prototype.procesarCola = function () {
        var self = this;
        if (this.subiendo || !this.cola.length) return;
        this.subiendo = true;

        var tarea = this.cola.shift();
        var li = tarea.li;
        var barra = li.querySelector('.barra div');
        var estado = li.querySelector('.estado');

        var fd = new FormData();
        fd.append('accion', 'subir');
        fd.append('terreno_id', this.opc.terrenoId);
        fd.append('descripcion', tarea.descripcion);
        fd.append('archivo', tarea.archivo);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API);
        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round(e.loaded * 100 / e.total);
            barra.style.width = pct + '%';
            estado.textContent = pct < 100 ? pct + '%' : 'Procesando…';
        };
        xhr.onload = function () {
            var r = null;
            try { r = JSON.parse(xhr.responseText); } catch (e) { }
            if (r && r.ok) {
                li.classList.add('ok');
                barra.style.width = '100%';
                estado.textContent = '✔ Subido';
                self.medios.push(r.medio);
                self.portada = r.portada;
                self.pintar();
                self.notificar();
                setTimeout(function () { li.remove(); }, 2500);
            } else {
                self.marcarError(li, (r && r.error) || 'Error del servidor (' + xhr.status + ')');
            }
            self.terminarTarea();
        };
        xhr.onerror = function () {
            self.marcarError(li, 'Sin conexión con el servidor');
            self.terminarTarea();
        };
        xhr.send(fd);
    };

    G.prototype.terminarTarea = function () {
        this.subiendo = false;
        this.procesarCola();
    };

    G.prototype.eliminar = function (i) {
        var self = this;
        var m = this.medios[i];
        if (!m || !confirm('¿Eliminar ' + (m.tipo === 'video' ? 'este video' : 'esta foto') + '? No se puede deshacer.')) return;

        post({ accion: 'eliminar', medio_id: m.id }).then(function (r) {
            if (!r.ok) { alert(r.error); return; }
            self.medios.splice(i, 1);
            self.portada = r.portada;
            self.pintar();
            self.notificar();
            if (self.medios.length) {
                visorEstado.indice = Math.min(i, self.medios.length - 1);
                pintarVisor();
            } else {
                cerrarVisor();
            }
        }).catch(function () { alert('No se pudo conectar con el servidor.'); });
    };

    G.prototype.hacerPortada = function (i) {
        var self = this;
        var m = this.medios[i];
        post({ accion: 'portada', medio_id: m.id }).then(function (r) {
            if (!r.ok) { alert(r.error); return; }
            self.portada = r.portada;
            self.pintar();
            self.notificar();
            pintarVisor();
        }).catch(function () { alert('No se pudo conectar con el servidor.'); });
    };

    return {
        montar: function (el, opc) { return new G(el, opc); },
        cerrarVisor: cerrarVisor
    };
})();
